<?php
/**
 * CDN URL rewriting on the final HTML buffer.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_CDN {

	public function __construct() {
		if ( PSP_Options::get( 'cdn_enabled' ) && PSP_Options::get( 'cdn_url' ) ) {
			add_filter( 'psp_buffer', array( $this, 'rewrite' ), 55 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function rewrite( $html ) {
		$cdn_url = rtrim( (string) PSP_Options::get( 'cdn_url' ), '/' );
		if ( ! preg_match( '#^https?://#i', $cdn_url ) ) {
			$cdn_url = 'https://' . $cdn_url;
		}

		$site_url  = home_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$dirs      = PSP_Options::get_lines( 'cdn_included_dirs' );
		$excludes  = PSP_Options::get_lines( 'cdn_exclude' );

		if ( ! $site_host || ! $dirs ) {
			return $html;
		}

		$dir_pattern = implode( '|', array_map( function ( $d ) {
			return preg_quote( trim( $d, '/' ), '#' );
		}, $dirs ) );

		// Match src/href/srcset URLs pointing at the included directories.
		$pattern = '#(?:https?:)?//' . preg_quote( $site_host, '#' ) . '(/(?:' . $dir_pattern . ')/[^\s"\'>)]+)#i';

		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $cdn_url, $excludes ) {
				if ( PSP_Assets::is_excluded( $m[1], $excludes ) ) {
					return $m[0];
				}
				return $cdn_url . $m[1];
			},
			$html
		);
	}
}
