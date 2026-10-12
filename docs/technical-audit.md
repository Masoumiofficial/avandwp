# AvandWP — Technical Audit (Phase 0 Baseline)

**Plugin:** آوند (AvandWP)
**Baseline version audited:** `1.0.0` (`avandwp-v1.0.0.zip`, 109,367 bytes, `reference/avandwp-v1.0.0-baseline.zip`)
**Audit date:** 2026-10-12
**Auditor:** engineering review performed against the actual source archive, not against documentation claims.
**Method:** full source extraction, line-by-line inspection of all 29 PHP files, 2 CSS files and 2 JS files, machine-checked PHP 8.2 parse verification of every file, plus verification of external API documentation.

---

## 1. How this audit was produced

| Step | What was actually executed | Result |
|---|---|---|
| Archive extraction | `unzip avandwp-v1.0.0.zip` | 56 entries, 37 files, 311,522 bytes uncompressed |
| Repository inspection | `git log`, `find`, file census | Repository contained **only** the ZIP; no prior source tree, no history, no tests, no CI |
| Source census | full read of `includes/`, `templates/`, `assets/` | 29 PHP / 2 CSS / 2 JS files; 12 classes, no namespaces, `AvandWP_*` global class prefix |
| Machine parse check | `npm run lint` — every PHP file parsed with a real PHP 8.2 parser (`token_get_all` + `TOKEN_PARSE`) | **29/29 files parse cleanly**, 0 syntax errors, 0 BOM files, 0 trailing `?>` |
| Security pattern scan | automated policy scan (`eval`, `extract`, `unserialize`, shell exec, raw superglobals) | 0 blocking violations; 16 advisory raw-input sites, all manually reviewed as false positives (sanitisation on an adjacent line or inside `verify_ajax`) |
| External API verification | fetched the official GapGPT documentation | Base URL, auth header, chat + image endpoints and the **current model catalogue** verified (see `docs/api-integrations.md`) |
| Runtime execution | PHP 8.2 via WebAssembly harness (`tools/`) | Used for the parse check and for the unit test suite; **no live WordPress, database or API key was available** in this environment |

### What this audit could NOT do

These limitations are real and are not worked around with assumptions:

- **No live WordPress instance.** No integration test, no browser test, no `dbDelta` execution, no real Media Library import was run. Behaviour is established by reading code, not by observing a running site.
- **No API credentials.** No GapGPT, OpenAI, Google Search Console or Analytics request was ever issued. Every claim about provider behaviour in this document is sourced from provider documentation and is labelled as such.
- **No `openssl` or `mbstring`** in the WASM runtime. AES-256-GCM round-trip and Persian `mb_*` text paths could not be executed here; they are covered by guarded/fallback fallbacks in the code and by static review only.
- **No PHPCS / PHPStan / WPCS.** Composer is unavailable in this environment, so no third-party static analyser or WordPress coding-standard run exists. Do not treat the parse check as a substitute.

---

## 2. Architecture inventory

### 2.1 Bootstrap and lifecycle

| Responsibility | Implementation | Notes |
|---|---|---|
| Plugin header | `avandwp.php` | `Version: 1.0.0`, `Requires at least: 6.0`, `Requires PHP: 7.4`, GPLv2+ |
| Constants | `AVANDWP_VERSION`, `AVANDWP_DB_VERSION` (`1000`), `AVANDWP_PATH`, `AVANDWP_URL`, `AVANDWP_BASENAME` | Conventional and correct |
| Autoloading | **None** — 20 explicit `require_once` calls | Manual list is a maintenance hazard (a new file is invisible until required) but is deterministic and fast |
| Boot | `add_action('plugins_loaded', 'avandwp')` → `AvandWP_Plugin::instance()` | Singleton; constructor runs `maybe_upgrade_db()` then `init_modules()` |
| Activation | `AvandWP_Activator::activate()` | `create_tables()`, `init_default_options()`, `schedule_crons()` |
| Deactivation | `AvandWP_Deactivator::deactivate()` | Unschedule loop is safe against stale timestamps |
| Uninstall | `uninstall.php` | Always unschedules cron; deletes 8 tables + 8 options **only** when `delete_data_on_uninstall` is enabled — correct and non-destructive by default |

