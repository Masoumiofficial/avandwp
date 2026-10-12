# AvandWP — Architecture

**Version:** 2.0.1 · **Database schema:** 1002 · **Document date:** 2026-10-12

This document describes how the plugin is put together *as it exists in this
repository*. Every claim below was read out of the source; nothing is aspirational.

---

## 1. Design constraints

Four constraints explain most of the structure:

1. **Shared hosting.** No daemon, no long-running process, no external queue.
   Anything slow is chunked into bounded requests that resume from a checkpoint.
2. **No autoloader and no Composer.** WordPress plugins ship as a ZIP that a
   customer uploads; a class that is used but never `require_once`d is a fatal
   error at callback time, so the load order is explicit and is verified by the
   packaging script.
3. **Evidence over polish.** A score, a cost, or a rollout percentage is either
   measured or reported as unavailable. Nothing is estimated silently.
4. **User data is never overwritten by automation.** Generation produces drafts;
   link insertion is approval-based and reversible; SEO metadata writes only
   through the detected plugin's own API or not at all.

---

## 2. File layout

```
avandwp.php                         Plugin header, constants, explicit require list
includes/
  Security/    capabilities, vault, rate limiter, outbound guard
  Core/        plugin bootstrap, activator, price catalog, text helper,
               job queue, lock, logger, health, spend guard
  Providers/   provider registry, AI client, image client
  Quality/     content analyzer, criteria registry, quality lab
  Content/     article brief, content studio, long-form generator
  Knowledge/   knowledge base
  Links/       link intelligence
  Audit/       site auditor
  Integrations/ Google auth, Search Console, Analytics 4
  WooCommerce/ product assistant
  Support/     chat assistant, support desk
  Automation/  content auditor, Telegram
  Reports/     reports/exports
  Admin/       admin menu, screens
templates/admin/  dashboard, studio, quality, integrations, woocommerce,
                  support, automation, settings, partials/header
assets/           admin.css, admin.js, chat-widget.*
tests/            bootstrap.php (WordPress shim + $wpdb double), lib/harness.php,
                  7 test files
tools/            lint.mjs, run-tests.mjs, build-dist.mjs, php.mjs
docs/             this document and its siblings
```

`avandwp.php` requires files in dependency order. `tools/build-dist.mjs`
independently re-reads that list and fails the build if any required file is
missing from the shipping set, so a released ZIP cannot contain a dangling
`require_once`.

Two ordering details are deliberate and easy to get wrong later:

* The activator is loaded early, but `AvandWP_Activator::default_settings()`
  returns constants from classes loaded later (`Article_Brief::DEFAULT_LENGTH`,
  `Site_Auditor::DEFAULT_BATCH`). That is safe because class constants are
  resolved when the expression runs, not when the file is included, and
  `default_settings()` only runs on activation — after the whole file list has
  been included. The unit suite calls it directly and asserts the result.
* `AvandWP_Spend_Guard` is required by `avandwp.php` and is **not** dead code:
  `AvandWP_Logger`, `AI_Client`, `Image_Client` and `Longform_Generator` all call
  into it, and `Logger::log()` is what records a completed request's cost.

---

## 3. Request and job execution model

Two execution paths exist, deliberately:

**Interactive (AJAX).** Read-only or fast operations: quality analysis, link
analysis, settings, single image generation, audit batch stepping. Each handler
calls `verify_ajax( $capability )` first, which checks the nonce **and**
`current_user_can()`. Object-level edits additionally go through
`AvandWP_Capabilities::user_can_edit_object( $post_id )`.

**Background (job queue).** Anything that can exceed a request budget:
long-form generation, WooCommerce batch optimization, knowledge indexing,
site audit. Jobs live in `{prefix}avandwp_jobs` and are executed by
`AvandWP_Job_Queue::process_batch()` on `avandwp_cron_process_jobs`.

```
enqueue(job_type, payload, args)
   │  args: max_attempts, dedupe_key, exclude_job_id
   ▼
pending ──(cron tick)──► running ──► completed
   ▲                        │
   └── retry (backoff 30·2^(n-1), cap 3600 s) ──► failed (at max_attempts)
```

Guarantees implemented in the queue:

| Concern | Mechanism |
|---|---|
| No duplicate work | `dedupe_key` + `find_active_by_dedupe_key()` |
| A job that re-queues itself is not blocked by its own key | `exclude_job_id` |
| A stalled worker does not hold a job forever | stale threshold 900 s |
| Progress is never invented | `report_progress( $id, $stage, $percent|null )`; `null` means indeterminate and the UI shows an indeterminate bar |
| Users can see state | Persian stage labels via `stage_label()`, job row exposed through `public_view()` |

