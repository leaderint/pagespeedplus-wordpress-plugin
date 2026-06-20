<?php
/**
 * Centralized options access with defaults.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Options {

	const OPTION_KEY = 'pagespeedplus_settings';

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Default settings. Safe optimizations on, aggressive ones off.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Master kill switch: 0 = behave exactly as if the plugin were
			// deactivated (no optimizations, no cache serving, no .htaccess rules).
			'enabled'                => 1,

			// Cache.
			'cache_enabled'          => 1,
			'cache_lifetime'         => 36000, // 10 hours, in seconds.
			'cache_logged_in'        => 0,
			'cache_mobile_separate'  => 1,
			'cache_query_strings'    => 0, // Cache URLs with query strings (marketing params are always ignored).
			'cache_exclude_urls'     => "/cart\n/checkout\n/my-account\n/wp-login.php",
			'disable_on_urls'        => '', // Disable ALL optimizations (and caching) on these URL patterns. Supports * wildcards.
			'cache_exclude_cookies'  => "woocommerce_items_in_cart\nedd_items_in_cart",

			// HTML.
			'minify_html'            => 1,

			// CSS.
			'minify_css'             => 0,
			'combine_css'            => 0,
			'async_css'              => 0,
			'critical_css'           => '',
			'css_exclude'            => '',
			'content_visibility'     => 0,
			'content_visibility_selectors' => "footer",
			'content_visibility_size'      => 600,

			// JS.
			'minify_js'              => 0,
			'combine_js'             => 0,
			'defer_js'               => 0,
			'defer_js_exclude'       => "jquery.min.js\njquery.js",
			'delay_js'               => 0,
			'delay_js_timeout'       => 0, // 0 = load only on interaction. A timeout can fire mid-Lighthouse-trace and tank TBT.
			'delay_js_exclude'       => '',
			'prefetch_links'         => 0,
			'prefetch_exclude'       => '',
			'rum_enabled'            => 0,

			// Images: next-gen formats.
			'webp_enabled'           => 0,
			'webp_quality'           => 82,
			'avif_enabled'           => 0,

			// PageSpeedPlus account.
			'psp_api_key'            => '',

			// Media.
			'lazyload_images'        => 1,
			'lazyload_iframes'       => 1,
			'lazyload_bg'            => 0, // Lazy-load inline-style CSS background images.
			'lazyload_exclude'       => '',
			'lazyload_skip_first'    => 2, // Skip first N images (likely above the fold / LCP).
			'add_missing_dimensions' => 1,
			'youtube_facade'         => 0,
			'preload_lcp_image'      => 1,
			'lqip_enabled'           => 0, // Blurry low-quality placeholders behind lazy images.

			// Fonts.
			'font_display_swap'      => 1,
			'preconnect_fonts'       => 1,
			'self_host_fonts'        => 0,
			'preload_fonts'          => '',

			// Third-party scripts.
			'self_host_scripts'      => 0,
			'self_host_scripts_urls' => '',

			// Script manager (per-URL dequeue rules).
			'script_manager_rules'   => '',

			// Hints.
			'dns_prefetch'           => '',
			'preconnect'             => '',

			// Tweaks.
			'disable_emojis'         => 1,
			'disable_embeds'         => 0,
			'disable_dashicons'      => 0,
			'remove_query_strings'   => 0,
			'disable_xmlrpc'         => 0,
			'disable_jquery_migrate' => 0,
			'disable_block_css'      => 0,
			'disable_rest_head_link' => 0,
			'disable_rsd_wlw'        => 0,
			'disable_shortlink'      => 0,
			'disable_feed_links'     => 0,
			'disable_generator'      => 0,
			'disable_comment_reply'  => 0,
			'auto_resource_hints'    => 0,
			'heartbeat_dashboard'    => 'default', // default | slow | disable.
			'heartbeat_editor'       => 'default',
			'heartbeat_frontend'     => 'disable',

			// CDN.
			'cdn_enabled'            => 0,
			'cdn_url'                => '',
			'cdn_included_dirs'      => "wp-content\nwp-includes",
			'cdn_exclude'            => '.php',

			// Preload.
			'preload_enabled'        => 0,
			'preload_sitemap'        => '',
			'warmer_mode'            => 'local',  // local = WP-Cron crawler; pagespeedplus = off-server warm via app.pagespeedplus.com.
			'psp_site_id'            => '',       // PageSpeedPlus dashboard Site ID (for remote warming).
			'warm_scope'             => 'full',   // full = whole sitemap; monitored = your monitored URLs only.

			// Browser cache / .htaccess.
			'browser_cache'          => 1,
			'gzip_compression'       => 1,
			'brotli_cache'           => 1, // Pre-compress cached pages with Brotli (needs the PHP brotli extension).
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$settings ) {
			$saved          = get_option( self::OPTION_KEY, array() );
			self::$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$settings;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Update settings (partial merge) and reset cache.
	 *
	 * @param array $new New settings to merge in.
	 */
	public static function update( array $new ) {
		$merged = array_merge( self::all(), $new );
		update_option( self::OPTION_KEY, $merged );
		self::$settings = $merged;
	}

	/**
	 * Parse a textarea setting into a clean array of non-empty lines.
	 *
	 * @param string $key Setting key.
	 * @return array
	 */
	public static function get_lines( $key ) {
		$raw   = (string) self::get( $key, '' );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		return array_values( $lines );
	}

	/**
	 * Whether a path matches any of a list of URL patterns.
	 *
	 * Patterns are plain substrings by default (backward compatible). A pattern
	 * containing `*` is treated as a wildcard glob anchored to the whole path,
	 * e.g. `/admin*` matches `/admin`, `/admin/`, `/administrator/x`.
	 *
	 * Standalone helper (no WP funcs) so the advanced-cache drop-in can reuse it.
	 *
	 * @param string $path     Request path.
	 * @param array  $patterns Patterns to test.
	 * @return bool
	 */
	public static function path_matches( $path, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
				if ( preg_match( $regex, $path ) ) {
					return true;
				}
			} elseif ( false !== strpos( $path, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
