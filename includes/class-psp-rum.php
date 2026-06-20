<?php
/**
 * Real User Monitoring (Core Web Vitals beacon).
 *
 * Injects the PageSpeedPlus web-vitals collector snippet on every front-end
 * page so real-visitor LCP/CLS/INP/TTFB are reported to the SaaS. The only
 * per-site value is the connected site_id; everything downstream (collection,
 * processing, dashboards) is managed server-side by PageSpeedPlus.
 *
 * Injected at buffer priority 60 — after the delay-JS pass (30) — so the
 * beacon is never neutralized, and it's baked into cached HTML like any other
 * markup, so every visitor (cache hit or miss) reports.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_RUM {

	public function __construct() {
		if ( PSP_Options::get( 'rum_enabled' ) ) {
			add_filter( 'psp_buffer', array( $this, 'inject' ), 60 );
		}
	}

	/**
	 * Whether RUM can actually run (enabled + a site is connected).
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return (bool) PSP_Options::get( 'rum_enabled' ) && (int) PSP_Options::get( 'psp_site_id' ) > 0;
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function inject( $html ) {
		$site_id = (int) PSP_Options::get( 'psp_site_id' );
		if ( $site_id <= 0 ) {
			return $html; // No connected site → nothing to attribute the data to.
		}

		$tag = $this->snippet( $site_id );

		if ( false !== stripos( $html, '</head>' ) ) {
			return preg_replace( '/<\/head>/i', $tag . '</head>', $html, 1 );
		}
		return $tag . $html;
	}

	/**
	 * The collector snippet, verbatim from PageSpeedPlus, with this install's
	 * connected site_id substituted in.
	 *
	 * @param int $site_id Connected PageSpeedPlus site id.
	 * @return string
	 */
	private function snippet( $site_id ) {
		$js = <<<'JS'
function generatePageviewID() {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let id = "";
  for (let i = 0; i < 20; i++) {
    id += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return id;
}

const pageviewID = generatePageviewID();
function sendToAnalytics(metric) {
  if(metric.navigationType != "back-forward-cache") {
  const data = {
    ...metric,
    page_path: window.location.pathname,
    user_agent: navigator.userAgent,
    pageview_id: pageviewID,
    site_id: __PSP_SITE_ID__
  };

  const body = JSON.stringify(data);

  (navigator.sendBeacon && navigator.sendBeacon("https://rum-collector.leaderint.workers.dev", body)) || fetch("https://rum-collector.leaderint.workers.dev", { body, method: "POST", keepalive: true });
  }
}

(function () {
  var script = document.createElement("script");
  script.src = "https://web-vitals-script.leaderint.workers.dev";
  script.onload = function () {
    webVitals.onCLS(sendToAnalytics);
    webVitals.onINP(sendToAnalytics);
    webVitals.onLCP(sendToAnalytics);
    webVitals.onTTFB(sendToAnalytics);
  };
  document.head.appendChild(script);
})();
JS;
		$js = str_replace( '__PSP_SITE_ID__', (int) $site_id, $js );

		return '<script id="psp-rum" defer>' . $js . '</script>';
	}
}
