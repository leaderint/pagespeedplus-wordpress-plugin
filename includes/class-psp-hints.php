<?php
/**
 * Resource hints: dns-prefetch and preconnect for user-specified origins.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Hints {

	public function __construct() {
		if ( PSP_Options::get( 'dns_prefetch' ) || PSP_Options::get( 'preconnect' ) || PSP_Options::get( 'auto_resource_hints' ) ) {
			add_filter( 'psp_buffer', array( $this, 'inject' ), 50 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function inject( $html ) {
		$tags = '';

		foreach ( PSP_Options::get_lines( 'preconnect' ) as $origin ) {
			$origin = self::normalize_origin( $origin );
			if ( $origin && false === stripos( $html, 'preconnect" href="' . $origin ) ) {
				$tags .= '<link rel="preconnect" href="' . esc_url( $origin ) . '" crossorigin>' . "\n";
			}
		}
		foreach ( PSP_Options::get_lines( 'dns_prefetch' ) as $origin ) {
			$origin = self::normalize_origin( $origin );
			if ( $origin && false === stripos( $html, 'dns-prefetch" href="' . $origin ) ) {
				$tags .= '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">' . "\n";
			}
		}

		// Auto: dns-prefetch every external host referenced on the page (cheap;
		// unlike preconnect, which we leave manual to avoid over-connecting).
		if ( PSP_Options::get( 'auto_resource_hints' ) ) {
			$tags .= $this->auto_dns_prefetch( $html );
		}

		if ( $tags ) {
			$html = preg_replace( '/<head(\b[^>]*)?>/i', '$0' . "\n" . $tags, $html, 1 );
		}
		return $html;
	}

	/**
	 * Build dns-prefetch tags for every distinct external host referenced by a
	 * src/href in the page (skips the site's own host and ones already hinted).
	 * Capped to keep the head lean.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function auto_dns_prefetch( $html ) {
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$tags = '';
		$seen = array();
		$max  = 10;

		if ( preg_match_all( '#(?:src|href)=["\']https?://([^/"\'?]+)#i', $html, $m ) ) {
			foreach ( $m[1] as $host ) {
				$host = strtolower( $host );
				if ( '' === $host || $host === $home || isset( $seen[ $host ] ) ) {
					continue;
				}
				$seen[ $host ] = true;
				if ( false !== stripos( $html, 'dns-prefetch" href="//' . $host ) || false !== stripos( $html, 'dns-prefetch" href="https://' . $host ) ) {
					continue;
				}
				$tags .= '<link rel="dns-prefetch" href="//' . esc_attr( $host ) . '">' . "\n";
				if ( count( $seen ) >= $max ) {
					break;
				}
			}
		}
		return $tags;
	}

	/**
	 * Accept "example.com", "//example.com" or full URLs; return https origin.
	 *
	 * @param string $origin Raw user input.
	 * @return string|false
	 */
	private static function normalize_origin( $origin ) {
		$origin = trim( $origin );
		if ( '' === $origin ) {
			return false;
		}
		if ( 0 === strpos( $origin, '//' ) ) {
			$origin = 'https:' . $origin;
		} elseif ( ! preg_match( '#^https?://#i', $origin ) ) {
			$origin = 'https://' . $origin;
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		return $host ? 'https://' . $host : false;
	}
}
