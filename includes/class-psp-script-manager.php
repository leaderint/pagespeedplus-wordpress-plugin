<?php
/**
 * Script Manager: dequeue specific scripts/styles by handle, optionally only on
 * matching URLs. Lets you strip assets a plugin loads site-wide but that you
 * only need on some pages (e.g. a contact-form or slider script).
 *
 * Rules (one per line), in the script_manager_rules setting:
 *   contact-form-7            → dequeue everywhere
 *   wp-block-library | /shop* → dequeue only on matching URL paths (* wildcard)
 *
 * Each rule dequeues both the script and the style registered under that handle.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Script_Manager {

	public function __construct() {
		if ( PSP_Options::get_lines( 'script_manager_rules' ) ) {
			// Late, so it runs after themes/plugins have enqueued their assets.
			add_action( 'wp_enqueue_scripts', array( $this, 'apply' ), 9999 );
		}
	}

	public function apply() {
		if ( is_admin() ) {
			return;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( PSP_Options::get_lines( 'script_manager_rules' ) as $rule ) {
			$parts   = array_map( 'trim', explode( '|', $rule, 2 ) );
			$handle  = $parts[0];
			$pattern = isset( $parts[1] ) ? $parts[1] : '';

			if ( '' === $handle ) {
				continue;
			}
			if ( '' !== $pattern && ! self::path_matches( $path, $pattern ) ) {
				continue;
			}

			wp_dequeue_script( $handle );
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Substring match, or glob match when the pattern contains "*".
	 *
	 * @param string $path    Current URL path.
	 * @param string $pattern Rule pattern.
	 * @return bool
	 */
	private static function path_matches( $path, $pattern ) {
		if ( false !== strpos( $pattern, '*' ) ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			return (bool) preg_match( $regex, $path );
		}
		return false !== stripos( $path, $pattern );
	}
}
