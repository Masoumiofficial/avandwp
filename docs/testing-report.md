# AvandWP — Testing Report

**Version under test:** 2.0.0 · **Date:** 2026-10-12
**Toolchain:** Node 22 · `php-wasm` PHP **8.2.11** · no Composer, no PHPUnit, no WordPress runtime, no network

---

## 1. How to reproduce every number in this report

```bash
npm install          # dev-only: installs php-wasm (not shipped)
npm run lint         # static verification
npm test             # unit suite
npm run verify       # lint + test
npm run dist         # verify manifest, then build the installable ZIP
```

Recorded results of the last full run:

| Command | Result | Detail |
|---|---|---|
| `node tools/lint.mjs` | **PASS** | 57 PHP files parsed, 0 syntax failures, 0 policy failures, 1 advisory; UI wiring: 146 element ids, 32 AJAX actions, 140 rendered ids checked |
| `node tools/run-tests.mjs` | **PASS** | **125 passed / 0 failed**, PHP 8.2.11, ~0.82 s |
| `node tools/build-dist.mjs` | **PASS** | 55 files packaged, 896,552 bytes, 37 requires resolved, 8 templates resolved, SHA-256 `d0e02703598e91882226e1f3286325e094de194c8c6580d17a1b646536a2f5b6` (rebuilding twice produces the same bytes) |

A claim in this document is only "tested" if the command above was executed and
its result recorded here. Everything else is marked accordingly.

---

Static verification covers a class of defect the PHP suite cannot see: the
JavaScript ↔ template ↔ PHP-handler contract. `tools/lint.mjs` §4 fails the build
if admin.js selects an id no template renders and the script never creates, if a
`data-tab-target` points at a panel that does not exist, if a `postAjax()` call
names an action missing from `$ajax_actions`, or if a `data-source` button has no
`case` in the AJAX handler. Each of those produces a button that silently does
nothing — the failure mode that costs the most time to diagnose in production. The
check was verified by deliberately breaking all three (a mistyped id, a mistyped
tab target, a mistyped action name) and confirming the build fails, then restoring
the files.

## 2. What the unit suite actually covers

7 test files, run inside one PHP 8.2 interpreter against a WordPress shim
(`tests/bootstrap.php`) and a recording `$wpdb` double.

| File | Focus |
|---|---|
| `test-length.php` | Persian length counting: words, characters with/without spaces, ZWNJ, reading time, `assess_length()` shortfall/overage wording |
| `test-quality-lab.php` | criterion registry integrity, weights, profile totals, score arithmetic, partial credit, `null` scores, exclusion of non-evaluable criteria, disclaimer presence |
| `test-security-and-cost.php` | capability mapping, object-edit authority, vault encryption path and its no-OpenSSL fallback, SSRF/URL validation, size caps, price catalog arithmetic, spend guard |
| `test-article-brief.php` | brief validation, defaults, length bounds, list/URL normalisation, publish confirmation, prompt block |
| `test-site-audit-and-links.php` | auditor checklist/weights/profiles, 100-point page, missing title+H1, cross-domain canonical, empty document floor, thin content, "no ranking-guarantee wording" guard; the newest-run lookup behind «ادامه بررسی» (newest-first, `LIMIT 1`, clamped to 50, empty history returns an empty list); link extraction, internal detection, single-occurrence replacement, and every documented refusal |
| `test-providers-and-integrations.php` | provider definitions and wire adapters, unknown provider/model errors, retired-model guard, auth-header conventions, failure classification, cost integrity, SEO adapter write behaviour, Google readiness/status/scopes/redirect URI, GA4 validation, "no fabricated data" assertion; Search Console property-list parsing and permission mapping from a canned API payload, the HTTP request that actually goes out (URL, bearer header, JSON body, dropped undocumented dimensions), property expiry and host matching, and the truncation flag with summed clicks/impressions/CTR/position |
| `test-longform-and-integrations.php` | 16-stage pipeline order, request budget ceiling and publicity, defaults (4,500 words / 15,300 tokens), publish confirmation, `report_progress()` null-percent semantics and clamping, job dedupe continuation (`exclude_job_id`), image MIME/extension derivation and rejection paths, image cost honesty, settings defaults purity and merge behaviour, provider catalogue coverage |

### Defects found by executing tests (not by reading)

