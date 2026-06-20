=== PageSpeedPlus — Cache & Core Web Vitals Optimization ===
Contributors: pagespeedplus
Tags: cache, performance, core web vitals, lazy load, minify
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.13.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

All-in-one speed optimization: page caching, delayed JavaScript, lazy loading, CSS/JS optimization and Core Web Vitals improvements.

== Description ==

PageSpeedPlus makes your WordPress site dramatically faster and improves your Core Web Vitals (LCP, CLS, INP) — the metrics Google uses for ranking.

= Caching =
* **Full page caching** served before WordPress even loads (advanced-cache drop-in)
* Separate mobile cache, gzip pre-compression, smart purging on content changes
* **Cache preloading** from your sitemap so visitors always hit a warm cache
* Browser caching headers and GZIP compression rules

= JavaScript =
* **Delay JavaScript until interaction** — the single biggest Total Blocking Time win
* Defer JavaScript with sensible jQuery exclusions
* Minify and combine local scripts

= CSS =
* Minify and combine stylesheets
* **Async CSS loading** with critical CSS inlining (eliminate render-blocking CSS)

= Media =
* LCP-aware lazy loading: skips above-the-fold images, adds `fetchpriority="high"` and a preload hint for your LCP image
* Adds missing image dimensions to prevent layout shift (CLS)
* **YouTube facades** — replace 500KB+ embeds with a click-to-play thumbnail

= More =
* Google Fonts: self-host locally, `display=swap`, preconnect, custom font preloading
* HTML minification, emoji/embed/dashicons removal, Heartbeat control
* CDN URL rewriting
* WP-CLI support: `wp pagespeedplus purge|preload|webp|critical-css|license|master|setting`

Safe defaults are enabled on activation; aggressive optimizations are opt-in with per-feature exclusion lists.

