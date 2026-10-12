/**
 * Shared helper: boot a PHP runtime inside Node (PHP compiled to WebAssembly).
 *
 * Why this exists: the AvandWP plugin must be verifiably free of PHP syntax
 * errors and its pure-logic units must be covered by executed tests. This
 * harness gives the repository a real PHP 8.2 interpreter without requiring a
 * system PHP installation, so `npm run verify` works on any machine.
 *
 * Limitations of the bundled runtime (documented in docs/testing-report.md):
 *   - `openssl` and `mbstring` extensions are NOT compiled into the WASM build.
 *     Code paths guarded by `function_exists()` therefore exercise their
 *     fallback branches here. AES-256-GCM round-trip tests are skipped.
 */

import { PhpNode } from 'php-wasm/PhpNode';

export const PHP_VERSION_TARGET = '8.2';

/**
 * Run a PHP source string and capture stdout/stderr.
 *
 * @param {string} code   PHP source including the opening `<?php` tag.
 * @param {object} [opts] Options.
 * @returns {Promise<{stdout: string, stderr: string}>}
 */
export async function runPhp(code, opts = {}) {
  const php = new PhpNode({ version: opts.version ?? PHP_VERSION_TARGET });

  let stdout = '';
  let stderr = '';

  php.addEventListener('output', (event) => {
    stdout += event.detail[0];
  });
  php.addEventListener('error', (event) => {
    stderr += event.detail[0];
  });

  // php-wasm routes PHP notices/warnings to the output stream; keep them.
  await php.run(code);

  return { stdout, stderr };
}
