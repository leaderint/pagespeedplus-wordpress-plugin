# PageSpeedPlus Plugin ↔ API Contract

What the WordPress plugin expects from `api.pagespeedplus.com`. This is the
backend you need to build (or adapt) on the service side. All endpoints are
JSON over HTTPS. Errors should return a JSON body with `message`.

Every request body the plugin sends includes:

```json
{
  "site": "https://customer-site.com",
  "plugin_version": "1.2.0",
  "wp_version": "6.8"
}
```

## 1. License

### POST /v1/license/activate

Called when a customer enters their key. Should record an activation
(site ↔ key) and enforce the plan's site limit.

Request extra fields: `key`.

Success response:

```json
{
  "status": "active",
  "plan": "agency",
  "expires": 1781136000,
  "activation_id": "act_8f3k2"
}
```

Failure: `{ "status": "invalid" | "expired" | "site_limit", "message": "Human-readable reason shown in wp-admin" }`
(HTTP 200 with a non-active status is treated as an explicit rejection;
HTTP 5xx is treated as an outage and the plugin keeps working on its
7-day grace window.)

### POST /v1/license/validate

Called daily by WP-Cron per site. Same response shape as activate.
This is your kill switch: respond `expired`/`invalid` and the plugin
disables optimizations and cache serving within 24 hours.

### POST /v1/license/deactivate

Called when the customer deactivates the site or the plugin. Release the
activation slot. Response body is ignored (best effort).

## 2. Critical CSS

### POST /v1/critical-css

Auth: `Authorization: Bearer <key>` header.

Request: `{ "url": "https://customer-site.com/some-page/", "source": "wp-plugin/1.2.0" }`

Run the URL through a headless browser, extract above-the-fold CSS, respond:

```json
{ "css": ".site-header{...} .hero{...}" }
```

Failure: `{ "error": "Reason shown in the plugin's status table" }` with non-200.
The plugin requests one URL per page type (front page, one per public post
type, blog, archive) with a 60s timeout, sequentially via WP-Cron.

## 3. Plugin updates

### POST /api/plugin/update

Auth: `Authorization: Bearer <key>` header (same Sanctum token as `/api/sites`).
Served by the main app at `app.pagespeedplus.com` (not a separate `api.*` host).

Request: `{ "site": "...", "version": "1.13.3" }`

Implemented in `PageSpeedPlus-v3` as `PluginController@update`: it reads the
latest GitHub Release of `leaderint/pagespeedplus-wordpress-plugin` (public,
cached 1h) and returns the payload below. `package` is the release's
`pagespeedplus.zip` asset URL — WordPress downloads it unauthenticated, which
works because the repo is public. Publishing a version = cutting a GitHub
Release; nothing changes server-side per release.

If a newer build exists **and the license is current**:

```json
{
  "new_version": "1.13.4",
  "package": "https://github.com/leaderint/pagespeedplus-wordpress-plugin/releases/download/v1.13.4/pagespeedplus.zip",
  "requires": "5.8",
  "tested": "6.8",
  "changelog": "<h4>1.13.4</h4><ul><li>...</li></ul>"
}
```

`package` must be a URL WordPress can GET to download the zip. Current
implementation points it at the public GitHub Release asset (no signing
needed — GPL, so this is a commercial gate, not DRM; the update *offer* is
license-gated by the Bearer auth on this endpoint). If you later move to a
private/closed download, switch `package` to a short-lived signed URL.
No update / lapsed license: return `{}`.

## Plugin-side behavior summary

- Optimization modules only boot when the stored license state is `active`
  AND the last successful validation is < 7 days old.
- The advanced-cache drop-in config mirrors license state — cached pages
  stop being served when the license lapses (config regenerated on every
  license state change).
- Explicit API rejection disables immediately; API outage degrades
  gracefully (7-day grace window, then off; settings always preserved).
- `define( 'PSP_LICENSE_DEV', true )` in wp-config bypasses validation for
  local development.
- Test hooks: filter `psp_license_endpoint`, `psp_update_endpoint`,
  `psp_ccss_endpoint` to point at a staging API.

## Suggested backend tables

- `licenses`: key (hashed), plan, site_limit, expires_at, owner account.
- `activations`: license_id, site_url (normalized), activation_id,
  activated_at, last_seen_at. Unique on (license_id, site_url) so
  re-activation from the same site never burns a slot.