1. `Article_Brief::from_array()` read `$raw['post_status']` without a default in
   its `in_array()` guard → PHP 8 undefined-key warning and an empty destination
   value. Fixed; covered.
2. `Quality_Lab` scoring of an empty document: the negative checks pass by
   absence, so the floor is **25**, not 0 — the test expectation was wrong and
   was corrected to assert the presence checks fail (`< 30`), not `0`.
3. `plugin_outputs_faq_schema()` returns **false** when no SEO plugin is active
   (the plugin emits its own FAQ graph only when nothing else does).
4. `AvandWP_Logger` was absent from the unit load order and failed at the first
   enqueue; it is now part of the tested set.
5. `Longform_Generator::request_budget()` read `$brief['length_mode']` and
   `$brief['length_include_spaces']` without defaults, so the admin screen's
   partial-brief call produced PHP warnings.
6. The `site_audit` continuation job was deduplicated against **itself**
   (`status = 'running'` with the same `dedupe_key`), so a cron-driven crawl
   silently stopped after one batch. Fixed with `exclude_job_id`; covered.
7. `Image_Client::finalize_bytes()` treated `Outbound_Guard::detect_image_mime()`
   as an array, but that method returns a MIME **string**. Every generated image
   would have been saved with a broken MIME type and extension. Fixed; covered
   by calling the real normaliser with real PNG bytes.
8. `Activator::default_settings()` returned before its chat/Telegram seeding
   blocks, making them unreachable on a fresh install (no chat or Telegram
   defaults were ever written) and mixing writes into a getter. Seeding moved to
   `seed_feature_options()`; a test now asserts the getter writes nothing and
   the activation path writes all three option rows.

---

## 3. Status per module

Taxonomy: **implemented+tested** · **implemented, not fully tested** ·
**partial** · **blocked by credentials** · **planned, not implemented**.

| Module | Status | Evidence / limitation |
|---|---|---|
| A — Article Studio (4,500-word default, distinct counting, resumable multi-section generation, 16-stage pipeline) | implemented, not fully tested | pipeline order, budget, length maths, defaults and publish confirmation are unit-tested. The model calls themselves need a live API key and a WordPress runtime — not executed. |
| B — Link intelligence (owner-defined anchors, approval-based insertion) | implemented+tested | extraction, internal detection, single-occurrence replacement and all documented refusals are tested. The multi-link `apply_links` orchestration needs a `$wpdb`-backed post and is untested. |
| C — Quality Lab (three independent 0–100 scores with evidence) | implemented+tested | registry integrity, arithmetic, partial credit, `null` scores, exclusion rules and disclaimer are tested. |
| D — Bounded auto-correction with diff and rollback | implemented, not fully tested | the correction policy and original-HTML retention are in code; the diff/rollback flow runs inside the job pipeline, which needs a live runtime. |
| E — Image Studio (featured + inline, alt text, Media Library) | implemented, not fully tested | MIME/extension derivation, refusal paths and cost reporting are tested; the actual upload path needs WordPress' upload stack. |
| F — GapGPT text + image provider | implemented, not fully tested | base URL, alternate URL, auth headers, model catalogue and price rates are encoded from the provider's published documentation (`class-price-catalog.php`, `Verified 2026-10-12`). No live request was made — no API key in this environment. |
| G — Site SEO crawler/audit | implemented, not fully tested | checklist, weights, scoring, robots handling and summary are tested; the crawler needs HTTP and a database. |
| H — Search Console OAuth | blocked by credentials | endpoints, scopes, state check (`hash_equals`), token exchange and revocation are implemented and unit-tested for readiness/status, and the report parsing is now covered against canned API payloads. No Google OAuth client exists in this environment, so authorisation cannot be exercised. |
| I — GA4 Data API | blocked by credentials | request/response shapes, metric allow-list, property validation, property-list parsing and `readiness_report()` are implemented and tested for the blocked path. No live property. |
| J — SEO plugin compatibility | implemented+tested | adapter detection and "write nothing when unsupported" behaviour are tested. Writing through a real Rank Math/Yoast install was not executed. |
| K — RTL admin UX with honest progress | implemented, not fully tested | server-side percentage computation and indeterminate progress are implemented; the ids, tab targets, AJAX actions and `data-source` values are asserted statically by `npm run lint`; actual rendering and interaction were not executed in a browser. |
| L — Security / privacy / governance | implemented+tested (partly) | capability checks, object authority, SSRF guards, size caps, vault behaviour (both branches), uninstall/retention settings and the raw-input policies are tested or statically enforced. See `security-review.md` for the residual list. |
| M — Performance on shared hosting | partial | batching, polite delays, bounded budgets and resumable checkpoints are implemented; **no load test was run** and none is claimed. |
| N — Commercial positioning | partial | `COMMERCIAL-LAUNCH-KIT.md` plus the feature matrix in this repo summary. The comparison against Rank Math/Yoast/Surfer/Frase is written from their public product pages, not from hands-on trials — treat it as positioning copy, not a benchmark. |
| O — Testing & QA | implemented+tested | this report. |

