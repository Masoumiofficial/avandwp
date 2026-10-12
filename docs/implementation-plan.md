# AvandWP — Implementation Plan (as executed)

**Version:** 2.0.1 · **Date:** 2026-10-12
**Baseline:** `reference/avandwp-v1.0.0-baseline.zip` (109,367 bytes, immutable)

This is not a roadmap written before the work: it is the plan as actually
executed, including where the mandated order was changed and why. The master
prompt's order was **audit → fix critical defects → implement in dependency order
→ test after each stage → package last**. That order was followed, with two
documented reorders (§4).

---

## Phase 0 — Audit (delivered)

| Deliverable | Output |
|---|---|
| Baseline inventory | 109,367-byte archive unpacked; file list, require order, DB schema, AJAX surface, provider layer mapped |
| `docs/technical-audit.md` | **F-01 … F-15**, each with file, line, evidence and severity |
| Defect severity ranking | 4 critical (security/reliability), 6 correctness, 5 honesty/UX |

Critical findings that shaped everything after:

* **F-01** every `$_POST` read was raw; no nonce/authority separation.
* **F-02** object-level authorisation missing (a Contributor could edit others' posts).
* **F-04** job lock released on `Exception` only — an `Error` left the job locked forever.
* **F-06** retired provider defaults (`gpt-4o-mini`, `dall-e-3`) that do not exist in GapGPT's catalogue.
* Plus: no spend cap, dead scaffolding in the auditor, `finalize_bytes()` MIME misuse, unreachable seeding in `default_settings()`, and a self-dedupe hang in the crawl continuation job.

---

## Phase 1 — Critical fixes (delivered, tested)

1. `verify_ajax()` = nonce **and** capability; `user_can_edit_object()` for post-scoped actions.
2. Job lock released in a `finally`-style path covering `Error` and `Throwable`.
3. Vault: AES-256-GCM with an explicit, non-silent degraded fallback.
4. `Outbound_Guard` as the single choke point for SSRF, redirects, size caps and MIME sniffing.
5. `Spend_Guard` + daily caps + per-IP rate limiting on the anonymous REST routes.
6. `Job_Queue` dedupe with `exclude_job_id` (the continuation job was deduplicating against itself).
7. `finalize_bytes()` rewritten against the real `detect_image_mime()` contract.

Every item above has an executable test except the ones needing WordPress
infrastructure, which are listed explicitly in `testing-report.md` §4.

---

## Phase 2 — Foundation (dependency order)

The order below is a dependency chain, not a preference: nothing after it can work
without the layer above.

```
Price_Catalog (verified model ids + rates)
   └─ Provider_Registry / adapters (text, image, transcribe, speech, embedding)
        └─ AI_Client (routing, retry classification, fallback, cost ledger)
             └─ Job_Queue (resumable, observable, retry-safe)
                  └─ Longform_Generator (16 stages, checkpoints)
                       └─ Content_Studio (assembly, links, images, SEO metadata)
                            └─ Quality_Lab (three profiles, evidence)
                                 └─ Auto_Correction (bounded, diff, rollback)
                                      └─ Site_Auditor (batched crawl)
                                           └─ Integrations (GSC OAuth, GA4, Telegram, SEO adapter)
                                                └─ Admin UI (RTL screens, honest progress)
```

Delivered in this order, with tests wired into `npm test` as each layer landed:
`class-price-catalog.php`, `class-provider-registry.php`, the six adapters,
`class-ai-client.php`, `class-job-queue.php`, `class-longform-generator.php`,
`class-content-studio.php`, `class-criteria.php`, `class-quality-lab.php`,
`class-auto-correction.php`, `class-site-auditor.php`, `class-google-auth.php`,
`class-search-console.php`, `class-analytics-data.php`, `class-seo-adapter.php`,
`class-link-intelligence.php`, `class-text-helper.php`, `class-content-analyzer.php`,
`class-article-brief.php`, and the admin screens.

---

## Phase 3 — Modules A–O, delivery status

Status vocabulary is fixed and used identically in `testing-report.md`:
**implemented+tested** · **implemented, not fully tested** · **partial** ·
**blocked by credentials** · **planned, not implemented**.

| Module | Deliverable | Status |
|---|---|---|
| A | Article Studio: 4,500-word default, distinct word/char/token counting, resumable multi-section generation, 16-step pipeline | implemented, not fully tested |
| B | Link intelligence: user-defined anchors, approval-based insertion, per-link revert | implemented+tested |
| C | Quality Lab: three independent 0–100 scores with per-criterion evidence | implemented+tested |
| D | Bounded auto-correction with diff and rollback | implemented, not fully tested |
| E | Image Studio: featured + inline, alt text, Media Library | implemented, not fully tested |
| F | GapGPT text + image adapter | implemented, not fully tested (no live key) |
| G | Site SEO crawler/audit, batched and resumable | implemented, not fully tested |
| H | Search Console OAuth | **blocked by credentials** |
| I | GA4 Data API | **blocked by credentials** |
| J | Rank Math / Yoast / AIOSEO / SEOPress compatibility | implemented+tested |
| K | RTL admin UX with honest progress | implemented, not fully tested |
| L | Security / privacy / governance | implemented+tested (residuals in `security-review.md` §9) |
| M | Shared-hosting performance | partial — no load test performed |
| N | Commercial positioning + comparison matrix | partial — written from public product pages |
| O | Testing & QA | implemented+tested |

Nothing in this table is claimed as working end to end on a live site; the
unverified surfaces are enumerated in `testing-report.md`.

---

## Phase 4 — Testing infrastructure (built alongside, not after)

The prompt said "test after each stage". That is only possible with a harness, so
the harness was built first inside Phase 1:

* `tests/bootstrap.php` — WordPress shim (options, transients, caps, posts,
  post meta, `wp_remote_*` recorder, `$wpdb` double with queue + query log).
* `tests/lib/harness.php` — assertions and a reporter.
* `tools/run-tests.mjs` — concatenates the 23 subject files and 7 test files into
  one program and runs it under `php-wasm` PHP 8.2.
* `tools/lint.mjs` — parses all 57 PHP files and enforces 9 source policies.
* `npm run verify` — lint + tests, the single gate.

Result: **125 tests, 0 failures**, run after every change.

---

## Phase 5 — Documentation (delivered)

| File | Contents |
|---|---|
| `docs/technical-audit.md` | F-01…F-15 with evidence |
| `docs/architecture.md` | constraints, layout, AJAX vs job paths, data model, AI layer, pipeline, quality model, admin capability table, tooling limits |
| `docs/security-review.md` | auth, input, credentials, SSRF, governance, residual risk register |
| `docs/api-integrations.md` | verified endpoints, parameters, OAuth flow, blocked states |
| `docs/seo-scoring-model.md` | the 37 criteria, scoring arithmetic, what the scores do not claim |
| `docs/compatibility.md` | WordPress/PHP/hosting, SEO plugins, themes, providers |
| `docs/testing-report.md` | reproduction commands, per-module status, deliberately unverified surfaces |
| `CHANGELOG.md` | 1.0.0 → 2.0.1 |
| `docs/COMMERCIAL-LAUNCH-KIT.md` | positioning, pricing, comparison matrix |

---

## Phase 6 — Packaging (last)

`tools/build-dist.mjs` runs lint and tests first and **refuses to build if either
fails**, then:

1. collects the shipping file list (PHP, assets, templates, readme, LICENSE);
2. verifies that every `require_once` target exists and that every template path
   resolves;
3. scans every included file for credential shapes and private-key blocks;
4. writes a byte-reproducible ZIP (fixed DOS timestamp 2026-01-01, stored, one root
   folder) plus `.manifest.json` with the file list, sizes and SHA-256.

Excluded from the ZIP by construction: `node_modules/`, `tests/`, `tools/`,
`reference/`, `build/`, `.git`, editor/OS junk, debug logs and any credential
file.

One document is shipped deliberately: `docs/USER-GUIDE-FA.md`, because a customer
who installs the plugin cannot read this repository. Everything else under
`docs/` (technical audit, security review, implementation plan, testing report,
launch kit) is an engineering record and stays in the repository. The build script
declares that exception as a list, and fails loudly if a listed document is
missing rather than silently shipping a package without it.

---

## § 4 — Documented reorders and scope decisions

1. **The test harness was built in Phase 1, not Phase 4.** The prompt's order
   ("test after each stage") is impossible without a runner, and the PHP-WASM
   sandbox has no filesystem, so tests had to be behavioural from the start.
2. **The lint policies were added after the code, not before**, and the
   `ajax_input_sanitised` policy was reformulated once. The first version demanded
   that every handler read input only through the typed helpers; the codebase's
   established inline-sanitisation idiom is legitimate, so the policy was rewritten
   to enforce the stronger property that actually matters: no unannounced raw
   input. This is recorded rather than hidden because the policy governs future
   edits.
3. **PHP 7.4 verification was dropped, not skipped.** No 7.4 interpreter is
   available in this environment and none can be installed. Instead of raising the
   header to a version that *was* tested (which would silently drop users) the
   header stays at 7.4, the absence of PHP 8.0-only constructs is enforced
   statically by `php_version_honesty`, and the limitation is stated in the
   compatibility matrix and the testing report.
4. **No billing/licensing code was written.** Only integration points.