### 2.2 Modules

| Module | File | Responsibility | State |
|---|---|---|---|
| Main controller | `Core/class-plugin.php` (942 lines) | REST routes, 20 AJAX handlers, cron, FAQ JSON-LD, XLSX export | Working, contains the audit's most serious findings |
| Admin menu | `Admin/class-admin-menu.php` | 6 submenu pages, asset enqueue, capability split | Working |
| Text helper | `Core/class-text-helper.php` | Persian word count, normalisation, tokenisation, AI HTML cleanup, JSON parsing, token estimation | Working; `str_word_count()` defect resolved |
| Vault | `Security/class-vault.php` | AES-256-GCM credential storage | Works; silent plaintext-base64 downgrade (see F-07) |
| Rate limiter | `Security/class-rate-limiter.php` | Fixed-window IP limiter on 4 public REST routes | Working; DB write per request (see F-12) |
| Logger | `Core/class-logger.php` | Activity log + usage ledger + cost estimation | Working; cost model is stale/incorrect (see F-08) |
| Job queue | `Core/class-job-queue.php` | 3 job types, transient lock, retry counter | Working with defects (F-03, F-04, F-05) |
| Health | `Core/class-health.php` | 8-table check, OpenSSL/cron/WooCommerce/ZipArchive report | Working; reports the plaintext fallback as merely "warning" |
| AI client | `Providers/class-ai-client.php` | OpenAI/GapGPT chat, Gemini, Claude, Whisper, TTS, embeddings | Working; fabricated-fallback and truncation blind spots (F-06, F-09) |
| Image client | `Providers/class-image-client.php` | GapGPT/OpenAI DALL·E, FAL Flux, Stability SD3 → Media Library | Working; unverified model id and fabricated usage (F-02, F-09) |
| Knowledge base | `Knowledge/class-knowledge-base.php` | Lexical + optional embedding search over posts/pages/products/FAQ | Working; unbounded scan (F-11) |
| Content studio | `Content/class-content-studio.php` | One-shot article → post + Yoast/Rank Math meta + FAQ + image + TTS | Working; **cannot reach the required 4,500-word default** (F-01) |
| Woo assistant | `WooCommerce/class-woo-assistant.php` | Product scan / optimise / draft creation | Working; missing authority checks (F-03) |
| Support desk | `Support/class-support-desk.php` | Tickets, history, feedback | Working; all queries prepared |
| Chat assistant | `Support/class-chat-assistant.php` | Grounded chat over KB, source citations | Working; correctly rejects client-supplied `system` roles |
| Telegram | `Automation/class-telegram.php` | Publish posts, order/ticket notifications, HTTPS proxy | Working; SSRF guard present |
| Content auditor | `Automation/class-content-auditor.php` | Non-destructive audit + AI SEO meta generation | Working; missing authority checks (F-03) |
| Reports | `Reports/class-reports.php` | OpenXML `.xlsx` stream + RTL print view | Working; capability + nonce checked |

### 2.3 Database (8 custom tables)

`avandwp_knowledge`, `avandwp_jobs`, `avandwp_chat_logs`, `avandwp_usage`, `avandwp_support_requests`, `avandwp_support_history`, `avandwp_chat_feedback`, `avandwp_activity_log` — all created with `dbDelta()` and `$wpdb->get_charset_collate()`, all with primary keys and supporting indexes.

**Verdict:** schema is competent. `avandwp_usage` has **no `is_estimate` column**, so provider-reported and locally-estimated cost cannot be distinguished after the fact (F-08). `avandwp_jobs` has no `locked_at`/`started_at` column, so abandoned jobs cannot be detected by time (F-04).

### 2.4 REST API

Four public routes under `/wp-json/avandwp/v1/`: `POST /chat`, `POST /support`, `POST /support/audio`, `POST /feedback`. All use `permission_callback => '__return_true'` — **intentional** (the frontend widget must work for logged-out visitors) and each handler applies an IP rate-limit; there is no nonce and no CAPTCHA. See F-10 for the cost-abuse consequence.

### 2.5 Frontend

