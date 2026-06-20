<?php
/**
 * CSS & JS optimization: minify, combine, async CSS, critical CSS, defer JS.
 *
 * Operates on the final HTML buffer so it catches assets regardless of how
 * they were enqueued (or hardcoded by themes).
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Assets {

	public function __construct() {
		$any_css = PSP_Options::get( 'minify_css' ) || PSP_Options::get( 'combine_css' ) || PSP_Options::get( 'async_css' );
		$any_js  = PSP_Options::get( 'minify_js' ) || PSP_Options::get( 'combine_js' ) || PSP_Options::get( 'defer_js' );

		if ( $any_css || $any_js ) {
			add_filter( 'psp_buffer', array( $this, 'optimize' ), 20 );
		}

		if ( PSP_Options::get( 'content_visibility' ) ) {
			add_filter( 'psp_buffer', array( $this, 'content_visibility' ), 22 );
		}
	}

	/**
	 * Inject a content-visibility:auto rule for offscreen sections so the
	 * browser skips their rendering/layout until they're scrolled near,
	 * cutting initial render work. `contain-intrinsic-size` reserves space
	 * so skipped sections don't cause layout shift.
	 *
	 * Selector-driven (and off by default) because applying it blindly can
	 * break sticky elements, in-page anchors and cause CLS — the user opts
	 * specific below-the-fold containers in.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function content_visibility( $html ) {
		$selectors = PSP_Options::get_lines( 'content_visibility_selectors' );
		if ( ! $selectors ) {
			return $html;
		}
		$size = max( 0, (int) PSP_Options::get( 'content_visibility_size', 600 ) );
		$list = implode( ',', array_map( 'trim', $selectors ) );

		$css = $list . '{content-visibility:auto;contain-intrinsic-size:auto ' . $size . 'px;}';
		$style = '<style id="psp-content-visibility">' . $css . '</style>';

		return preg_replace( '/<head(\b[^>]*)?>/i', '$0' . "\n" . $style, $html, 1 );
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function optimize( $html ) {
		if ( PSP_Options::get( 'minify_css' ) || PSP_Options::get( 'combine_css' ) ) {
			$html = $this->process_stylesheets( $html );
		}
		if ( PSP_Options::get( 'async_css' ) ) {
			$html = $this->async_css( $html );
		}
		if ( PSP_Options::get( 'minify_js' ) || PSP_Options::get( 'combine_js' ) ) {
			$html = $this->process_scripts( $html );
		}
		if ( PSP_Options::get( 'defer_js' ) ) {
			$html = $this->defer_scripts( $html );
		}
		return $html;
	}

	/* ---------------------------------------------------------------- CSS */

	/**
	 * Minify and optionally combine local stylesheets.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function process_stylesheets( $html ) {
		if ( ! preg_match_all( '#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i', $html, $matches ) ) {
			return $html;
		}

		$combine  = (bool) PSP_Options::get( 'combine_css' );
		$excludes = PSP_Options::get_lines( 'css_exclude' );
		$combined_css   = '';
		$combined_tags  = array();

		foreach ( $matches[0] as $tag ) {
			$href = self::attr( $tag, 'href' );
			if ( ! $href || self::is_excluded( $href . $tag, $excludes ) ) {
				continue;
			}
			$path = self::url_to_path( $href );
			if ( ! $path ) {
				continue; // External or unresolvable.
			}
			$media = self::attr( $tag, 'media' );
			if ( $media && ! in_array( strtolower( $media ), array( 'all', 'screen' ), true ) ) {
				continue; // Leave print/media-query sheets alone.
			}

			$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $css ) {
				continue;
			}
			$css = self::rewrite_css_urls( $css, $href );
			$css = PSP_Minifier::css( $css );

			if ( $combine ) {
				$combined_css  .= "/* " . basename( $path ) . " */\n" . $css . "\n";
				$combined_tags[] = $tag;
			} else {
				$url = self::cache_asset( $css, 'css', $path );
				if ( $url ) {
					$new_tag = str_replace( $href, esc_url( $url ), $tag );
					$html    = str_replace( $tag, $new_tag, $html );
				}
			}
		}

		if ( $combine && count( $combined_tags ) > 1 ) {
			$url = self::cache_asset( $combined_css, 'css', 'combined' );
			if ( $url ) {
				$first = array_shift( $combined_tags );
				$html  = str_replace( $first, '<link rel="stylesheet" id="psp-combined-css" href="' . esc_url( $url ) . '" media="all">', $html );
				foreach ( $combined_tags as $tag ) {
					$html = str_replace( $tag, '', $html );
				}
			}
		}

		return $html;
	}

	/**
	 * Load stylesheets asynchronously and inline critical CSS.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function async_css( $html ) {
		$excludes = PSP_Options::get_lines( 'css_exclude' );

		$html = preg_replace_callback(
			'#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i',
			function ( $m ) use ( $excludes ) {
				$tag = $m[0];
				if ( self::is_excluded( $tag, $excludes ) || false !== stripos( $tag, 'onload=' ) ) {
					return $tag;
				}
				$async = preg_replace( '/\bmedia=["\'][^"\']*["\']/i', '', $tag );
				$async = str_ireplace( '<link ', '<link media="print" onload="this.media=\'all\';this.onload=null;" ', $async );
				return $async . '<noscript>' . $tag . '</noscript>';
			},
			$html
		);

		// API-generated CSS for this page type wins; manual global CSS is the fallback.
		$critical = class_exists( 'PSP_Critical_CSS' ) ? PSP_Critical_CSS::for_current_request() : '';
		if ( '' === $critical ) {
			$critical = trim( (string) PSP_Options::get( 'critical_css' ) );
		}
		if ( $critical ) {
			$html = preg_replace(
				'/<head(\b[^>]*)?>/i',
				'$0' . "\n<style id=\"psp-critical-css\">" . PSP_Minifier::css( $critical ) . '</style>',
				$html,
				1
			);
		}

		return $html;
	}

	/* ----------------------------------------------------------------- JS */

	/**
	 * Minify and optionally combine local scripts.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function process_scripts( $html ) {
		if ( ! preg_match_all( '#<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>\s*</script>#i', $html, $matches ) ) {
			return $html;
		}

		$combine  = (bool) PSP_Options::get( 'combine_js' );
		$excludes = array_merge( PSP_Options::get_lines( 'defer_js_exclude' ), PSP_Options::get_lines( 'delay_js_exclude' ) );
		$combined_js   = '';
		$combined_tags = array();

		foreach ( $matches[0] as $tag ) {
			// Only plain classic scripts are safe to touch.
			if ( preg_match( '/\b(type=["\'](?!text\/javascript)[^"\']+["\']|async|defer|nomodule)/i', $tag ) ) {
				continue;
			}
			$src = self::attr( $tag, 'src' );
			if ( ! $src || self::is_excluded( $tag, $excludes ) ) {
				continue;
			}
			$path = self::url_to_path( $src );
			if ( ! $path ) {
				continue;
			}

			$js = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $js ) {
				continue;
			}

			if ( PSP_Options::get( 'minify_js' ) && false === strpos( $path, '.min.js' ) ) {
				$js = PSP_Minifier::js( $js );
			}

			if ( $combine ) {
				$combined_js   .= "/* " . basename( $path ) . " */\n;" . $js . "\n";
				$combined_tags[] = $tag;
			} elseif ( PSP_Options::get( 'minify_js' ) && false === strpos( $path, '.min.js' ) ) {
				$url = self::cache_asset( $js, 'js', $path );
				if ( $url ) {
					$html = str_replace( $tag, str_replace( $src, esc_url( $url ), $tag ), $html );
				}
			}
		}

		if ( $combine && count( $combined_tags ) > 1 ) {
			$url = self::cache_asset( $combined_js, 'js', 'combined' );
			if ( $url ) {
				$last = array_pop( $combined_tags );
				$html = str_replace( $last, '<script id="psp-combined-js" src="' . esc_url( $url ) . '"></script>', $html );
				foreach ( $combined_tags as $tag ) {
					$html = str_replace( $tag, '', $html );
				}
			}
		}

		return $html;
	}

	/**
	 * Add `defer` to external scripts.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function defer_scripts( $html ) {
		$excludes = PSP_Options::get_lines( 'defer_js_exclude' );

		return preg_replace_callback(
			'#<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>#i',
			function ( $m ) use ( $excludes ) {
				$tag = $m[0];
				if ( preg_match( '/\b(async|defer|nomodule)\b/i', $tag ) ) {
					return $tag;
				}
				if ( preg_match( '/type=["\'](?!text\/javascript|module)[^"\']*["\']/i', $tag ) ) {
					return $tag; // JSON, templates, delayed scripts.
				}
				if ( self::is_excluded( $tag, $excludes ) ) {
					return $tag;
				}
				return str_ireplace( '<script ', '<script defer ', $tag );
			},
			$html
		);
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * Write content to the asset cache, return its URL.
	 *
	 * @param string $content Minified content.
	 * @param string $ext     File extension (css|js).
	 * @param string $source  Source identifier for the hash.
	 * @return string|false
	 */
	private static function cache_asset( $content, $ext, $source ) {
		if ( ! wp_mkdir_p( PSP_ASSET_CACHE_DIR ) ) {
			return false;
		}
		$hash = substr( md5( $content . $source ), 0, 12 );
		$name = "psp-{$hash}.{$ext}";
		$file = PSP_ASSET_CACHE_DIR . $name;
		if ( ! file_exists( $file ) ) {
			if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}
		}
		return PSP_ASSET_CACHE_URL . $name;
	}

	/**
	 * Empty the optimized asset cache.
	 */
	public static function clear_asset_cache() {
		if ( ! is_dir( PSP_ASSET_CACHE_DIR ) ) {
			return;
		}
		foreach ( (array) glob( PSP_ASSET_CACHE_DIR . 'psp-*.{css,js}', GLOB_BRACE ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Map a local URL to a filesystem path.
	 *
	 * @param string $url Asset URL.
	 * @return string|false
	 */
	public static function url_to_path( $url ) {
		$url = strtok( $url, '?#' );

		// Protocol-relative.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( $url_host && strtolower( $url_host ) !== strtolower( (string) $site_host ) ) {
			return false; // External.
		}

		$path = $url_host ? (string) wp_parse_url( $url, PHP_URL_PATH ) : $url;
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return false;
		}

		// Resolve against the WordPress install root.
		$content_path = wp_parse_url( content_url(), PHP_URL_PATH );
		if ( $content_path && 0 === strpos( $path, $content_path ) ) {
			$file = WP_CONTENT_DIR . substr( $path, strlen( $content_path ) );
		} else {
			$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
			if ( $home_path && 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
			$file = rtrim( ABSPATH, '/' ) . $path;
		}

		$file = realpath( $file );
		if ( ! $file || ! is_readable( $file ) ) {
			return false;
		}
		// Stay inside the install.
		$root = realpath( ABSPATH );
		$content_root = realpath( WP_CONTENT_DIR );
		if ( ( ! $root || 0 !== strpos( $file, $root ) ) && ( ! $content_root || 0 !== strpos( $file, $content_root ) ) ) {
			return false;
		}
		return $file;
	}

	/**
	 * Rewrite relative url() references so CSS keeps working when moved
	 * to the cache directory.
	 *
	 * @param string $css      Stylesheet contents.
	 * @param string $orig_url Original stylesheet URL.
	 * @return string
	 */
	private static function rewrite_css_urls( $css, $orig_url ) {
		$base = trailingslashit( dirname( strtok( $orig_url, '?#' ) ) );

		return preg_replace_callback(
			'/url\(\s*([\'"]?)(?![a-z]+:|\/|#|data:)([^\'")]+)\1\s*\)/i',
			function ( $m ) use ( $base ) {
				$resolved = $base . $m[2];
				// Normalize ../ segments.
				while ( preg_match( '#/[^/]+/\.\./#', $resolved ) ) {
					$resolved = preg_replace( '#/[^/]+/\.\./#', '/', $resolved, 1 );
				}
				return 'url(' . $resolved . ')';
			},
			$css
		);
	}

	/**
	 * Read an attribute value from an HTML tag string.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute name.
	 * @return string|false
	 */
	public static function attr( $tag, $name ) {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '=["\']([^"\']*)["\']/i', $tag, $m ) ) {
			return $m[1];
		}
		return false;
	}

	/**
	 * Whether a tag/URL matches any exclusion pattern.
	 *
	 * @param string $haystack Tag or URL.
	 * @param array  $patterns Substring patterns.
	 * @return bool
	 */
	public static function is_excluded( $haystack, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $haystack, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
