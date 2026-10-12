/**
 * AvandWP static verification: PHP syntax lint + focused policy scan.
 *
 * Usage: npm run lint
 *
 * Exit code 1 on any syntax error or policy violation.
 *
 * The syntax check is authoritative: it compiles every PHP file with a real
 * PHP 8.2 parser (`token_get_all(..., TOKEN_PARSE)` throws `ParseError` on
 * invalid syntax). PHP 8.2 parsing is a superset of the plugin's declared
 * minimum (PHP 7.4), so PHP 8.2 parse failures are real defects; a small number
 * of PHP 7.4-only constructs would not be caught and are noted in the report.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runPhp } from './php.mjs';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

const SKIP_DIRS = new Set(['node_modules', '.git', 'reference', 'build', 'vendor', '.arena']);

/** Recursively collect files matching an extension. */
function collect(dir, ext, out = []) {
  for (const entry of readdirSync(dir)) {
    if (SKIP_DIRS.has(entry)) continue;
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) {
      collect(full, ext, out);
    } else if (entry.endsWith(ext)) {
      out.push(full);
    }
  }
  return out;
}

const phpFiles = collect(ROOT, '.php').sort();

if (phpFiles.length === 0) {
  console.error('No PHP files found.');
  process.exit(1);
}

// ---------------------------------------------------------------------------
// 1. Syntax parse (authoritative)
// ---------------------------------------------------------------------------

const payload = {};
for (const f of phpFiles) {
  payload[relative(ROOT, f).split(sep).join('/')] = readFileSync(f).toString('base64');
}

const lintScript = `<?php
$files = json_decode(base64_decode('${Buffer.from(JSON.stringify(payload)).toString('base64')}'), true);
$fail = 0;
foreach ($files as $path => $b64) {
    $src = base64_decode($b64, true);
    if (false === $src) { echo "DECODE_FAIL\\t{$path}\\n"; $fail++; continue; }
    // A UTF-8 BOM before <?php produces visible whitespace in output.
    if (strncmp($src, "\\xEF\\xBB\\xBF", 3) === 0) { echo "BOM\\t{$path}\\n"; $fail++; }
    try {
        @token_get_all($src, TOKEN_PARSE);
    } catch (ParseError $e) {
        $msg = str_replace(array("\\n", "\\r", "\\t"), ' ', $e->getMessage());
        echo "SYNTAX\\t{$path}\\t{$msg}\\n";
        $fail++;
    } catch (Throwable $e) {
        $msg = str_replace(array("\\n", "\\r", "\\t"), ' ', $e->getMessage());
        echo "TOKENIZE\\t{$path}\\t{$msg}\\n";
        $fail++;
    }
}
echo "COUNT\\t" . count($files) . "\\n";
echo "FAIL\\t{$fail}\\n";
`;

const { stdout, stderr } = await runPhp(lintScript);

const lines = stdout.split('\n').filter(Boolean);
const problems = [];
let fileCount = 0;
let syntaxFailures = 0;

for (const line of lines) {
  const [kind, ...rest] = line.split('\t');
  if (kind === 'COUNT') fileCount = Number(rest[0]);
  else if (kind === 'FAIL') syntaxFailures = Number(rest[0]);
  else problems.push({ kind, detail: rest.join(' ').trim() });
}

// ---------------------------------------------------------------------------
// 2. Policy scan (high-signal patterns only; advisory findings are printed
//    separately and do not fail the build unless listed in FAILING_POLICIES)
// ---------------------------------------------------------------------------