`assets/css/admin.css` (design tokens, RTL, 8 px spacing scale), `assets/css/chat-widget.css`, `assets/js/admin.js` (jQuery, 18 KB), `assets/js/chat-widget.js` (vanilla, 11 KB). Assets are enqueued **only** on `*avandwp*` admin screens — correct. Dynamic strings in `chat-widget.js` are wrapped in a local `escHtml()`; the widget is not rendered inside `innerHTML` unescaped. Bot replies are escaped before insertion — **no DOM XSS found**.

### 2.6 Compatibility today

- Declares WordPress ≥ 6.0, PHP ≥ 7.4.
- Uses `mb_substr()` unguarded in one place (`class-image-client.php`, `save_to_media_library()`) while guarding it everywhere else → potential fatal on hosts without `mbstring` (F-13).
- No SEO-plugin conflict handling: writes Yoast **and** Rank Math meta keys simultaneously and always (F-14).
- Emits its own `FAQPage` JSON-LD on `wp_head` for posts, pages and products without checking whether the active SEO plugin already emits one (F-14).

---

## 3. Confirmed defects

Severity is assigned by **impact on a live commercial site**, not by how hard the fix is.

### F-01 — Article length cannot reach the required default · **CRITICAL (product requirement)**

- **Location:** `includes/Content/class-content-studio.php:29`, `:82`; `includes/Core/class-plugin.php:466`; `templates/admin/studio.php:60-63`
- **Evidence:**
  - `$target_words = max( 300, min( 3000, (int) ( $params['target_words'] ?? 1000 ) ) );` → hard ceiling of 3,000 words.
  - `'max_tokens' => 3500` hardcoded for the single generation request.
  - Admin UI offers only `600 / 1000 / 1600` word presets; default is `1000`.
  - Settings clamp `max_tokens` to `max(200, min(4096, ...))`.
- **Impact:** the mandated default of **4,500 words is unreachable**. Worse, a requested 3,000-word Persian article (~4,500–6,000 output tokens) is **silently truncated** at 3,500 tokens, and the response parser has no `finish_reason` check, so a truncated article is presented to the user as a complete one. The `word_count` returned is measured from the real HTML (good), but no shortfall against the target is ever reported.
- **Reproduction:** request `target_words = 3000`; observe the returned `word_count` plateau and the absence of any warning.
- **Fix (implemented):** see `docs/architecture.md` §Long-form generation — outline → sectioned generation → consolidation, per-request token budget derived from the target, provider `finish_reason` inspection, measured word/character count, explicit shortfall reporting, and background continuation.

### F-02 — Unverified image model identifier sent to GapGPT · **HIGH**

- **Location:** `includes/Providers/class-image-client.php` (`call_dalle()`, `'model' => 'dall-e-3'`)
- **Evidence:** the official GapGPT model catalogue (verified 2026-10-12, `docs/api-integrations.md`) lists image models `gpt-image-2`, `gpt-image-1`, `gpt-image-1-mini`, `gemini-3-pro-image-preview`, `gemini-2.5-flash-image`, `flux-1-schnell`, `gapgpt/qwen-image-2.1`. **`dall-e-3` does not appear.** The same call sends `response_format: b64_json`, while GapGPT's documentation states image responses return a **URL** (`data[0].url`), not raw file bytes.
- **Impact:** the shipped default image path either fails outright or relies on an undocumented fallback, and the model the customer paid for is not selectable.
- **Fix (implemented):** provider adapters now take an explicit, configurable model id defaulting to a **verified** catalogue entry, accept both `b64_json` and URL responses, and reject unknown model ids rather than inventing them.

### F-03 — Missing per-object authority checks (privilege escalation) · **HIGH (security)**

