# Build Spec — Cloud CSS Service (Critical CSS + Remove Unused CSS)

Status: **planned**. This is the single highest-leverage feature-parity move
(see the competitive analysis: Remove Unused CSS is the #1 reason people pick
FlyingPress over WP Rocket, and automatic Critical CSS is table-stakes among
premium plugins). Both features are powered by the **same** cloud service — a
headless browser that loads a URL and reports which CSS it actually uses. They
should be built and shipped together.

The wire contract for the Critical CSS endpoint already exists in
[API-CONTRACT.md](API-CONTRACT.md) §2. This doc is the implementation plan and
the additional RUCSS contract + plugin-side work.

---

## 1. Where things stand today

| Piece | State |
|---|---|
| Critical CSS **client** (`PSP_Critical_CSS`) | ✅ built — queues page-type contexts, POSTs each URL to `/v1/critical-css`, stores result in `psp_critical_css_store`, `for_current_request()` feeds `PSP_Assets::async_css()` which inlines it in `<head>`. |
| Critical CSS **backend** (`api.pagespeedplus.com/v1/critical-css`) | ❌ not built. |
| Critical CSS **UI gate** | ✅ added v1.11.5 — `PSP_Critical_CSS::backend_available()` (`PSP_CCSS_BACKEND` constant / `psp_ccss_backend_available` filter, default OFF). UI shows "Coming soon". |
| Manual Critical CSS + Async CSS | ✅ work today (no backend needed). |
| Remove Unused CSS (RUCSS) | ❌ nothing — no client, no backend, no UI. |

**Goal:** build the backend, flip the gate for Critical CSS, and add a RUCSS
module behind the same gate.

---

## 2. The shared cloud service

One service, two outputs, from a single headless-Chrome render per URL.

```
plugin → POST {url}  ─────────►  cloud service
                                 1. headless Chrome loads {url}
                                 2. enable CSS coverage (CDP Profiler/CSS.startRuleUsageTracking)
                                 3. settle: wait for network idle + fonts + a scroll pass
                                 4. read coverage → set of USED selectors/rules per stylesheet
                                 5. compute ABOVE-THE-FOLD used rules (rules matching nodes
                                    in the initial viewport at common breakpoints, e.g. 360 / 768 / 1280)
plugin ◄── { css, used_css }     6. return both payloads
```

- **Critical CSS** = above-the-fold used rules, minified, small enough to inline
  in `<head>` (target < ~30–40 KB).
- **Unused-CSS-removed (RUCSS)** = for each original stylesheet, the subset of
  rules actually used anywhere on the page. Keep it **per-stylesheet** (so files
  stay individually cacheable) rather than one giant merged blob — this is the
  FlyingPress edge over WP Rocket's inline-everything approach.

### Implementation notes
- **Engine:** headless Chrome (Puppeteer/Playwright) + the DevTools **CSS
  coverage** API. Don't regex-parse HTML — coverage is the only reliable signal.
- **Settle correctly** before reading coverage: `networkidle`, `document.fonts.ready`,
  a forced scroll to the bottom and back (to trigger lazy/intersection styles),
  and a short timeout for JS that adds classes. Under-settling = missing styles
  = broken pages, the #1 RUCSS failure mode.
- **Safelist always-keep patterns** server-side too (see §4) — never strip
  `@font-face`, keyframes referenced by kept rules, or `:hover`/`:focus`/
  `aria-*`/`is-*`/`has-*` state classes that coverage misses because they need
  interaction.
- **Async job model:** the plugin already polls via WP-Cron one URL at a time.
  Either (a) respond synchronously within ~60s (current Critical CSS contract),
  or (b) return `202 {job_id}` and add a `GET /v1/css/job/{id}` poll. Given the
  plugin's sequential cron worker, synchronous-with-60s-timeout is simplest for
  v1; move to job IDs if render time grows.
- **Dedup/cache server-side** by `(normalized_url, theme_fingerprint)` so repeated
  requests across sites/pages with identical markup are cheap.
- **Auth:** `Authorization: Bearer <psp_api_key>` (same key as everything else).
  Meter usage per key for plan limits.

---

## 3. New contract — Remove Unused CSS

Add to API-CONTRACT.md §2.

### POST /v1/unused-css

Auth: `Authorization: Bearer <key>`.

Request:
```json
{
  "url": "https://customer-site.com/some-page/",
  "stylesheets": [
    "https://customer-site.com/wp-content/themes/x/style.css?ver=1.2",
    "https://customer-site.com/wp-includes/css/dist/block-library/style.min.css"
  ],
  "safelist": ["is-active", "menu-open", ".wp-block-*"],
  "source": "wp-plugin/1.x"
}
```
- `stylesheets` — the local stylesheet URLs found on the page (plugin sends them
  so the service trims exactly those; third-party/CDN sheets can be skipped).
