<?php
/**
 * HTML minification on the output buffer.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Minify_HTML {

	public function __construct() {
		if ( PSP_Options::get( 'minify_html' ) ) {
			add_filter( 'psp_buffer', array( $this, 'minify' ), 80 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function minify( $html ) {
		return PSP_Minifier::html( $html );
	}
}
