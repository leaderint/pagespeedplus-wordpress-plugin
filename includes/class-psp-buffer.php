<?php
/**
 * Single output buffer for the frontend. All HTML-rewriting modules
 * register callbacks on the `psp_buffer` filter; the buffer runs once
 * and each module transforms the final HTML in priority order.
 *
 * Priority convention:
 *  10 Media (lazyload, dimensions, facades)
 *  20 Assets (CSS/JS minify, combine, defer)
 *  30 Delay JS
 *  40 Fonts
 *  50 Hints / CDN
 *  80 HTML minify
 *  99 Page cache write
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Buffer {

	/**
	 * @var PSP_Buffer|null
	 */
	private static $instance = null;

	/**
	 * @var bool
	 */
	private $started = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Start late enough that conditional tags work, early enough to wrap the template.
		add_action( 'template_redirect', array( $this, 'start' ), -PHP_INT_MAX );
	}

	public function start() {
		if ( $this->started || ! self::should_process() ) {
			return;
		}
		$this->started = true;
		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Run the buffer through all registered transformations.
	 *
	 * @param string $html Buffered output.
	 * @return string
	 */
	public function process( $html ) {
		// Only touch real HTML documents — skip feeds, JSON, partial output.
		if ( strlen( $html ) < 255 || ! preg_match( '/<\/html>/i', $html ) ) {
			return $html;
		}

		$processed = apply_filters( 'psp_buffer', $html );

		return is_string( $processed ) && '' !== $processed ? $processed : $html;
	}

	/**
	 * Whether the current request is eligible for HTML processing.
	 *
	 * @return bool
	 */
	public static function should_process() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( isset( $_GET['psp_nooptimize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		// Per-URL rule: leave matching pages completely untouched (no optimizations, no cache).
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $path && PSP_Options::path_matches( $path, PSP_Options::get_lines( 'disable_on_urls' ) ) ) {
			return false;
		}
		// Page builders and previews.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ) || is_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		return true;
	}
}