- `safelist` — selectors/patterns the user marked never-remove (merged with the
  service's built-in safelist).

Success:
```json
{
  "sheets": {
    "https://.../style.css?ver=1.2": ".header{...}.used-only{...}",
    "https://.../block-library/style.min.css": ".wp-block-image{...}"
  },
  "stats": { "before": 412904, "after": 38211, "removed_pct": 90.7 }
}
```

Failure: `{ "error": "reason" }` non-200 (plugin records it in the status table
and leaves the original CSS untouched — never serve a half-trimmed page).

---

## 4. Plugin-side work for RUCSS

Mirror the Critical CSS architecture as closely as possible — it's a proven
shape in this codebase.

**New module `PSP_Unused_CSS` (`includes/class-psp-unused-css.php`):**
- Reuse `PSP_Critical_CSS::contexts()` (one representative URL per page type) OR
  go per-URL — **decision needed**, see §6. Recommend page-type for v1 (cheaper,
  matches Critical CSS, good enough for most themes).
- Queue + WP-Cron worker (`psp_rucss_queue`, `psp_rucss_batch`) — copy the
  `start()` / `process_batch()` / `generate_for_url()` pattern from
  `PSP_Critical_CSS`. On the page being processed, collect its local `<link rel=stylesheet>`
  URLs and POST them with the safelist.
- Store results in `psp_unused_css_store` keyed by context → `{ sheets, stats, updated }`.
- Write each trimmed sheet to `wp-content/cache/pagespeedplus-assets/rucss/{hash}.css`
  (reuse the existing asset-cache dir + URL).

**Serving (`PSP_Assets`, buffer priority ~20, alongside async/combine):**
- When RUCSS is on and a trimmed set exists for the current context: replace each
  matched `<link rel=stylesheet href=ORIGINAL>` with a `<link>` to its trimmed
  local file. Combine this with the existing **Async CSS** path so the trimmed
  sheets load non-blocking, and inline **Critical CSS** for first paint. The
  three features compose: critical inline → async-load trimmed sheets.
- Never touch excluded/third-party sheets or anything in the user's CSS exclusion
  list. Honor `?psp_nooptimize=1`.

**Settings (`PSP_Options` defaults + CSS tab):**
- `rucss_enabled` (0), `rucss_safelist` (textarea, one selector/pattern per line).
- Gate the whole UI behind `PSP_Critical_CSS::backend_available()` (same flag —
  same backend). Show a status table like the Critical CSS one (page type → before/after
  size → updated).

**Invalidation:** flush both stores on `switch_theme`, `customize_save_after`,
and when the user saves CSS-tab settings (the existing save → purge already fires
`psp_cache_cleared`; hook RUCSS/Critical flush to the same signal). Stale trimmed
CSS after a theme/plugin change is the other big failure mode.

---

## 5. Rollout sequence

1. **Build the backend** `/v1/critical-css` (contract already defined). Verify
   against the existing client via the `psp_ccss_endpoint` filter pointing at staging.
2. **Flip the gate** — set `PSP_CCSS_BACKEND` true (or have `backend_available()`
   probe a cheap health endpoint). Critical CSS auto-generation goes live with
   zero further plugin work.
3. **Add `/v1/unused-css`** to the service (same render, extra payload).
4. **Ship `PSP_Unused_CSS`** behind the same gate; beta with `rucss_enabled` off
   by default and a prominent "test your site after enabling" warning.
5. Add `wp pagespeedplus rucss` WP-CLI command (mirror `critical-css`).

---

## 6. Open decisions

- **Per-URL vs per-page-type.** Per-URL is most accurate (FlyingPress/NitroPack
  do this) but multiplies render cost and storage. Per-page-type matches the
  existing Critical CSS model and is the recommended v1; revisit if themes with
  heavy per-page variation complain.
- **Sync vs job-queue** on the backend (see §2) — start sync, 60s timeout.
- **Safelist defaults.** Ship a strong built-in safelist (WP core block classes,
  common interaction states, `wp-admin-bar`, Elementor/Beaver dynamic classes)
  so users rarely need to touch it. This is most of the support burden.
- **Combine interaction.** Decide whether RUCSS replaces "Combine CSS" or layers
  on top. Recommend: when RUCSS is on, prefer per-file trimmed sheets and treat
  Combine as mutually exclusive (combining trimmed sheets re-bloats the cache key).

---

## 7. Why this is the priority

- Closes the **two** biggest CSS gaps (auto Critical CSS + RUCSS) with one backend.
- The Critical CSS **client is already built and gated** — step 2 is nearly free.
- Plays directly to the SaaS moat: the value is the cloud render, which a GPL
  plugin can't be "pirated" out of.
- Everything plugin-side reuses patterns already in the codebase (queue + cron +
  store + buffer rewrite), so risk is mostly in the **render-settling accuracy**
  and **safelist** — budget QA there.
