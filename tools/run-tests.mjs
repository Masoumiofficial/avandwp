/**
 * AvandWP test runner.
 *
 * Assembles the WordPress shim, the pure-logic plugin classes and the test
 * files into one PHP program, then executes it in a real PHP 8.2 interpreter
 * (WebAssembly). Exit code 1 when any assertion fails.
 *
 * Which files are loaded is declared explicitly and deliberately: only classes
 * that do not require a database connection, an HTTP stack or a WordPress hook
 * system are unit-testable in this environment. Everything else is listed as an
 * explicit gap in docs/testing-report.md rather than being silently skipped.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runPhp } from './php.mjs';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

/** Strip the opening PHP tag so files can be concatenated. */
function phpBody(path) {
  const source = readFileSync(join(ROOT, path), 'utf8');
  return source.replace(/^\s*<\?php\s*/, '');
}

/**
 * Classes under test, in dependency order.
 *
 * The exclusion is deliberate and documented: `class-plugin.php`,
 * `class-admin-menu.php` and the template files are NOT loaded because they
 * require a live database, a real HTTP stack and the WordPress hook system.
 * They are listed as untested surfaces in docs/testing-report.md rather than
 * being silently skipped.
 *
 * `class-job-queue.php`, `class-activator.php`, `class-longform-generator.php`
 * and `class-image-client.php` are loaded: their declaration and their
 * pure-logic helpers are executable here. Their database-touching methods are
 * exercised only through the paths the tests call.
 */
const SUBJECT_FILES = [
  'includes/Security/class-capabilities.php',
  'includes/Security/class-vault.php',
  'includes/Security/class-outbound-guard.php',
  'includes/Core/class-job-queue.php',
  'includes/Core/class-logger.php',
  'includes/Core/class-activator.php',
  'includes/Core/class-text-helper.php',
  'includes/Core/class-price-catalog.php',
  'includes/Core/class-spend-guard.php',
  'includes/Quality/class-content-analyzer.php',
  'includes/Quality/class-criteria.php',
  'includes/Quality/class-quality-lab.php',
  'includes/Providers/class-provider-registry.php',
  'includes/Providers/class-ai-client.php',
  'includes/Content/class-article-brief.php',
  'includes/Links/class-link-intelligence.php',
  'includes/Audit/class-site-auditor.php',
  'includes/Compat/class-seo-adapter.php',
  'includes/Integrations/class-google-auth.php',
  'includes/Integrations/class-search-console.php',
  'includes/Integrations/class-analytics-data.php',
  'includes/Content/class-longform-generator.php',
  'includes/Providers/class-image-client.php',
];

const TEST_FILES = [
  'tests/test-length.php',
  'tests/test-quality-lab.php',
  'tests/test-security-and-cost.php',
  'tests/test-article-brief.php',
  'tests/test-site-audit-and-links.php',
  'tests/test-providers-and-integrations.php',
  'tests/test-longform-and-integrations.php',
];

const parts = [
  phpBody('tests/bootstrap.php'),
  phpBody('tests/lib/harness.php'),
  ...SUBJECT_FILES.map(phpBody),
  ...TEST_FILES.map(phpBody),
  `
echo "SUITE BOOTSTRAP OK\\n";
exit( AvandWP_Test_Harness::run() );
`,
];

const program =
  '<?php ' +
  // Tests that guard against a regression by reading a source file need a
  // stable path; the sandbox has no WordPress, so it is injected here.
  `define('AVANDWP_PATH', ${JSON.stringify(join(ROOT, '') + '/')});` +
  '\n' +
  parts.join('\n');

const { stdout, stderr } = await runPhp(program, { version: '8.2' });

process.stdout.write(stdout);

if (stderr.trim()) {
  process.stderr.write('\nPHP stderr:\n' + stderr + '\n');
}

// Parse the machine-readable summary line for the CI-style exit code.
const match = stdout.match(/__AVANDWP_TEST_SUMMARY__ (\{.*\})/);
if (!match) {
  console.error('\nFAILED: the suite produced no summary line (fatal error?).');
  process.exit(1);
}

let summary;
try {
  summary = JSON.parse(match[1]);
} catch (err) {
  console.error('\nFAILED: could not parse the suite summary.');
  process.exit(1);
}

if (summary.failed > 0) {
  process.exit(1);
}
