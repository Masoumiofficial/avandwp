/**
 * AvandWP distribution builder.
 *
 * Produces `build/avandwp-<version>.zip`: the exact set of files that ship to a
 * customer's site. The rules encoded here are the packaging contract from the
 * project brief:
 *
 *   - no development tooling (tools/, tests/, package.json, node_modules/)
 *   - no repository artefacts (docs/, reference/, .git*, build/), with one
 *     exception: the buyer-facing user guide ships, because a customer who
 *     installs the plugin has no access to this repository
 *   - no credentials, local overrides, debug logs or editor backups
 *   - the archive must be installable as-is through WordPress' plugin uploader,
 *     which requires the plugin folder and main file to sit at the archive root
 *
 * The ZIP is written by a tiny store-only writer instead of a dependency: the
 * plugin ships nothing, so the build must not need anything either. Stored
 * (uncompressed) entries are valid ZIP; WordPress' unzip handles them and the
 * resulting archive is only marginally larger than a deflated one for PHP
 * sources.
 *
 * Run with `npm run dist`.
 */

import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, mkdirSync, rmSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const BUILD_DIR = join(ROOT, 'build');

/** Files and directories that never ship. */
const EXCLUDED_TOP_LEVEL = new Set([
  '.git',
  '.github',
  '.gitignore',
  'build',
  'docs',
  'node_modules',
  'package.json',
  'package-lock.json',
  'reference',
  'tests',
  'tools',
]);

/** Any file whose name matches one of these is excluded wherever it lives. */
const EXCLUDED_PATTERNS = [
  /(^|\/)\.DS_Store$/,
  /(^|\/)Thumbs\.db$/,
  /(^|\/)\.env(\.|$)/,
  /\.log$/i,
  /\.orig$/,
  /\.rej$/,
  /\.bak$/,
  /~$/,
  /\.zip$/i,
  /\.tar(\.gz)?$/i,
  /(^|\/)avandwp-v[\d.]+-baseline\.zip$/,
  /(^|\/)phpcs\.xml(\.dist)?$/,
  /(^|\/)composer\.(json|lock)$/,
];

/**
 * Repository documents that are part of the product and therefore ship.
 *
 * Everything else under `docs/` is an engineering record (technical audit,
 * security review, implementation plan, testing report, launch kit) and stays in
 * the repository. The user guide is shipped because it is written for the person
 * using the plugin, not for the person maintaining it.
 */
const SHIPPED_DOCS = ['docs/USER-GUIDE-FA.md'];

/** Content patterns that must never appear in a shipped file. */
const FORBIDDEN_CONTENT = [
  { re: /AKIA[0-9A-Z]{16}/, note: 'AWS access key id' },
  { re: /sk-[A-Za-z0-9]{20,}/, note: 'OpenAI-style secret key' },
  { re: /-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/, note: 'private key material' },
  { re: /AIza[0-9A-Za-z\-_]{35}/, note: 'Google API key' },
  { re: /\bghp_[A-Za-z0-9]{36}\b/, note: 'GitHub personal access token' },
  { re: /xox[baprs]-[A-Za-z0-9-]{10,}/, note: 'Slack token' },
  { re: /\b\d{9,10}:AA[A-Za-z0-9_-]{33}\b/, note: 'Telegram bot token' },
  { re: /var_dump\s*\(|print_r\s*\(\s*\$/ , note: 'debug dump left in source' },
];

// ---------------------------------------------------------------------------
// Collection
// ---------------------------------------------------------------------------

/** Detect the plugin version from the main file header. */
function readVersion() {
  const main = readFileSync(join(ROOT, 'avandwp.php'), 'utf8');
  const header = /^\s*\*\s*Version:\s*(.+)$/m.exec(main);
  const constant = /define\(\s*'AVANDWP_VERSION'\s*,\s*'([^']+)'\s*\)/.exec(main);

  if (!header) {
    throw new Error('avandwp.php has no "Version:" header line');
  }
  if (!constant) {
    throw new Error("avandwp.php does not define AVANDWP_VERSION");
  }

  const version = header[1].trim();
  const declared = constant[1].trim();

  // The two must agree, otherwise the WordPress update checker compares against
  // a version the plugin itself does not believe it is.
  if (version !== declared) {
    throw new Error(`version mismatch: header "${version}" vs AVANDWP_VERSION "${declared}"`);
  }

  return version;
}

/** Recursively collect shippable files, as paths relative to the repo root. */
function collectFiles(dir = ROOT, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const abs = join(dir, entry.name);
    const rel = relative(ROOT, abs).split(sep).join('/');

    if (dir === ROOT && EXCLUDED_TOP_LEVEL.has(entry.name)) {
      continue;
    }
    if (EXCLUDED_PATTERNS.some((re) => re.test(rel))) {
      continue;
    }

    if (entry.isDirectory()) {
      collectFiles(abs, out);
    } else if (entry.isFile()) {
      out.push(rel);
    }
  }

  return out;
}

