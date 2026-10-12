# AvandWP — Security & Privacy Review

**Version:** 2.0.1 · **Date:** 2026-10-12
**Method:** source review of every file in `includes/` plus the static policies in
`tools/lint.mjs` and the executable assertions in `tests/test-security-and-cost.php`.

Status labels: **verified** (checked and correct as written), **verified by test**
(exercised in the unit suite), **open** (known gap), **unverified** (needs a real
installation).

---

## 1. Authentication and authorisation

**Verified.** Authority is checked at two levels:

1. **Capability.** `AvandWP_Plugin::verify_ajax( $capability )` runs the nonce check
   **and** `current_user_can()`. A nonce alone is never treated as authorisation.
   Admin-managed screens use `manage_options`; content screens use `edit_posts`.
2. **Object.** Anything that edits a specific post additionally calls
   `AvandWP_Capabilities::user_can_edit_object( $post_id )`, which resolves the
   post's own post type and checks the matching capability. This closes the
   baseline defect where a Contributor could edit an author's post through the
   plugin's endpoints.

Additional controls:

* `get_current_user_id()` ownership checks gate job polling: a job is visible to
  its creator or to a manager, nobody else.
* REST routes are registered with explicit `permission_callback`s and per-IP rate
  limits (`class-rate-limiter.php`).

**Open (documented, not fixed).** The four public REST routes (`chat`, `support`,
`support/audio`, `feedback`) are intentionally anonymous — they serve the on-site
chat widget — and are protected only by per-IP rate limits (15/60 s, 5/300 s,
3/300 s, 20/60 s). A distributed client can therefore consume API spend. Mitigation
available today: `spend_guard_enabled` + `daily_spend_limit_usd`. A deferred
measure is documented in `technical-audit.md` (F-10).

---

## 2. Input handling

**Verified (policy-enforced).** Two mechanisms:

* `tools/lint.mjs` fails the build if any `$_POST[...]` read in `class-plugin.php`
  lacks an inline sanitiser, a numeric cast, or an explicit `phpcs:ignore`
  acknowledgement, and it requires the typed helpers
  `post_text/post_textarea/post_key/post_int/post_flag/post_float/post_raw/post_array`
  to exist. The handler surface was migrated onto them; the advisory count for
  raw input dropped from 22 to 1 (the generic `$key` read inside `post_array()`).
* Content that reaches the database is sanitised with
  `sanitize_text_field` / `sanitize_textarea_field` / `sanitize_key` / `absint`,
  or `wp_kses_post` when HTML is the point (pasted article text for analysis).

**Verified.** Output escaping is present on every dynamic value in the templates
(`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` for the JS data island). The
admin JS escapes every interpolated value through a single `escHtml()` before DOM
insertion.

**Unverified.** The templates are reviewed by reading; they are not executed
against a real WordPress runtime in this environment.

---

## 3. Credential storage

**Design.** All credentials live in `AvandWP_Vault`, encrypted with AES-256-GCM
under a key derived from `AUTH_KEY`/`SECURE_AUTH_SALT`. The vault never returns a
plaintext key to a template: `get_masked_key()` returns a masked value for display.

**Verified by test.** The non-OpenSSL fallback branch, the mask format, and the
refusal to leak the raw value.

**Verified (design).** If `openssl` is unavailable the value is stored
`b64:`-obfuscated and **not** encrypted; `flag_degraded()` writes
`avandwp_vault_degraded` once, logs `vault.degraded`, and every admin screen shows
a security warning banner. The settings save message also states plainly that the
keys were **not** encrypted on that server, instead of claiming AES-256-GCM.

**Known limitation (documented).** `flag_degraded()` fires when a value is written
through the fallback branch. A value written earlier by the 1.0.0 baseline under a
different scheme is not re-flagged on upgrade. Sites upgrading from 1.0.0 that
never re-save a key will not see the banner until they save.

**Verified.** No credential is ever localised into JavaScript; the lint policy
`no_secret_in_js` fails the build if a key-shaped literal appears in `admin.js`.
The packaging script scans every shipped file for AWS/OpenAI/Google/GitHub/Slack/
Telegram secret shapes and for private-key blocks, and refuses to build if one is
found.

---

## 4. Outbound requests (SSRF and resource limits)

**Verified.** `AvandWP_Outbound_Guard` is the single choke point:

* scheme must be `http`/`https`; credentials in URLs, and non-web schemes
  (`file:`, `gopher:`, `dict:`, …) are rejected;
* private, loopback, link-local and reserved hosts are blocked, including
  `169.254.169.254` and IPv6 loopback;