Pair it with [PageSpeedPlus monitoring](https://pagespeedplus.com) to track your scores automatically after every change.

== Installation ==

1. Upload and activate the plugin.
2. Page caching, lazy loading and HTML minification are enabled automatically.
3. Visit **PageSpeedPlus → JavaScript** and enable *Defer* and *Delay* for the biggest gains — test your site after enabling each one.
4. Re-test your site on PageSpeed Insights.

== Frequently Asked Questions ==

= My site looks broken after enabling an optimization =
Disable the last optimization you enabled and re-test. For Delay/Defer JavaScript, add the offending script's name to the exclusion list. Appending `?psp_nooptimize=1` to any URL bypasses all optimizations for debugging.

= Does it work with WooCommerce? =
Yes. Cart, checkout and account pages are excluded from the cache automatically, as are visitors with items in their cart.

= Does it work on Nginx? =
Yes. Page caching and all optimizations work everywhere. Browser-cache/GZIP rules are Apache/LiteSpeed only — equivalent Nginx config is shown under Cache.

= Is it compatible with other caching plugins? =
Run only one page-caching plugin at a time. PageSpeedPlus will not overwrite another plugin's advanced-cache.php drop-in.

== Changelog ==

= 1.13.3 =
* Cache tab: clarified that the plugin fires one-off warms, while recurring schedules and warm regions are managed in the PageSpeedPlus dashboard — added a deep-link (filter: psp_cache_warm_url).

= 1.13.2 =
* Brotli is now a toggle on the Cache tab (Brotli Compression for cached pages), with an availability indicator — defaults on when the PHP brotli extension is present, off/disabled when it isn't.

= 1.13.1 =
* Dashboard counter now includes the new toggles added in 1.12–1.13 (self-host fonts, auto DNS-prefetch, and the new Tweaks switches), so the total reflects every optimization.

= 1.13.0 =
* Tweaks: new toggles to disable jQuery Migrate, Gutenberg block CSS, conditional comment-reply.js, and remove feed/REST/RSD-WLW/shortlink/version <head> cruft.
* Fonts: Auto DNS-Prefetch — adds dns-prefetch hints for every third-party host on the page.
* Page cache: Brotli pre-compression when the PHP brotli extension is available (drop-in serves .br, falling back to gzip).
* Media: decoding="async" now also applied to above-the-fold images.
* Dashboard: Import / Export settings as JSON.

= 1.12.0 =
* Fonts: new Self-Host Google Fonts option — downloads Google Fonts (CSS + woff2) to your server in the background and serves them locally, removing the render-blocking request to fonts.googleapis.com/gstatic.com (faster + GDPR-friendly). Opt-in; safe fallback to the original request until the local copy is ready.

= 1.11.5 =
* CSS tab: automatic Critical CSS generation is now gated behind a backend-availability flag (the cloud service isn't live yet) and shows "Coming soon" instead of a button that errors. Manual Critical CSS + Async CSS are unchanged. Flip the PSP_CCSS_BACKEND constant once the endpoint ships.

= 1.11.4 =
* Cache tab: moved the Purge and Preload buttons to the top of the page for quicker access.

= 1.11.3 =
* Header logo now sits directly on the masthead (removed the white plate).

= 1.11.2 =
* CSS tab: clarified that Manual Critical CSS takes raw CSS (no <style> tags) and that Excluded Stylesheets match on a fragment, not a full URL; added example placeholders to both.

= 1.11.1 =
* CDN tab: can no longer enable CDN rewriting without a CDN URL (blocked client- and server-side).
* License tab: removed the redundant "choose your site on the Dashboard" card.
* Added example placeholders to the Media (lazy-load) and JavaScript (defer/delay/prefetch) exclusion boxes, and clarified that a filename fragment is enough — no full URL needed.

= 1.11.0 =
* Header now uses the real PageSpeedPlus logo (bundled locally, no external request).
* Media tab: added a clear "Image Engine" readout (Imagick/GD and which formats are supported).
* Cache tab: clarified that Auto-Warm fires only after a FULL purge and uses the warming scope (not per-page); noted that Browser Cache/GZIP are .htaccess-only and Nginx users need the server rules; removed the duplicate Connected Site row (it lives on the Dashboard).

= 1.10.5 =
* Retired the Tools tab: Purge + Preload actions and the Nginx server rules now live on the Cache tab, alongside the caching and warming settings they operate on.

= 1.10.4 =
* License tab: the "Connected" status is now green, and the internal dev-mode note no longer shows.

= 1.10.3 =
* Fonts tab: clarified that Preconnect/DNS Prefetch accept a full origin URL (a bare host also works); examples now match the placeholders.

= 1.10.2 =
* Dashboard: removed the "Getting the Most Out of It" tips card to keep the home view focused on status and connection.

= 1.10.1 =
* License tab: status now reads "Connected" / "Disconnected" up front (with the underlying state as a note); "Get a Key" links straight to your API tokens page.
* Removed the duplicate Purge button from the Dashboard — purge lives in the header; Purge + Preload now have a single home on the Tools tab, which was reorganized into clear Maintenance and Server sections.
* Fonts tab: added example placeholders to the Preload Fonts / Preconnect / DNS Prefetch boxes. Tweaks tab: explained what each Heartbeat control does.

= 1.10.0 =
* Rebrand: admin now matches the pagespeedplus.com palette (deep blue + gold, speedometer logo) instead of the old navy/lime theme.
* Dashboard: replaced the partial telemetry stats bar with an honest "X of Y settings active" counter covering every optimization toggle. Removed the "Cache active" status chip from the header (it was only one setting of many).

= 1.9.2 =
* Tidied the connected-site controls into a clear two-row layout (connect on top, "or create" below; Refresh as a subtle link).

= 1.9.1 =
* Dashboard: removed the misleading "% optimized" gauge and the Measure Your Results card; cached-size now counts only cached pages. Added a Refresh button for the connected-site list.

= 1.9.0 =
* Real User Monitoring: new Web Vitals tab adds the PageSpeedPlus web-vitals beacon to every page (gated by your connected site), reporting real-visitor LCP/CLS/INP/TTFB. Spinner feedback while validating a key, and clearer connect-button wording.

= 1.8.0 =
* Account connection: your API key is now validated live against app.pagespeedplus.com (GET /api/sites). On success you pick which of your PageSpeedPlus sites to connect from a dropdown, or create a new one — no more pasting a Site ID.

= 1.7.0 =
* Content Visibility: optionally add content-visibility:auto to chosen below-the-fold containers (e.g. footer) to cut initial render work, with reserved height to avoid layout shift.

= 1.6.0 =
* Link prefetching: preload the next page on hover (desktop) or tap (mobile) for instant-feeling navigation; pairs with the page cache. Safe exclusions built in.

= 1.5.0 =
* Removed the database cleanup feature (destructive DB operations are out of scope for a performance plugin and a support liability). PageSpeedPlus no longer modifies your database.
* Cache reliability: the advanced-cache config self-heals if its config file goes missing (e.g. after a deploy), and the drop-in now uses a weak ETag.

= 1.4.0 =
* Per-URL rules: new "Disable Everything On URLs" list turns off all caching and optimizations on matching pages (e.g. custom dashboards). URL exclusion lists now support * wildcards (/admin*, /shop/*/cart).

= 1.3.0 =
* Cache warmer integration: trigger off-server warming via the PageSpeedPlus app API (runs from up to 13 global locations against your public URLs, never loading your PHP workers). Local WP-Cron crawler remains the default.
* WebP/AVIF: disabled toggles + admin notice when no Imagick/GD engine is present; Dashboard image-engine readout.

= 1.2.0 =
* License system: activation, daily revalidation with 7-day grace window, license-gated optimizations.
* Self-hosted plugin updates via the PageSpeedPlus API.
* New WP-CLI command: license (status|activate|deactivate).

= 1.1.0 =
* WebP and AVIF conversion: automatic on upload, bulk conversion for existing media, Accept-header negotiation on Apache/LiteSpeed with HTML rewrite fallback.
* Automatic per-page-type Critical CSS generation via the PageSpeedPlus API.
* New WP-CLI commands: webp, critical-css.

= 1.0.0 =
* Initial release.