// ---------------------------------------------------------------------------
// Verification
// ---------------------------------------------------------------------------

const problems = [];

function verifyContent(files) {
  for (const rel of files) {
    const absol = join(ROOT, rel);
    const size = statSync(absol).size;

    if (size > 2 * 1024 * 1024) {
      problems.push(`${rel}: ${(size / 1024 / 1024).toFixed(2)} MB is unexpectedly large for a plugin source file`);
    }

    if (!/\.(php|js|css|json|txt|md|pot|po|mo)$/i.test(rel)) {
      problems.push(`${rel}: unexpected file type in the shipping set`);
    }

    if (!/\.(php|js|css)$/i.test(rel)) {
      continue;
    }

    const source = readFileSync(absol, 'utf8');

    for (const rule of FORBIDDEN_CONTENT) {
      if (rule.re.test(source)) {
        problems.push(`${rel}: ${rule.note} found in shipped source`);
      }
    }
  }
}

function verifyManifest(files) {
  const mainFile = 'avandwp.php';

  if (!files.includes(mainFile)) {
    problems.push(`${mainFile} is missing from the shipping set — the ZIP would not be installable`);
  }

  // Every require_once in the main file must resolve to a shipped file.
  const main = readFileSync(join(ROOT, mainFile), 'utf8');
  const requires = [...main.matchAll(/require_once\s+AVANDWP_PATH\s*\.\s*'([^']+)'/g)].map((m) => m[1]);

  for (const path of requires) {
    if (!files.includes(path)) {
      problems.push(`avandwp.php requires "${path}" but it is not in the shipping set`);
    }
  }

  // Templates referenced by the admin menu must exist too.
  const menu = readFileSync(join(ROOT, 'includes/Admin/class-admin-menu.php'), 'utf8');
  const templates = [...menu.matchAll(/AVANDWP_PATH\s*\.\s*'(templates\/[^']+)'/g)].map((m) => m[1]);

  for (const path of templates) {
    if (!files.includes(path)) {
      problems.push(`class-admin-menu.php includes "${path}" but it is not in the shipping set`);
    }
  }

  return { requires: requires.length, templates: templates.length };
}

// ---------------------------------------------------------------------------
// ZIP writer (store-only)
// ---------------------------------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Int32Array(256);

  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    table[i] = c;
  }

  return table;
})();

function crc32(buffer) {
  let crc = -1;

  for (let i = 0; i < buffer.length; i++) {
    crc = (crc >>> 8) ^ CRC_TABLE[(crc ^ buffer[i]) & 0xff];
  }

  return (crc ^ -1) >>> 0;
}

/**
 * Build a ZIP archive from `{ name, data }` entries.
 *
 * Dates are fixed so the archive is reproducible: building twice from the same
 * source produces byte-identical output, which is what makes the published
 * checksum meaningful.
 */
