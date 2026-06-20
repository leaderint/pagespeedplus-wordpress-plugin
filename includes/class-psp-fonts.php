<?php
/**
 * Font optimization: font-display swap for Google Fonts, preconnect,
 * custom font preloading, and self-hosting of Google Fonts.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Fonts {

	/** Registry: google-css-url-hash => array( css => filename, updated => ts ). */
	const REGISTRY_OPTION = 'psp_self_hosted_fonts';
	/** Pending google-css URLs awaiting background download: hash => url. */
	const QUEUE_OPTION = 'psp_fonts_queue';

	public function __construct() {
		if ( PSP_Options::get( 'self_host_fonts' ) ) {
			add_action( 'psp_fonts_fetch', array( __CLASS__, 'process_queue' ) );
			// Re-fetch if the theme changes (font stacks usually change with it).
			add_action( 'switch_theme', array( __CLASS__, 'flush' ) );
		}

		if ( PSP_Options::get( 'font_display_swap' ) || PSP_Options::get( 'preconnect_fonts' ) || PSP_Options::get( 'preload_fonts' ) || PSP_Options::get( 'self_host_fonts' ) ) {
			add_filter( 'psp_buffer', array( $this, 'optimize' ), 40 );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function optimize( $html ) {
		if ( PSP_Options::get( 'font_display_swap' ) ) {
			// Add display=swap to Google Fonts requests that lack it.
			$html = preg_replace_callback(
				'#<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com/css[^"\']*)["\'][^>]*>#i',
				function ( $m ) {
					if ( false !== strpos( $m[1], 'display=' ) ) {
						return $m[0];
					}
					$new_href = $m[1] . ( false !== strpos( $m[1], '?' ) ? '&' : '?' ) . 'display=swap';
					return str_replace( $m[1], $new_href, $m[0] );
				},
				$html
			);
		}

		// Self-host: swap the Google stylesheet for a local copy once it's cached.
		// Runs after display=swap so the swap is baked into the downloaded CSS.
		if ( PSP_Options::get( 'self_host_fonts' ) ) {
			$html = $this->self_host( $html );
		}

		$head_inject = '';

		// Preconnect only still makes sense if a Google Fonts request remains
		// (i.e. self-hosting is off or hasn't cached this page's fonts yet).
		if ( PSP_Options::get( 'preconnect_fonts' ) && false !== stripos( $html, 'fonts.googleapis.com' ) ) {
			if ( false === stripos( $html, 'rel="preconnect" href="https://fonts.gstatic.com"' ) ) {
				$head_inject .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
				$head_inject .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
			}
		}

		foreach ( PSP_Options::get_lines( 'preload_fonts' ) as $font_url ) {
			$type = 'font/' . strtolower( pathinfo( strtok( $font_url, '?' ), PATHINFO_EXTENSION ) );
			$head_inject .= '<link rel="preload" as="font" type="' . esc_attr( $type ) . '" href="' . esc_url( $font_url ) . '" crossorigin>' . "\n";
		}

		if ( $head_inject ) {
			$html = preg_replace( '/<head(\b[^>]*)?>/i', '$0' . "\n" . $head_inject, $html, 1 );
		}

		return $html;
	}

	/* ------------------------------------------------------ Self-hosting */

	/**
	 * Replace Google Fonts <link> tags with a locally-served stylesheet when a
	 * cached copy exists; otherwise queue the URL for background download and
	 * leave the original tag in place (so the page never blocks on our fetch).
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function self_host( $html ) {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		$registry = is_array( $registry ) ? $registry : array();
		$queue    = array();

		$html = preg_replace_callback(
			'#<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com/css[^"\']*)["\'][^>]*>#i',
			function ( $m ) use ( $registry, &$queue ) {
				$href = html_entity_decode( $m[1] ); // hrefs are HTML-encoded (&amp;).
				$key  = md5( $href );

				if ( ! empty( $registry[ $key ]['css'] ) && file_exists( PSP_FONTS_CACHE_DIR . $registry[ $key ]['css'] ) ) {
					$url = PSP_FONTS_CACHE_URL . $registry[ $key ]['css'];
					return '<link rel="stylesheet" id="psp-selfhost-fonts" href="' . esc_url( $url ) . '" media="all">';
				}

				$queue[ $key ] = $href; // Not cached yet — fetch in the background.
				return $m[0];
			},
			$html
		);

		if ( $queue ) {
			$existing = get_option( self::QUEUE_OPTION, array() );
			$existing = is_array( $existing ) ? $existing : array();
			update_option( self::QUEUE_OPTION, $queue + $existing, false );
			if ( ! wp_next_scheduled( 'psp_fonts_fetch' ) ) {
				wp_schedule_single_event( time() + 5, 'psp_fonts_fetch' );
			}
		}

		return $html;
	}

	/**
	 * Background worker: download each queued Google Fonts stylesheet and its
	 * font files, rewrite the CSS to point at local copies, and register it.
	 */
	public static function process_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		$registry = get_option( self::REGISTRY_OPTION, array() );
		$registry = is_array( $registry ) ? $registry : array();

		wp_mkdir_p( PSP_FONTS_CACHE_DIR );

		foreach ( $queue as $key => $href ) {
			if ( self::fetch_and_localize( $href, $key ) ) {
				$registry[ $key ] = array( 'css' => $key . '.css', 'updated' => time() );
			}
			unset( $queue[ $key ] );
		}

		update_option( self::REGISTRY_OPTION, $registry, false );
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Fetch one Google Fonts CSS file, download the woff2/woff/ttf files it
	 * references, rewrite url() to local paths, and store the local CSS.
	 *
	 * @param string $href Google Fonts stylesheet URL.
	 * @param string $key  Cache key (md5 of the URL).
	 * @return bool Success.
	 */
	private static function fetch_and_localize( $href, $key ) {
		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		}
		if ( ! preg_match( '#^https?://fonts\.googleapis\.com/#i', $href ) ) {
			return false;
		}

		// A modern desktop UA makes Google return woff2 @font-face rules.
		$response = wp_remote_get( $href, array(
			'timeout' => 20,
			'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$css = wp_remote_retrieve_body( $response );
		if ( '' === trim( $css ) ) {
			return false;
		}

		// Download each referenced font file and rewrite the url() to local.
		$css = preg_replace_callback(
			'#url\(\s*([\'"]?)(https://fonts\.gstatic\.com/[^\'")]+)\1\s*\)#i',
			function ( $m ) use ( $key ) {
				$remote = $m[2];
				$ext    = strtolower( (string) pathinfo( (string) wp_parse_url( $remote, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$ext    = preg_replace( '/[^a-z0-9]/', '', $ext );
				$ext    = $ext ? $ext : 'woff2';
				$name   = $key . '-' . md5( $remote ) . '.' . $ext;
				$path   = PSP_FONTS_CACHE_DIR . $name;

				if ( ! file_exists( $path ) ) {
					$font = wp_remote_get( $remote, array( 'timeout' => 20 ) );
					if ( is_wp_error( $font ) || 200 !== (int) wp_remote_retrieve_response_code( $font ) ) {
						return $m[0]; // Leave the remote URL if the download fails.
					}
					$body = wp_remote_retrieve_body( $font );
					if ( '' === $body || false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						return $m[0];
					}
				}
				return 'url(' . PSP_FONTS_CACHE_URL . $name . ')';
			},
			$css
		);

		return false !== file_put_contents( PSP_FONTS_CACHE_DIR . $key . '.css', $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Number of Google Fonts stylesheets currently self-hosted.
	 *
	 * @return int
	 */
	public static function hosted_count() {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		return is_array( $registry ) ? count( $registry ) : 0;
	}

	/**
	 * Clear all downloaded fonts + registry (e.g. on theme switch).
	 */
	public static function flush() {
		update_option( self::REGISTRY_OPTION, array(), false );
		update_option( self::QUEUE_OPTION, array(), false );
		if ( is_dir( PSP_FONTS_CACHE_DIR ) ) {
			foreach ( (array) glob( PSP_FONTS_CACHE_DIR . '*' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				}
			}
		}
	}
}