---

## 4. Deliberately unverified surfaces

These are **not** covered, and the omission is stated rather than hidden.

| Surface | Why it cannot run here | Residual risk |
|---|---|---|
| `class-plugin.php` (AJAX and REST handlers, ~1,700 lines) | requires WordPress hooks, a database and HTTP | a handler-level defect (wrong field name between JS and PHP, wrong capability) would only appear in a live install. Mitigated by lint, the `post_*` input policy and the registered-action list; **not** proven. |
| `class-admin-menu.php` and all templates | require the WordPress admin runtime | markup/DOM defects and hook-name typos are not caught. |
| Vault AES-256-GCM round trip | `openssl` is not compiled into the WASM PHP build | the encryption path is executed in the fallback branch only. A live install with OpenSSL must be used to confirm the encrypted branch. |
| Persian text operations requiring `mbstring` | `mbstring` is not compiled in | the suite asserts the byte-safe fallback; the mbstring branch is unexercised. |
| Every network call (six providers, Google APIs, crawler, Telegram) | no outbound network from the test sandbox and no credentials | request construction is asserted from the source; real responses are not. |
| Cron scheduling, `dbDelta` migrations, uninstall | require WordPress | schema version bumps and activation behaviour are asserted by reading, not executing. |
| Browser behaviour (tabs, AJAX wiring, polling, RTL layout) | no browser in this environment | JavaScript is syntax-checked (`node --check`) and reviewed; it is not exercised. |
| PHP 7.4 runtime | the interpreter matrix available here starts at PHP 8.0 and 7.4 is not installable | the header claims `Requires PHP: 7.4`; a lint policy forbids PHP 8.0-only constructs in shipped source, which is a static argument, not a run. |

---

## 5. Environment limitations that shape the suite

* The PHP-WASM sandbox has **no filesystem access**, so tests assert behaviour and
  never read source files. Source-level regression checks live in
  `tools/lint.mjs`, which does have filesystem access.
* The WordPress shim is a shim, not an emulator. A passing test proves the logic
  under test; it does not prove the plugin works inside WordPress.
* `number_format_i18n()` is shimmed with ASCII `number_format()`, so assertions
  use `'4,500'`, never Persian digits.
* The `$wpdb` double records calls and returns queued values. It does not parse
  SQL: control-flow tests queue the row a real database would have returned, and
  query-shape tests inspect the recorded statement.
* HTTP is stubbed, not simulated. `AvandWP_Test_HTTP::queue_json()` hands the
  next `wp_remote_*` call a canned response, and a call with an empty queue
  returns a `WP_Error`. A passing test therefore proves the plugin parses a
  **known** payload and that nothing else on that path silently succeeds — it
  never proves a live endpoint behaves the same way.

---

## 6. What must be verified on a real installation before release

Ordered by risk, these are the checks that this environment cannot perform:

1. Activate on WordPress 6.x with PHP 8.1+: confirm tables, options, cron events
   and that no fatal error occurs at activation or on any admin screen.
2. With a real GapGPT key: generate one 4,500-word article end to end and confirm
   word count, cost ledger row, draft status and Media Library attachment.
3. With a Google Cloud OAuth client: complete the connection and read one GSC and
   one GA4 report; confirm the blocked state before connecting shows no numbers.
4. Run a site audit of 100+ URLs on shared hosting and confirm each batch returns
   within the PHP time limit and the run resumes after a timeout.
5. Insert an internal link on a real post and revert it; confirm the post content
   is byte-identical to before after the revert.
6. Open every admin screen at a narrow viewport in an RTL browser and confirm no
   layout breakage.