The `site_audit` job is the clearest example: one cron tick crawls one batch,
reports real counts, and re-queues its own continuation with the same dedupe key
and `exclude_job_id => its own id`. Without that exclusion the continuation
resolved to the running job itself and the crawl silently stopped after one
batch — a defect found by reading the enqueue path, fixed, and now covered by a
test.

---

## 4. Data model

Tables (all `utf8mb4`), created by `AvandWP_Activator::install_schema()` and
upgraded by `maybe_upgrade()`:

| Table | Purpose |
|---|---|
| `avandwp_knowledge` | knowledge index (FULLTEXT + optional embeddings) |
| `avandwp_jobs` | background queue: status, stage, progress, progress_data, attempts, dedupe_key, next_attempt_at |
| `avandwp_chat_logs` | chat transcript |
| `avandwp_usage` | token/cost ledger: provider, model, tokens, operation, units, cost_usd (nullable), cost_source, cost_note |
| `avandwp_support_requests` / `_history` | ticket desk |
| `avandwp_chat_feedback` | ratings |
| `avandwp_activity_log` | audit trail |
| `avandwp_audit_runs` / `avandwp_audit_pages` | site audit (schema 1002) |

Options owned by the plugin: `avandwp_settings`, `avandwp_chat_settings`,
`avandwp_telegram_settings`, `avandwp_link_rules`, `avandwp_google_connection`,
`avandwp_vault_degraded`. Credentials never live in an option — they live in the
vault, AES-256-GCM encrypted.

`maybe_upgrade()` backfills rows written by the 1.0.0 baseline: unknown legacy
costs are relabelled `estimated` rather than being presented as measured.

---

## 5. AI layer

```
Provider_Registry (6 providers, capability → model catalogue, wire adapter)
        │
        ├── AI_Client      text: chat/completions, gemini, claude adapters
        └── Image_Client   images: generations / fal / stability
                 │
                 ▼
        Outbound_Guard  (URL validation, SSRF blocking, byte sniffing, size caps)
        Spend_Guard     (per-request and per-day caps)
        Logger          (usage ledger, activity log)
```

* `Provider_Registry::resolve_model()` refuses an identifier a provider does not
  publish. There is no global default model: an unknown id is an error, not a
  fallback, because the baseline's retired defaults (`gpt-4o-mini`, `dall-e-3`)
  produced billable failures.
* `AI_Client` retries only retryable classes (`rate_limit`, `server`, `network`)
  with 1/2/4 s delays and ≤40 % jitter; `auth`, `invalid_request` and `content`
  are returned immediately so the user sees the real cause.
* `Price_Catalog` maps `provider → model → rate`. A model with no verified rate
  returns `cost = null`, `source = 'unknown'` and an explanatory note.
* `Image_Client` sniffs returned bytes (`detect_image_mime()` → `image/png` …)
  and maps them through the guard's allow-list to a MIME and extension; non-image
  payloads are refused before anything reaches the Media Library.

---

## 6. Content pipeline (Module A)

`Longform_Generator` is a 16-stage, checkpointed pipeline:

```
validate_brief → load_context → plan_outline → allocate_budgets →
write_sections → assemble → measure_length → fill_gaps → plan_links →
insert_links → evaluate_quality → auto_correct → re_evaluate →
generate_media → build_seo_meta → persist_draft
```

* State is stored in the `avandwp_longform_state_{job_id}` transient (TTL 6 h);
  a resumed job skips stages already recorded in `state['done']`.
* `request_budget( $brief )` returns a hard ceiling of requests — 27 for the
  4,500-word default, 60 at the cap. The ceiling is displayed before the run
  starts, and the generator refuses to exceed it (`avandwp_request_budget_exhausted`).
* Length is counted by the plugin, not the model: `Text_Helper::measure()` counts
  words, characters with and without spaces, sentences, ZWNJ and reading minutes
  separately. `assess_length()` compares `achieved` against `target` and reports
  the shortfall or overage in Persian.
* Publication is opt-in: `Article_Brief::from_array()` returns
  `avandwp_brief_publish_unconfirmed` unless `confirm_publish` is set. Otherwise
  the result is a draft.
* Auto-correction is bounded to structural, reversible edits (e.g. an H1 inside
  the body becomes an H2, because the post title already renders as the H1) and
  keeps the original HTML plus an MD5 so a diff and rollback are possible.

---

## 7. Quality model (Module C)

`Content_Analyzer` extracts measured facts (counts, densities, structure, link
and image inventory, schema presence). `Criteria` holds 37 criteria across three
profiles — SEO 19 (weight 91), AIO 10 (62), GEO 8 (53) — each with an evaluator,
a weight, a severity and an `auto_fixable` flag. `Quality_Lab::evaluate()`
returns, per profile, the score, earned/possible weight, per-criterion evidence
and explanation, and the list of criteria that could not be evaluated.

