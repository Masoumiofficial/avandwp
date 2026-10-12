# AvandWP — Compatibility Matrix

**Date:** 2026-10-12 · Behaviour below is read from `AvandWP_SEO_Adapter`,
`AvandWP_Provider_Registry`, `avandwp.php` and the health screen. Items that
could not be executed in this environment are marked **unverified**.

---

## 1. WordPress, PHP, hosting

| Requirement | Declared | Verified here | Notes |
|---|---|---|---|
| WordPress | 6.0+ | unverified | the plugin uses only core APIs (`wp_remote_*`, `dbDelta`, transients, options, `wp_handle_upload`-adjacent APIs) |
| PHP | 7.4+ | **not executed** | the interpreter available for testing starts at 8.0; a lint policy forbids PHP 8.0-only constructs in shipped source, which is a static argument rather than a run |
| MySQL / MariaDB | any WP-supported version | unverified | `utf8mb4` tables, `dbDelta` additive migrations, no stored procedures |
| Multisite | not claimed | unverified | tables are created per-site through normal activation; no network-wide activation path was tested |
| Shared hosting | primary target | partial | all long operations are batched with a polite delay and resume from checkpoints; **no load or timeout test was run** |
| `openssl` extension | recommended | fallback tested | without it, credentials are stored base64-obfuscated, the UI shows a security warning and no encryption is claimed |
| `mbstring` | optional | fallback tested | Persian length/word counting falls back to byte-safe routines that still count characters, not bytes |
| Outbound HTTPS | required for AI features | untested | blocked hosts are reported with the failing URL rather than a generic error |

---

## 2. SEO plugins (Module J)

Detection order and write behaviour (`write_support()`):

| Plugin | Detected by | Meta keys written | Writes? |
|---|---|---|---|
| Rank Math | `RankMath` class or `RANK_MATH_VERSION` | `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` | yes |
| Yoast SEO | `WPSEO_Options` / `WPSEO_VERSION` | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw` | yes |
| SEOPress | `SEOPRESS_VERSION` / `seopress_init` | `_seopress_titles_title`, `_seopress_titles_desc`, `_seopress_analysis_target_kw` | yes |
| All in One SEO 4 | `aioseo()` / `AIOSEO_VERSION` | — | **no** — AIOSEO 4 keeps SEO data in its own tables; writing there directly is unsafe, so the plugin generates the values for the user to paste and says so |
| none | — | — | **no** — the plugin does not invent a parallel SEO field; it tells the user to install one of the supported plugins |

Two rules are absolute:

1. **One source of truth.** The 1.0.0 baseline wrote Yoast *and* Rank Math meta on
   every post. That produced contradictory titles and descriptions depending on
   which plugin rendered the page. The adapter writes to the detected plugin only.
2. **Never overwrite silently.** Metadata is written during explicit generation,
   and the value written is shown in the result card.

Schema: `plugin_outputs_faq_schema()` returns `true` whenever a capable SEO plugin
is active, because those plugins can emit their own FAQ graph per post. In that
case AvandWP does **not** emit a second FAQ graph — two graphs on one page is a
duplicate-entity error. With no SEO plugin the function returns `false` and AvandWP
may emit its own (this is asserted by a unit test).

Multilingual plugins (WPML/Polylang) are not integrated: generated content is
stored on the post in the default language. **Unverified and not claimed.**

---

## 3. Themes, page builders, editors

| Surface | Behaviour |
|---|---|
| Block editor (Gutenberg) | generated HTML uses `wp_kses_post`-safe markup: `h2`/`h3`, `p`, `ul`/`ol`, `table`, `figure`, `a`, `img` with alt |
| Classic editor | same HTML is valid |
| Elementor / Divi / WPBakery | content is written to `post_content`; page builders that render from their own data store will not display it. **Unverified** — needs a real site |
| RTL themes | all admin screens are RTL; generated identifiers, URLs and JSON are marked `dir="ltr"` |
| WooCommerce | detected via `class_exists( 'WooCommerce' )`; the assistant is hidden when WooCommerce is absent |

---

## 4. AI providers

| Provider | Text | Image | Transcription | Speech | Embedding |
|---|---|---|---|---|---|
| GapGPT | yes (catalogue) | yes (catalogue) | yes | yes | yes |
| OpenAI | yes | yes | yes | yes | yes |
| Google Gemini | yes | — | — | — | yes |
| Anthropic Claude | yes | — | — | — | — |
| FAL.ai | — | yes | — | — | — |
| Stability AI | — | yes | — | — | — |

"yes" means the adapter and endpoint family exist in `Provider_Registry::all()`.
For providers other than GapGPT there is **no model catalogue**: the identifier
must come from the provider's own documentation, and the plugin says so in the
settings field rather than shipping a guessed list. Cost reporting for those
providers is marked unverified.

A configured fallback provider is used only for retryable failures on the stage
that failed; `auth`, `invalid_request` and `content` errors are returned
immediately, because replaying a rejected request against a second provider
spends money without a chance of success.

---

## 5. Google integrations

| Integration | Requirement |
|---|---|
| Search Console | Google Cloud project; Search Console API enabled; verified property; account has at least read access to that property |
| GA4 | Google Cloud project; Analytics Data API enabled; `analytics.readonly` granted on the property |
| Both | HTTPS site (Google requires an HTTPS redirect URI for these scopes) |

See [`api-integrations.md`](api-integrations.md) §5 for the exact redirect URI and
scope list.

---

## 6. Interaction with other plugins

| Plugin type | Interaction |
|---|---|
| Caching plugins | the plugin does not purge third-party caches; it writes posts through `wp_insert_post`/`wp_update_post` like any editor, so a cache plugin's own invalidation rules apply |
| Security plugins | no file writes outside `WP_CONTENT_DIR/uploads` (generated images) and no custom `.htaccess`; the REST routes are standard `register_rest_route` endpoints and can be disabled by any firewall that blocks them |
| Backup plugins | all data lives in WordPress tables and options, so a standard database backup covers it; the vault key derives from `AUTH_KEY`, which a restore must preserve or credentials become unreadable (and the UI says so) |
| SMTP/mail | not used; notifications go through Telegram or the admin screens |

---

## 7. Known incompatibilities and limits

1. **PHP 7.4 runtime not executed.** The header declares 7.4 and the source
   contains no PHP 8.0-only construct (enforced by lint), but only 8.0+ has been
   run. If a 7.4 install reports a fatal error, that is a bug and should be filed.
2. **AIOSEO 4 metadata is not written** (own table), by design.
3. **No rank tracking.** Google publishes no such API; the plugin will not invent
   one.
4. **Crawler is same-host.** External links are captured as link targets, not
   crawled.
5. **Page builders with their own content store** are not supported for
   generation output.
6. **Multilingual plugins** are not integrated.