function createZip(entries) {
  const DOS_TIME = 0;
  const DOS_DATE = (2026 - 1980) * 512 + 1 * 32 + 1; // 2026-01-01

  const chunks = [];
  const central = [];
  let offset = 0;

  for (const entry of entries) {
    const nameBuf = Buffer.from(entry.name, 'utf8');
    const data = entry.data;
    const crc = crc32(data);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0); // local file header
    local.writeUInt16LE(20, 4); // version needed
    local.writeUInt16LE(0x0800, 6); // UTF-8 name flag
    local.writeUInt16LE(0, 8); // store
    local.writeUInt16LE(DOS_TIME, 10);
    local.writeUInt16LE(DOS_DATE, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);

    chunks.push(local, nameBuf, data);

    const cd = Buffer.alloc(46);
    cd.writeUInt32LE(0x02014b50, 0); // central directory header
    cd.writeUInt16LE(20, 4); // version made by
    cd.writeUInt16LE(20, 6); // version needed
    cd.writeUInt16LE(0x0800, 8);
    cd.writeUInt16LE(0, 10);
    cd.writeUInt16LE(DOS_TIME, 12);
    cd.writeUInt16LE(DOS_DATE, 14);
    cd.writeUInt32LE(crc, 16);
    cd.writeUInt32LE(data.length, 20);
    cd.writeUInt32LE(data.length, 24);
    cd.writeUInt16LE(nameBuf.length, 28);
    cd.writeUInt16LE(0, 30);
    cd.writeUInt16LE(0, 32);
    cd.writeUInt16LE(0, 34);
    cd.writeUInt16LE(0, 36);
    cd.writeUInt32LE(0, 38);
    cd.writeUInt32LE(offset, 42);

    central.push(cd, nameBuf);

    offset += local.length + nameBuf.length + data.length;
  }

  const centralBuf = Buffer.concat(central);
  const end = Buffer.alloc(22);

  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(centralBuf.length, 12);
  end.writeUInt32LE(offset, 16);
  end.writeUInt16LE(0, 20);

  return Buffer.concat([...chunks, centralBuf, end]);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

const version = readVersion();

// Shipping set = everything collected, plus the product documents that live
// under an otherwise excluded directory. `existsSync` is checked explicitly so a
// renamed or deleted guide fails loudly instead of silently disappearing from
// the package.
const files = collectFiles().sort();

for (const doc of SHIPPED_DOCS) {
  if (!existsSync(join(ROOT, doc))) {
    problems.push(`${doc} is listed as a shipped document but does not exist`);
    continue;
  }
  if (!files.includes(doc)) {
    files.push(doc);
  }
}

files.sort();

verifyContent(files);
const manifest = verifyManifest(files);

if (problems.length > 0) {
  console.error('AvandWP distribution build FAILED');
  console.error('='.repeat(64));
  for (const problem of problems) {
    console.error(`  ✗ ${problem}`);
  }
  console.error(`\n${problems.length} packaging problem(s). Nothing was written.`);
  process.exit(1);
}

const folder = 'avandwp';
const entries = files.map((rel) => ({
  name: `${folder}/${rel}`,
  data: readFileSync(join(ROOT, rel)),
}));

const zip = createZip(entries);

rmSync(BUILD_DIR, { recursive: true, force: true });
mkdirSync(BUILD_DIR, { recursive: true });

const zipPath = join(BUILD_DIR, `avandwp-${version}.zip`);
writeFileSync(zipPath, zip);

const checksum = createHash('sha256').update(zip).digest('hex');

// A build manifest next to the archive: it is what makes "verified" checkable
// later rather than a claim in a chat message.
const manifestPath = join(BUILD_DIR, `avandwp-${version}.manifest.json`);
writeFileSync(
  manifestPath,
  JSON.stringify(
    {
      plugin: 'avandwp',
      version,
      built_at: new Date().toISOString(),
      entry_count: entries.length,
      uncompressed_bytes: entries.reduce((sum, e) => sum + e.data.length, 0),
      archive_bytes: zip.length,
      sha256: checksum,
      requires_checked: manifest.requires,
      templates_checked: manifest.templates,
      files: files,
    },
    null,
    2
  ) + '\n'
);

console.log('AvandWP distribution build');
console.log('='.repeat(64));
console.log(`Version:              ${version}`);
console.log(`Files packaged:       ${files.length}`);
console.log(`Requires resolved:    ${manifest.requires}`);
console.log(`Templates resolved:   ${manifest.templates}`);
console.log(`Archive:              ${relative(ROOT, zipPath).split(sep).join('/')} (${(zip.length / 1024).toFixed(1)} KB)`);
console.log(`Manifest:             ${relative(ROOT, manifestPath).split(sep).join('/')}`);
console.log(`SHA-256:              ${checksum}`);
console.log('\nRESULT: PASS — archive is installable as-is through the WordPress plugin uploader.');