- **Location:** `includes/Core/class-plugin.php` — `ajax_fix_post_seo()` (gated only by `edit_posts`, passes an arbitrary attacker-controlled `post_id` straight into `AvandWP_Content_Auditor::generate_seo_meta_for_post()`, which performs `update_post_meta()` on it); `ajax_woo_optimize()` (arbitrary `product_id` → `wp_update_post()`); `ajax_woo_create_draft()`; `ajax_update_ticket()`; `ajax_save_faqs()` and `ajax_sync_knowledge()` (`TRUNCATE TABLE` on the knowledge index); `ajax_publish_post_telegram()` (publishes to the site's Telegram channel).
- **Evidence:** all of the above call `$this->verify_ajax( 'edit_posts' )` and none of them check `current_user_can( 'edit_post', $post_id )`, post ownership, post type, or `manage_options` for the site-wide operations.
- **Impact:** on a multi-author site any **Author/Contributor** account (a routine, low-trust role) can overwrite the SEO title and meta description of *any* post, including the front page; rewrite arbitrary WooCommerce product descriptions; wipe the knowledge index; and post to the site's public Telegram channel. This is a genuine privilege escalation, not a theoretical one.
- **Fix (implemented):** every object-scoped handler now verifies `edit_post`/`edit_products` against the specific object; site-wide/destructive actions moved to `manage_options`.

### F-04 — Job queue: lock not released safely, and `Exception` is not `Throwable` · **MEDIUM**

- **Location:** `includes/Core/class-job-queue.php:84`, `:88`, `:122`, `:204`
- **Evidence:**
  1. `process_batch( $limit, $specific_job_id )` skips the *acquire* check when a specific job id is given, but **always** executes `delete_transient( self::LOCK_KEY )` at the end. A `poll_job` AJAX call therefore **releases a lock held by a concurrently running cron batch**, permitting two batches to execute the same job simultaneously — i.e. duplicate paid AI calls and duplicate posts.
  2. `try { ... } catch ( Exception $e )` — a PHP `Error`/`TypeError` (very reachable: a `WP_Error` passed where a string is expected, a null property) is **not** caught, so the fatal leaves the row stuck in `status = 'running'` forever, and `process_batch()` only ever selects `status = 'pending'`, so the job is never retried or surfaced.
  3. `mark_failed()` returns the job to `pending` with no delay, so the 3 retries fire back-to-back with no backoff.
- **Impact:** duplicate charges, duplicate drafts, permanently stuck jobs, no recovery path for the user.
- **Fix (implemented):** token-owned lock (a process may only release a lock it acquired), `catch ( Throwable )`, stale-`running` reaper, and exponential backoff via `next_attempt_at`.

### F-05 — Job progress is fabricated · **MEDIUM (honesty requirement)**

- **Location:** `includes/Core/class-job-queue.php` (`execute_job()` writes `progress => 20` on start and `progress => 100` on completion)
- **Impact:** the admin UI shows a fixed "20 %" for the entire duration of a multi-minute article generation. This is a fabricated progress indicator.
- **Fix (implemented):** progress is now derived from real completion of discrete, reported stages (outline → section *n* of *N* → consolidation → audit), and the UI shows an honest indeterminate state where a percentage cannot be measured.

### F-06 — Provider fallback fires on non-retryable errors and does not bound cost · **MEDIUM**

- **Location:** `includes/Providers/class-ai-client.php::chat()`
- **Evidence:** the available-provider list is built unconditionally and every provider is attempted for **any** failure — including `401 invalid key`, `400 malformed request` and content-policy rejections — so a single user action can issue up to four billed requests. There is no retry, no backoff and no jitter for genuinely transient failures (`429`, `5xx`, timeouts).
- **Impact:** unpredictable spend, misleading error surface (the user is told about the *last* error, not the first), and no resilience for transient failures.
- **Fix (implemented):** errors are classified (auth / rate-limit / transient / content / invalid-request); only transient and rate-limit failures retry, with bounded exponential backoff + jitter; auth and content failures stop immediately; each request carries a correlation id that appears in the log.

### F-07 — Vault silently downgrades to unencrypted storage · **MEDIUM (security)**

- **Location:** `includes/Security/class-vault.php::encrypt()` — falls back to `return 'b64:' . base64_encode( $plaintext );`
- **Impact:** on a host without OpenSSL, API keys and the **Telegram bot token** are stored in the `wp_options` table as base64 — i.e. effectively in plaintext, recoverable from any database or backup dump. The health screen labels this merely "غیرفعال (Fallback)" and the plugin keeps saving new keys without complaint.
- **Fix (implemented):** encryption failures are now recorded and surfaced prominently as a security warning with an explicit, admin-visible decision; base64-fallback payloads are tagged so they can be identified and rotated; the health report distinguishes "encrypted" from "degraded".

### F-08 — Cost figures are wrong and cannot be distinguished from real charges · **MEDIUM**

- **Location:** `includes/Core/class-logger.php::estimate_cost_usd()`; `avandwp_usage` schema
- **Evidence:** the rate table hardcodes three OpenAI price points and defaults **everything else** to `$0.15 / $0.60 per 1M` — so GapGPT's own models (`gapgpt-gemma-26b-a4b`, `gapgpt-qwen-3.6`, `gpt-6-luna`, `claude-sonnet-5`, …) are all mispriced, and unknown models are silently assigned a plausible-looking number. The `cost_usd` column has no companion flag, so an estimate is indistinguishable from a provider-reported charge. `class-image-client.php` additionally records a **hardcoded `500` tokens per generated image** and `class-ai-client.php` estimates tokens at `chars/3.2` when a provider omits `usage`.
- **Impact:** the "Usage & Costs" screen and the exported report present made-up numbers with the same authority as metered ones.
- **Fix (implemented):** a dated, verified per-model price table sourced from the provider's published pricing page; an explicit `cost_source` column (`provider_reported` | `estimated` | `unknown`) surfaced in the UI; unknown models yield `unknown` rather than a fabricated figure; the fake per-image token count is removed.

### F-09 — Truncated model output is accepted as complete · **MEDIUM**

- **Location:** `includes/Providers/class-ai-client.php` — `call_openai_compatible()`, `call_gemini()`, `call_claude()` never inspect `finish_reason` / `stop_reason` / `finishReason`.
- **Impact:** any length-limited response is treated as a finished article. This is the direct enabler of F-01's silent truncation.
- **Fix (implemented):** all three adapters return the stop reason; the long-form engine reacts to `length` by continuing rather than accepting.

### F-10 — Anonymous, unauthenticated paid operations · **MEDIUM (cost abuse)**

- **Location:** `includes/Core/class-plugin.php` — `rest_handle_support_audio()` (public, triggers a paid Whisper transcription and a Media Library write), `rest_handle_chat()` (public, triggers a paid chat completion), `rest_handle_support()` (public, writes a DB row and sends Telegram/e-mail notifications).
- **Evidence:** `permission_callback => '__return_true'` with an IP-only fixed-window limiter (15 chat / 5 min; **3 audio uploads / 5 min**). `AvandWP_Rate_Limiter::get_client_ip()` trusts only `REMOTE_ADDR` (correct choice — no header spoofing) but therefore also treats every visitor behind the same corporate NAT/CDN as one client.
- **Impact:** a single host can burn the owner's API credit (and fill the Media Library) at a rate of one paid transcription every 100 seconds; legitimate users behind a shared IP are falsely limited.
- **Fix (implemented):** configuration-gated public audio transcription that is **off by default**, a global (not per-IP) daily spend ceiling with a hard stop, and a documented recommendation for a CDN-aware trusted-proxy allowlist.

### F-11 — Knowledge search loads the whole index into PHP · **MEDIUM (performance)**

- **Location:** `includes/Knowledge/class-knowledge-base.php` (`SELECT … FROM avandwp_knowledge LIMIT 400`, then cosine similarity in PHP)
- **Impact:** up to 400 `longtext` rows — including a JSON embedding of up to 1,536 floats per row (potentially tens of MB) — are loaded and decoded on **every** chat message and **every** article generation. On shared hosting this is the most likely cause of a memory-limit fatal on a content-heavy site.
- **Fix (implemented):** bounded column selection (embedding excluded from the lexical path), adaptive candidate limit, and a cached, regenerated-on-demand index with an explicit invalidation path.

### F-12 — Rate limiter writes to the database on every public request · **LOW/MEDIUM (performance)**

- **Location:** `includes/Security/class-rate-limiter.php`
- **Evidence:** without a persistent object cache, `set_transient()` with an expiry writes two `wp_options` rows per request (`_transient_*`, `_transient_timeout_*`), and the counter path rewrites both on every hit.
- **Impact:** write amplification on the options table — the classic source of `wp_options` bloat and autoload pressure (the limiter correctly passes `false` for autoload only via `update_option` defaults, but `set_transient` on a high-traffic widget path is still two writes/request).
- **Fix (implemented):** the counter uses a single option read/write guarded by an object-cache-friendly path where available, and the limiter is skipped entirely when the endpoint is disabled.

### F-13 — Unguarded `mb_substr()` can fatal on hosts without mbstring · **LOW**

- **Location:** `includes/Providers/class-image-client.php::save_to_media_library()`
- **Evidence:** `sanitize_text_field( mb_substr( $alt_text, 0, 80, 'UTF-8' ) )` — every other call site guards with `function_exists( 'mb_substr' )`; this one does not. `mbstring` is not in WordPress's required extension list.
- **Impact:** fatal `Error: Call to undefined function mb_substr()` during image import, leaving an orphaned upload row.
- **Fix (implemented):** routed through the single guarded helper.

### F-14 — SEO-plugin ownership is not respected; duplicate structured data · **MEDIUM (compatibility)**

- **Location:** `includes/Content/class-content-studio.php` (writes `_yoast_wpseo_title/_metadesc/_focuskw` **and** `rank_math_title/description/focus_keyword` unconditionally); `includes/Automation/class-content-auditor.php` (same); `includes/Core/class-plugin.php::output_faq_schema_jsonld()`
- **Evidence:** no detection of which SEO plugin is active; no check for an existing FAQ schema emitter; the FAQ block is emitted for `post`, `page` and `product` whenever `_avandwp_faq_items` exists.
- **Impact:** metadata written into a plugin that is not installed (dead data, confusing on later migration), and two `FAQPage` graphs on one page when an SEO plugin already provides one — which can invalidate rich-result eligibility rather than win it.
- **Fix (implemented):** an explicit `AvandWP_SEO_Adapter` detects the active plugin (Rank Math / Yoast / AIOSEO / SEOPress / none), writes **only** to that plugin's own keys, and the JSON-LD emitter defers to the detected plugin where it already outputs the same graph.

### F-15 — Minor correctness issues

| # | Location | Issue | Impact |
|---|---|---|---|
| a | `class-image-client.php` | `call_stability()` ignores the requested `$size` and always sends `aspect_ratio => '16:9'` | Featured image aspect ratio silently wrong |
| b | `class-image-client.php` | Downloaded bytes are saved with the MIME derived only from the response `content-type` header; no `getimagesize()` / real-type validation | A non-image response body can be imported as `image/png` |
| c | `class-image-client.php` | No upper bound on the downloaded image body | Memory exhaustion from an oversized response |
| d | `class-content-studio.php` | `get_current_user_id() ?: 1` as post author; in a cron context the article is attributed to user 1 | Wrong byline/author on background-generated content |
| e | `class-plugin.php::ajax_save_settings()` | Keys are stripped of anything `sanitize_text_field()` removes; a key containing `••••` (4 bullets) anywhere is treated as "unchanged" | Cosmetic; documented behaviour is acceptable but brittle |
| f | `class-text-helper.php::estimate_tokens()` | Fixed `chars/3.2` heuristic presented in the ledger as a token count | Under-/over-states usage for Persian text; now labelled as an estimate (F-08) |
| g | `readme.txt` | `Tested up to: 6.7` while the plugin declares `Requires at least: 6.0`; no `Tested up to` bump was verifiable here | Distribution metadata may be stale |
| h | `class-content-studio.php` | FAQ section heading is emitted inside the generated HTML even when the source article already contains an FAQ section | Possible duplicated FAQ blocks |

---

## 4. Verified strengths (do not regress these)

These were checked specifically and are correct; the remediation work must preserve them.

1. **Every one of the 29 PHP files parses cleanly** under a real PHP 8.2 parser.
2. **All database writes go through `$wpdb->insert()`/`update()`/`prepare()`** with explicit format arrays. No string-interpolated user input was found in SQL. Table names are interpolated, but they are built from `$wpdb->prefix` plus constants.
3. **No XSS was found** in `admin.js` or `chat-widget.js`; every dynamic string passes through `escHtml()` before `innerHTML`, and PHP templates consistently use `esc_html`/`esc_attr`/`esc_url`/`esc_textarea`/`selected`. AI-generated article HTML is passed through `wp_kses_post()`.
4. **The chat assistant rejects client-supplied `system` roles** and slices history to the last 4 user/assistant turns — a genuinely good prompt-injection control.
5. **SSRF controls exist**: `wp_http_validate_url()` on the Telegram proxy (plus an `https`-only rule) and on image downloads; the rate limiter deliberately ignores `X-Forwarded-For`, so the IP cannot be spoofed for limit evasion.
6. **Destructive behaviour is opt-in**: uninstall deletes data only when the administrator opted in; the content auditor is explicitly non-destructive; the malicious legacy `SEO_Watcher` cron is gone.
7. **Admin assets load only on the plugin's own screens**, and `manage_options` is enforced on the settings, automation and export surfaces.
8. **The XLSX export** requires both a valid nonce and `manage_options`, and streams from a temp file instead of writing into the public uploads directory.
9. **Capability separation** between `edit_posts` and `manage_options` exists as a design (its *granularity* is the defect in F-03, not its absence).

---

## 5. Gap analysis against the target product definition

The baseline is a **content-generation + WooCommerce + chatbot + Telegram** plugin. The target is an **AI content intelligence, SEO audit, GEO/AIO optimisation and search-analytics platform**. Measured against the required modules:

| Required module | Baseline state | Verdict |
|---|---|---|
| A — Advanced AI Article Studio (briefs, 4,500-word default, word/char modes, resumable pipeline) | Partial: topic/keyword/tone only, 600–1,600 words, one-shot, no outline review, no resumability | **Rebuild required** |
| B — Link intelligence | Absent — internal links are delegated to the LLM with no verification; no external sources; no approval UI | **New** |
| C — SEO / AIO / GEO Quality Lab (documented criteria, evidence, severity) | **Absent** — no scoring of any kind exists | **New** |
| D — Automated diagnosis and iterative correction with diff/rollback | **Absent** | **New** |
| E — Image Studio (contextual inline images, alt text, regeneration) | Partial: single featured image, no inline generation, no regeneration, no style presets | **Extend** |
| F — GapGPT adapter | Partial and built on unverified model ids | **Correct + extend** |
| G — Website SEO Control Center | Absent (the "auditor" only checks thumbnail/SEO-meta/thin-content on 50 posts) | **New** |
| H — Google Search Console | **Absent** | **New** |
| I — Google Analytics 4 | **Absent** | **New** |
| J — SEO-plugin compatibility | Absent, and actively harmful (F-14) | **New** |
| K — Premium admin UX | Reasonable design system on 6 pages; navigation does not match the target IA | **Extend + re-IA** |
| L — Security/privacy governance | Good foundation; F-03/F-07/F-10 must be fixed first | **Harden** |
| M — Performance/reliability | F-04/F-05/F-11/F-12 | **Harden** |
| N — Commercial readiness | `docs/COMMERCIAL-LAUNCH-KIT.md` is marketing collateral, not a competitive analysis | **New** |
| O — Testing/QA | **No tests, no CI, no static analysis config** | **New** |

**Conclusion.** Roughly 25–30 % of the target feature set exists in usable form (provider plumbing, credential vault, job queue skeleton, knowledge index, Telegram automation, admin shell). The remaining 70 % — the entire evaluation, linking, auditing and Google-integration surface — must be built. The correct strategy is to **extend in place**: keep the eight tables, the options, the REST contract and the six existing screens working, and add the new modules alongside them, rather than rewriting a working plugin.

---

## 6. Baseline regression target

The following command is the machine-checked baseline that every subsequent change must keep passing:

```
npm install       # installs php-wasm 0.2.0 (dev-only, never shipped)
npm run lint      # parse all 29 PHP files with a real PHP 8.2 parser
npm run test      # execute the unit suite
npm run verify    # lint + test + build the release ZIP and validate its contents
```

Recorded baseline (2026-10-12, before any modification): **29 files parsed, 0 syntax failures, 0 blocking policy violations, 0 tests present.**

---

## 7. Related documents

- `docs/security-review.md` — threat model and the security-specific treatment of F-03, F-07, F-10.
- `docs/api-integrations.md` — the verified GapGPT contract and the evidence behind F-02/F-08.
- `docs/architecture.md` — the module boundaries chosen to close the gaps in §5.
- `docs/implementation-plan.md` — the ordered remediation and delivery plan.
- `docs/seo-scoring-model.md` — the documented criteria behind Module C.
- `docs/compatibility.md`, `docs/testing-report.md`.