Scoring rules that matter:

* A criterion that cannot be evaluated is **excluded from the denominator**, not
  failed. No keyword given ⇒ keyword criteria become "not evaluable".
* A profile with nothing evaluable scores `null`, never 0 or 100.
* 100 is downgraded to 99 whenever any criterion is only partially met, so
  "100" always means every applicable criterion passed outright.
* Every report carries `summary.disclaimer` stating that the score is an internal
  checklist and not a ranking or citation guarantee.

Full criteria table: [`seo-scoring-model.md`](seo-scoring-model.md).

---

## 8. Site audit (Module G)

`Site_Auditor` crawls only the site's own host, respects the site's own
`robots.txt` (wildcard group; named-crawler groups are deliberately ignored), and
runs in batches of 15 URLs by default (`DEFAULT_BATCH`), with a 0.4 s polite
delay and a 500 URL ceiling. 20 checks with a total weight of 123 produce a
0–100 internal score per page; `summarize()` aggregates pages, issue totals,
duplicate titles/descriptions and the score distribution.

Because the negative checks (no `noindex`, no redirect, canonical present, images
have alt text) pass by absence, an empty document floors at 25 rather than 0.
That number is a property of the checklist and is documented here so it is never
"fixed" into a fabricated zero.

Continuation is a cron job (`site_audit`), and the admin screen drives the same
`crawl_batch()` one batch per request with a server-computed percentage.

---

## 9. Admin surface

| Screen | Capability | Contents |
|---|---|---|
| داشبورد | `edit_posts` | status, recent activity, quick actions |
| استودیو محتوا و رسانه | `edit_posts` | article generator, long-form studio (tab 3), image studio, TTS |
| کیفیت و لینک‌سازی | `edit_posts` | three independent scores with evidence, link analysis and approved insertion, full criteria table |
| دستیار ووکامرس | `edit_posts` | product optimization |
| پشتیبانی و تیکت | `edit_posts` | ticket desk |
| ممیزی سایت و گوگل | `manage_options` | OAuth connect/disconnect, GSC and GA4 data, audit runs, previous runs |
| تنظیمات و سلامت | `manage_options` | vault, providers/models, Google credentials, advanced limits, health, job queue, usage ledger, exports |
| اتوماسیون | `manage_options` | Telegram, schedules |

Assets load only on screens whose hook contains `avandwp`. Every AJAX action used
by the JavaScript is registered in `class-plugin.php`'s `$ajax_actions` array —
a mismatch between the two is exactly the failure mode that produces a silent
dead button. `tools/lint.mjs` §4 asserts that contract in both directions and
fails the build on a violation: every `#id` the script selects must exist in a
template or be created by the script itself, every `data-tab-target` must resolve
to a rendered panel, every `postAjax()` action must appear in `$ajax_actions`, and
every `data-source=` button must have a `case` in the AJAX handler. What is still
missing is the *behavioural* half — that the wired button does the right thing —
which needs a browser and is listed as an unverified surface in
`testing-report.md`.

Two conventions in that surface are worth stating because they are easy to get
wrong later:

* **Remote values are never trusted just because they came from an API.** The
  Search Console property and the GA4 property are both re-validated server-side
  against the connected account's own list before being stored, and both lookups
  clear the cached reports of the previous selection so one property's numbers
  can never be shown under another's name.
* **A request from a button that has no target yet is resolved, not guessed.**
  The audit buttons send `run_id = 0`, which the server resolves to the newest
  run (or an explicit "no run yet" error) — see `resolve_audit_run_id()`.

RTL is the default: `dir="rtl"` on the wrapper, `dir="ltr"` on identifiers,
URLs and JSON.

---

## 10. Verification tooling

| Command | What it proves |
|---|---|
| `npm run lint` | every PHP file parses (PHP 8.2 parser), plus file-level policy checks (no `eval`, no shell exec, no trailing `?>`, raw-input discipline, source regression policies) and the UI wiring contract (146 ids, 32 AJAX actions, tab targets and `data-source` values) |
| `npm test` | 125 assertions across 7 test files in a real PHP 8.2 interpreter with a WordPress shim, a recording `$wpdb` double and a queued HTTP transport (`AvandWP_Test_HTTP`) |
| `npm run dist` | builds `build/avandwp-<version>.zip` after verifying the manifest, the shipping set and the absence of credentials/debug code; writes a SHA-256 manifest |
| `npm run verify` | lint + test |

Limits of this environment are stated in [`testing-report.md`](testing-report.md)
rather than hidden: no WordPress runtime, no network, no OpenSSL/mbstring in the
WASM interpreter, so integration and encryption paths are listed as unverified.
