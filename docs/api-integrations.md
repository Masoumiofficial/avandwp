# AvandWP — API & Integrations Reference

**Date:** 2026-10-12 · **All endpoints and parameters below were read from the
provider's own documentation on that date**, and the parts that could not be
verified from documentation are marked as such. No endpoint in this file was
invented, and no live request was made from this environment.

---

## 1. GapGPT (primary text and image provider, Module F)

| Item | Value | Source |
|---|---|---|
| Base URL | `https://api.gapgpt.app/v1` | `gapgpt.app/platform-v2/docs/quickstart` |
| Alternate CDN host | `https://api.gapapi.com/v1` | same |
| Compatibility | OpenAI SDK compatible (`Authorization: Bearer <key>`) | same |
| Key management | `gapgpt.app/platform-v2/tokens` (shown once) | same |
| Usage/cost dashboard | `gapgpt.app/platform-v2/usage` | same |
| Chat | `POST {base}/chat/completions` | `…/docs/use-cases/n8n` |
| Images | `POST {base}/images/generations`, body `{"model","prompt","size"}` | same |
| Image response | `data[0].url` — a **URL**, never raw bytes; a second request downloads the file | quickstart |
| Error shape | `response.error` | n8n use case |

Text models published in the price catalogue (verified 2026-10-12; per
1M tokens, input/output USD): `gpt-5.6-terra` 2/12, `gpt-5.6-luna` 0.20/1.20,
`gpt-5.6-sol` 2.50/15, `gpt-6-luna` 0.10/0.50, `gpt-6.1-sol` 2/10,
`gapgpt-agent` 0.10/0.50, `gapgpt-qwen-3.6` 0.25/2, `gapgpt-gemma-26b-a4b` 0.15/0.60,
`claude-sonnet-5` 2/10, and others — the full table lives in
`AvandWP_Price_Catalog::token_rates()`.

Image billing shapes:

| Model | Basis | Value |
|---|---|---|
| `flux-1-schnell` | per image | $0.0014 |
| `gemini-2.5-flash-image` | per image | $0.040 |
| `gapgpt/qwen-image-2.1` | per megapixel | $0.005 |
| `gpt-image-2`, `gpt-image-1`, `gpt-image-1-mini` | per **token** | e.g. `gpt-image-2` 8/30 per 1M tokens |

Because the per-token models have no fixed per-image price, the plugin reports
`cost = null`, `source = 'unknown'` and explains *why* in the UI. It does not
invent a per-image number.

**Removed defaults.** The 1.0.0 baseline defaulted to `gpt-4o-mini` and
`dall-e-3`. Neither identifier appears in GapGPT's published catalogue; requests
against them fail and are still billed. The defaults are now
`gpt-5.6-terra` (text) and `gpt-image-2` (image).

---

## 2. Direct providers

| Provider | Base URL | Adapter | Auth |
|---|---|---|---|
| OpenAI | `https://api.openai.com/v1` | `openai_compatible` | `Authorization: Bearer` |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta` | `gemini` (`POST {base}/models/{model}:generateContent`) | `x-goog-api-key` |
| Anthropic Claude | `https://api.anthropic.com/v1` | `claude` (`POST {base}/messages`) | `x-api-key` |
| FAL.ai | `https://fal.run` | `fal_run` (model in the path) | `Authorization: Key …` |
| Stability AI | `https://api.stability.ai/v2beta/stable-image/generate` | `stability` (multipart) | `Authorization: Bearer` |

For these providers the plugin declares **no** model catalogue: their model
line-ups are not in the verified price catalogue, so a site owner must paste the
identifier from the provider's own documentation. Cost reporting for them is
labelled `estimated`/unverified rather than presented as measured.

---

## 3. Google Search Console (Module H)

| Item | Value |
|---|---|
| List properties | `GET https://www.googleapis.com/webmasters/v3/sites` (no body) |
| Query | `POST https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query` |
| Scopes | `https://www.googleapis.com/auth/webmasters.readonly` (read-only only) |
| Body | `{startDate, endDate, dimensions[], type, dimensionFilterGroups[], aggregationType, rowLimit, startRow}` |
| Dates | `YYYY-MM-DD` interpreted in **Pacific Time** (UTC−7/−8) |
| Ordering | results return sorted by clicks descending |

Two behaviours are encoded because the API's own documentation states them:

1. *"This method does not guarantee to return all data rows but rather top rows."*
   → `query()` sets `totals.truncated` when the returned row count reaches the
   requested limit, and the UI prints that the totals cover only the returned
   set. A query export is never presented as complete data.
2. Days with no activity are omitted when `date` is a dimension → the plugin does
   not "fill" missing days with zeros.

Additionally the default end date is **today − 2 days**: Search Console data is
not final for the current or previous day, and reporting a partial day as a
complete one would be a fabricated number.

`siteUrl` resolution is deliberately strict: the plugin matches the connected
account's property list against `home_url()`'s host, returns an explicit error if
nothing matches, and refuses to silently pick an unrelated property (which would
print another site's numbers under this site's name).

---

## 4. Google Analytics 4 (Module I)

| Item | Value |
|---|---|
| Property discovery | `GET https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize≤200` |
| Report | `POST https://analyticsdata.googleapis.com/v1beta/{property=properties/*}:runReport` |
| Scopes | `https://www.googleapis.com/auth/analytics.readonly` |
| Property id | must be supplied; the Data API cannot enumerate properties |

Metrics requested (all declared in `metric_labels()`): `sessions`, `totalUsers`,
`screenPageViews`, `averageSessionDuration`, `engagementRate`, `keyEvents`.
Dimensions: `date`, `pagePath`, `sessionDefaultChannelGroup`, `deviceCategory`,
`country`. Row limit ≤ 200 (`MAX_ROWS`).

Two API signals are surfaced instead of swallowed:

* `metadata.subjectToThresholding` → the report is marked as thresholded;
* a `(other)` row → the report is marked as containing a collapsed remainder.

Either one produces a plain-language completeness note, because both mean the
numbers shown are not the whole picture.

---

## 5. OAuth flow (Module H, shared by H and I)

```
admin.php?page=avandwp-integrations  →  Google consent
        (state stored as a 15-minute transient per user)
        ▼
admin.php?page=avandwp-integrations&avandwp_oauth=google&code=…&state=…
        │  capability check (manage_options)
        │  hash_equals( stored_state, returned_state )
        │  exchange code at https://oauth2.googleapis.com/token
        │  require refresh_token (else: actionable error)
        ▼
vault: google_refresh_token (AES-256-GCM)   option: avandwp_google_connection
                                             (email, scopes, timestamps, last_error)
```

* `access_type=offline` **and** `prompt=consent` are both sent. Without
  `prompt=consent` Google only issues a refresh token on the very first consent,
  which is the classic "the connection worked yesterday" failure.
* The access token is cached in a 50-minute transient (Google issues 60-minute
  tokens) and is never sent to the browser.
* Disconnect calls `https://oauth2.googleapis.com/revoke`, clears the vault slot,
  deletes the transient and resets the connection option.
* The redirect URI shown in the settings screen is computed by
  `admin_url( 'admin.php?page=avandwp-integrations' )` — the value on screen is the
  value sent to Google, so a `redirect_uri_mismatch` cannot come from a stale copy.

**Required Google Cloud setup** (steps shown in the UI): create a project, enable
the Search Console API and the Analytics Data API, create a *Web application*
OAuth client, register the redirect URI above, then paste the Client ID into
تنظیمات و سلامت and the Client Secret into the vault.

**Blocked state.** Until credentials exist and consent completes, both
integrations report `blocked_by_credentials` with an explanation and display **no
numbers at all** — not zeros, not samples.

---

## 6. WhatsApp/Telegram, SEO plugins, WooCommerce

| Integration | Mechanism |
|---|---|
| Telegram | Bot API with a vault-stored token, optional proxy; used for notifications and optional auto-publish |
| Rank Math / Yoast / AIOSEO / SEOPress | detected via `SEO_Adapter`; metadata is written through the detected plugin's own meta keys/API, or not at all |
| WooCommerce | product optimization via the job queue; product creation is capability-gated |

Details of the SEO adapter keys and the no-write guarantees are in
[`compatibility.md`](compatibility.md).

---

## 7. What is not integrated, and will not be claimed

* No AI provider is claimed as supported unless its endpoint family and the
  adapter that speaks it exist in `Provider_Registry::all()`.
* No Search Console or Analytics number is cached indefinitely: transients expire
  after one hour and are cleared on disconnect or property change.
* No rank-tracking API is integrated. Google does not publish one; any product
  claiming exact rank positions is measuring something else.