* redirects are counted, not followed blindly; each hop is re-validated;
* response size caps: images `MAX_IMAGE_BYTES`, HTML `MAX_HTML_BYTES`;
* image payloads are sniffed from their bytes (`getimagesizefromstring()` when
  available, otherwise magic numbers) and must be in the allow-list
  (`png`, `jpeg`, `webp`, `gif`, `avif`). A declared `Content-Type` header is
  treated as a claim, not as evidence.

**Verified by test.** URL validation, size caps, and the MIME allow-list.

---

## 5. Data governance

* `delete_data_on_uninstall` (default **off**) controls whether `uninstall.php`
  drops tables and options. Default off means an uninstall does not silently
  destroy a store's audit history or knowledge index.
* `telemetry_enabled` (default **off**): the plugin sends no usage data anywhere.
* Activity log retention is bounded (`avandwp_activity_log_retention_days`,
  default 30) and pruned by the daily maintenance cron.
* The usage ledger stores token counts and cost *estimates*, not prompts or
  responses. Chat transcripts and ticket content are stored in the plugin's own
  tables and are subject to the same uninstall setting.
* The AI prompt payload contains the article brief and site-derived context
  (titles/URLs of related posts) — never a stored credential and never the whole
  database.
* Support chat can attach a transcript to a ticket; the audio path is bounded by
  the same size caps as other uploads.

---

## 6. Database access

**Verified.** Every query that takes user input uses `$wpdb->prepare()` with typed
placeholders; interpolated table names come from `$wpdb->prefix . constant`
values, never from input. Statements carry explicit
`phpcs:ignore WordPress.DB.DirectDatabaseQuery.*` acknowledgements where caching
is intentionally bypassed.

**Verified (new).** The job queue's dedupe lookup now also binds the excluded job
id (`id != %d`); it previously could have been built by string concatenation in a
future edit — the placeholder keeps it parameterised and is enforced by a lint
policy.

---

## 7. Failure handling

* Provider errors are classified (`rate_limit`, `server`, `network` retryable;
  `auth`, `invalid_request`, `content` terminal) so a retry cannot burn budget on
  an expired key.
* `Spend_Guard` refuses new work when the daily cap is reached and says so in
  Persian rather than failing mid-generation.
* The long-form pipeline checkpoints each stage; a failed stage keeps the
  checkpoint so a retry resumes instead of regenerating (and re-billing).
* A broken criterion inside `Quality_Lab::evaluate()` is caught, marked
  `unavailable` with the exception message, and excluded from the denominator —
  one bad evaluator cannot take down the report or inflate a score.
* Job execution is wrapped in a lock (`class-lock.php`) so a cron tick cannot run
  a job that another request is already executing; the lock is released even on
  `Error`, not only on `Exception` (baseline F-04).

---

## 8. Privacy of third-party data

Search Console and GA4 data is fetched on demand, cached in transients for one
hour, and cleared when the integration is disconnected or the property changes
(`clear_cache()` on save). No Google data is written into the plugin's own tables
except when a user exports a report. OAuth tokens are held in the vault and
revoked at Google on disconnect.

---

## 9. Residual risk register

| # | Risk | Status | Action |
|---|---|---|---|
| S-01 | Anonymous REST routes can consume API spend | open, mitigated | spend guard + rate limiter; CAPTCHA/nonce-per-session is a product decision, not a code fix |
| S-02 | Legacy `b64:` credentials never re-flagged as degraded | open | documented; re-saving a key sets the flag |
| S-03 | Encryption branch unexercised in CI (no OpenSSL in WASM) | unverified | must be confirmed on a host with OpenSSL |
| S-04 | Handler/template behaviour unverified without WordPress | unverified | see `testing-report.md` §6 |
| S-05 | Auto-correction edits content | by design, bounded | structural, reversible, draft-only, original retained for diff/rollback |
| S-06 | Link insertion mutates published content | by design, gated | explicit approval per link, `replace_first_eligible()` refusals, per-link revert |
| S-07 | Publishing without review | by design, gated | `avandwp_brief_publish_unconfirmed` unless the user ticks the confirmation box |
| S-08 | Prompt injection via fetched page content | open | only the site's own pages and owner-supplied reference URLs are fetched; fetched text is passed as data, and generation is instructed to ignore instructions inside it — this is a mitigation, not a guarantee |

---

## 10. What this review does **not** claim

* It is not a penetration test. No fuzzing, no adversarial testing against a
  running installation was performed.
* It does not cover WordPress core, the theme, or other plugins.
* It does not certify GDPR/PCI compliance. It documents where personal data
  (chat logs, tickets) is stored and how a site owner can remove it.