const FAILING_POLICIES = [
  { id: 'eval', re: /\beval\s*\(/, note: 'eval() is prohibited.' },
  { id: 'create_function', re: /\bcreate_function\s*\(/, note: 'create_function() removed in PHP 8.' },
  { id: 'extract', re: /\bextract\s*\(/, note: 'extract() creates untracked variables.' },
  { id: 'unserialize', re: /(?<![\w>])unserialize\s*\(/, note: 'Use JSON or allowed_classes to avoid object injection.' },
  { id: 'shell_exec', re: /\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(/, note: 'Shell execution prohibited.' },
  { id: 'php_short_open', re: /<\?(?!php|=|xml)/, note: 'Short open tag is not portable.' },
  { id: 'closing_tag_eof', re: /\?>\s*$/, note: 'Trailing "?>" risks whitespace-after-header bugs.' },
  { id: 'md5_random', re: /md5\s*\(\s*mt_rand|rand\s*\(\s*\)\s*\.\s*md5/i, note: 'Non-cryptographic randomness for security values.' },
];

const advisory = [];

for (const f of phpFiles) {
  const src = readFileSync(f, 'utf8');
  const rel = relative(ROOT, f).split(sep).join('/');

  for (const policy of FAILING_POLICIES) {
    if (policy.id === 'closing_tag_eof') {
      // Only the very last non-whitespace token matters.
      const tail = src.replace(/\s+$/, '');
      if (tail.endsWith('?>')) {
        problems.push({ kind: 'POLICY:' + policy.id, detail: `${rel} — ${policy.note}` });
      }
      continue;
    }
    if (policy.re.test(src)) {
      // Allow explicit phpcs-ignore acknowledgements to downgrade to advisory.
      const ignored = new RegExp(`phpcs:ignore[^\\n]*${policy.id}`, 'i').test(src);
      const bucket = ignored ? advisory : problems;
      bucket.push({ kind: 'POLICY:' + policy.id, detail: `${rel} — ${policy.note}` });
    }
  }

  // Advisory: raw superglobal access without an obvious sanitiser on the line.
  src.split('\n').forEach((line, i) => {
    if (/\$_(POST|GET|REQUEST|FILES)\s*\[/.test(line)) {
      const sanitised = /(sanitize_|absint|intval|\(int\)|\(float\)|wp_unslash|esc_|wp_kses|check_ajax|current_user_can|isset\s*\()/.test(line);
      if (!sanitised && !/phpcs:ignore/.test(line)) {
        advisory.push({ kind: 'RAW_INPUT', detail: `${rel}:${i + 1} — review sanitisation` });
      }
    }
  });
}

// ---------------------------------------------------------------------------
// 3. Source policies: regressions this project has already paid for
//
//    These are checks the PHP unit suite cannot make, because that suite runs
//    without a WordPress runtime and (inside the WebAssembly sandbox) without
//    filesystem access. They are source-level assertions about files or
//    constructs that must not come back.
// ---------------------------------------------------------------------------

const read = (rel) => readFileSync(join(ROOT, rel), 'utf8');

/**
 * Drop comment lines from a source file.
 *
 * Policies that look for a forbidden identifier must not trip on the comment
 * that documents why that identifier was removed.
 */
const readCode = (rel) =>
  read(rel)
    .split('\n')
    .filter((line) => {
      const t = line.trim();
      return !(t.startsWith('//') || t.startsWith('*') || t.startsWith('/*') || t.startsWith('#'));
    })
    .join('\n');

/** @type {Array<{id: string, test: () => boolean, note: string}>} */
const SOURCE_POLICIES = [
  {
    id: 'retired_model_defaults',
    test: () => {
      const tpl = readCode('templates/admin/settings.php');
      const bad = ['dall-e-3', 'dall-e-2', 'gpt-4o-mini', 'claude-3-5-haiku', 'gemini-1.5-flash'];
      return !bad.some((id) => tpl.includes(id));
    },
    note: 'templates/admin/settings.php must not name a model the provider catalogue does not publish.',
  },
  {
    id: 'settings_from_activator',
    test: () => {
      const tpl = read('templates/admin/settings.php');
      return tpl.includes('AvandWP_Activator::default_settings()');
    },
    note: 'templates/admin/settings.php must read fallbacks from the activator instead of a second hardcoded copy.',
  },
  {
    id: 'settings_registry_driven',
    test: () => {
      const tpl = read('templates/admin/settings.php');
      return tpl.includes('AvandWP_Provider_Registry::for_capability(') &&
        tpl.includes('AvandWP_Price_Catalog::describe_text_model(');
    },
    note: 'provider/model selects must be generated from the registry with verified price labels.',
  },
  {
    id: 'image_mime_contract',
    test: () => {
      const client = read('includes/Providers/class-image-client.php');
      return !client.includes('$detected[\'mime\']') && !client.includes('$detected[\'ext\']');
    },
    note: 'Outbound_Guard::detect_image_mime() returns a MIME string; Image_Client must not index it as an array.',
  },
  {
    id: 'job_continuation_dedupe',
    test: () => {
      const queue = read('includes/Core/class-job-queue.php');
      return queue.includes("'exclude_job_id'") && queue.includes('id != %d');
    },
    note: 'a job that re-queues its own continuation must be able to exclude itself from deduplication.',
  },
  {
    id: 'ajax_input_sanitised',
    test: () => {
      // Two conventions are enforced here:
      //   1. every direct $_POST read carries an inline sanitiser, a numeric
      //      cast, or an explicit phpcs:ignore acknowledgement;
      //   2. the typed post_* helpers exist, because the newer handlers
      //      (long-form, quality, links, audit, Google) read input through them
      //      so that unslashing and sanitising happen in exactly one place.
      const plugin = readCode('includes/Core/class-plugin.php');

      for (const helper of ['post_text', 'post_textarea', 'post_key', 'post_int', 'post_flag', 'post_raw', 'post_array', 'post_float']) {
        if (!plugin.includes(`function ${helper}(`)) {
          console.log(`    missing helper: ${helper}()`);
          return false;
        }
      }

      const offenders = plugin.split('\n').filter((line) => {
        if (!/\$_POST\s*\[/.test(line)) return false;
        if (/phpcs:ignore/.test(line)) return false;
        // The typed helpers themselves read the generic `$key` slot.
        if (/\$_POST\[ \$key \]/.test(line)) return false;
        if (/(sanitize_|absint|intval|\(int\)|\(float\)|wp_unslash|wp_kses|esc_url_raw)/.test(line)) return false;
        return true;
      });

      if (offenders.length > 0) {
        console.log('    unsanitised reads: ' + offenders.map((l) => l.trim().slice(0, 70)).join(' | '));
      }

      return offenders.length === 0;
    },
    note: 'every AJAX input read must be sanitised inline or explicitly acknowledged, and the typed post_* helpers must exist.',
  },
  {
    id: 'no_secret_in_js',
    test: () => {
      const admin = read('assets/js/admin.js');
      return !/api[_-]?key\s*[:=]\s*['"][^'"]{12,}/i.test(admin);
    },
    note: 'no credential may be embedded in shipped JavaScript.',
  },
  {
    id: 'score_disclaimer_present',
    test: () => {
      const lab = read('includes/Quality/class-quality-lab.php');
      return lab.includes("'disclaimer'") && lab.includes('تضمین');
    },
    note: 'the quality report must carry its own disclaimer; a score is never presented as a ranking guarantee.',
  },
  {
    id: 'php_version_honesty',
    test: () => {
      const main = read('avandwp.php');
      const declared = /Requires PHP:\s*([\d.]+)/.exec(main);
      if (!declared) return false;

      const minimum = declared[1];
      const numeric = parseFloat(minimum);
      if (numeric >= 8.0) return true;

      // PHP 8.0-only constructs must not appear while the header claims 7.4.
      const php8Only = [
        /\?->/,                     // nullsafe operator
        /(?<![\w$])match\s*\(/,     // match expression
        /catch\s*\(\s*[A-Za-z\\]+\s*\)/, // catch without a variable
        /\b(str_contains|str_starts_with|str_ends_with)\s*\(/,
      ];
      const targets = phpFiles.filter((f) => !relative(ROOT, f).startsWith('tests/'));
      const hits = [];
      targets.forEach((f) => {
        const rel = relative(ROOT, f).split(sep).join('/');
        readCode(rel).split('\n').forEach((line, i) => {
          if (php8Only.some((re) => re.test(line))) hits.push(`${rel}:${i + 1}`);
        });
      });
      if (hits.length > 0) {
        console.log('    php8-only constructs: ' + hits.slice(0, 5).join(', '));
      }
      return hits.length === 0;
    },
    note: 'a "Requires PHP: 7.4" header is only valid while no PHP 8.0-only construct is present.',
  },
];

for (const policy of SOURCE_POLICIES) {
  let ok = false;
  try {
    ok = policy.test();
  } catch (error) {
    problems.push({ kind: 'SOURCE:' + policy.id, detail: `check failed to run: ${error.message}` });
    continue;
  }
  if (!ok) {
    problems.push({ kind: 'SOURCE:' + policy.id, detail: policy.note });
  }
}


// ---------------------------------------------------------------------------
// 4. UI wiring: the JavaScript <-> template <-> PHP-handler contract
//
//    A renamed id, an action that was never registered, or a data-source the
//    handler does not know all produce the same symptom: a button that does
//    nothing, with no error anywhere. That failure mode cannot be caught by the
//    PHP suite (no DOM) or by the browser-free build, so it is asserted here,
//    statically, in both directions.
// ---------------------------------------------------------------------------

// `collect()` returns absolute paths; the UI contract is expressed in
// repository-relative ones.
const templateFiles = collect(ROOT, '.php')
  .map((abs) => relative(ROOT, abs).split(sep).join('/'))
  .filter((rel) => rel.startsWith('templates/'))
  .sort();

const jsSource = read('assets/js/admin.js');
const pluginSource = read('includes/Core/class-plugin.php');

const templateSource = templateFiles.map((rel) => read(rel)).join('\n');

/** Every id rendered by a template. */
const renderedIds = new Set(
  [...templateSource.matchAll(/\bid="([^"$<]+)"/g)].map((m) => m[1])
);

/** Every id the JavaScript itself injects into the DOM as a string literal. */
const jsCreatedIds = new Set([...jsSource.matchAll(/id="([^"$<]+)"/g)].map((m) => m[1]));

/** Ids a selector in admin.js refers to, skipping the dynamic `'#' + target` form. */
const selectedIds = new Set(
  [...jsSource.matchAll(/\$\(\s*'#([A-Za-z0-9_-]+)'/g)].map((m) => m[1])
);
const delegatedIds = new Set(
  [...jsSource.matchAll(/\.on\(\s*'[a-z]+'\s*,\s*'#([A-Za-z0-9_-]+)'/g)].map((m) => m[1])
);

const knownIds = new Set([...renderedIds, ...jsCreatedIds]);

if (knownIds.size === 0) {
  problems.push({
    kind: 'UI:id_scan_failed',
    detail: 'no element ids were found in the admin templates or the admin script',
  });
}

for (const id of [...selectedIds, ...delegatedIds].sort()) {
  if (!knownIds.has(id)) {
    problems.push({
      kind: 'UI:dead_selector',
      detail: `admin.js selects #${id} but no template renders it and the script never creates it`,
    });
  }
}

// Tab buttons navigate by `data-tab-target`; a typo there hides every panel.
for (const match of templateSource.matchAll(/data-tab-target="([^"]+)"/g)) {
  if (!renderedIds.has(match[1])) {
    problems.push({
      kind: 'UI:dead_tab',
      detail: `a tab button targets #${match[1]} but no template renders that id`,
    });
  }
}

// AJAX actions used by the script must exist in the plugin's own allow-list:
// wp_ajax_avandwp_<action> is registered from that array and nowhere else.
//
// Only that one array is parsed, so an unrelated list elsewhere in the file
// cannot widen the allowed set.
const actionsBlock = (() => {
  const start = pluginSource.indexOf('$ajax_actions = array(');

  if (start === -1) {
    return '';
  }

  const end = pluginSource.indexOf(');', start);

  return end === -1 ? '' : pluginSource.slice(start, end);
})();

const registeredActions = new Set(
  [...actionsBlock.matchAll(/'([^']+)'/g)].map((m) => m[1])
);

// Any quoted action name counts, including a mistyped one: the point of the
// check is to catch names that were never registered.
const usedActions = new Set(
  [...jsSource.matchAll(/postAjax\(\s*'([^']+)'/g)].map((m) => m[1])
);

if (registeredActions.size < 20 || usedActions.size < 20) {
  // A parser that quietly matches nothing would make this whole section a
  // no-op that still reports PASS.
  problems.push({
    kind: 'UI:action_scan_failed',
    detail: `expected to find the action array and the call sites, found ${registeredActions.size} registered and ${usedActions.size} used`,
  });
}

for (const action of [...usedActions].sort()) {
  if (!registeredActions.has(action)) {
    problems.push({
      kind: 'UI:unregistered_action',
      detail: `admin.js calls the "${action}" action but it is not in $ajax_actions`,
    });
  }
}

// `data-source` buttons are dispatched by a switch in ajax_google_data(); a
// value the server does not handle answers with a generic error at click time.
const handledSources = new Set(
  [...pluginSource.matchAll(/case '([a-z0-9_]+)':/g)].map((m) => m[1])
);

for (const match of templateSource.matchAll(/data-source="([^"]+)"/g)) {
  if (!handledSources.has(match[1])) {
    problems.push({
      kind: 'UI:unhandled_source',
      detail: `a data-source="${match[1]}" button has no matching case in the AJAX handler`,
    });
  }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

console.log('AvandWP static verification');
console.log('='.repeat(60));
console.log(`PHP files parsed: ${fileCount}`);
console.log(`Syntax/parse failures: ${syntaxFailures}`);
console.log(
  `UI wiring: ${knownIds.size} ids, ${usedActions.size} ajax actions, ${renderedIds.size} rendered ids checked`
);

if (problems.length) {
  console.log('\nFAILURES');
  for (const p of problems) console.log(`  [${p.kind}] ${p.detail}`);
}

if (advisory.length) {
  console.log(`\nADVISORY (${advisory.length}, non-blocking, reviewed manually)`);
  for (const p of advisory.slice(0, 25)) console.log(`  [${p.kind}] ${p.detail}`);
  if (advisory.length > 25) console.log(`  ... and ${advisory.length - 25} more`);
}

if (stderr.trim()) {
  console.log('\nRuntime stderr:');
  console.log(stderr.trim().split('\n').map((l) => '  ' + l).join('\n'));
}

const failed = syntaxFailures > 0 || problems.length > 0;
console.log('\nRESULT: ' + (failed ? 'FAIL' : 'PASS'));
process.exit(failed ? 1 : 0);
