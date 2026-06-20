<?php
/**
 * Media optimization: lazy loading, missing dimensions, LCP priority,
 * iframe lazy loading and YouTube facades.
 *
 * Uses native loading="lazy" (no JS library needed) and skips the first
 * N images, which are almost always above the fold; the first skipped
 * image gets fetchpriority="high" and a preload hint (LCP optimization).
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Media {

	public function __construct() {
		$enabled = PSP_Options::get( 'lazyload_images' ) || PSP_Options::get( 'lazyload_iframes' )
			|| PSP_Options::get( 'add_missing_dimensions' ) || PSP_Options::get( 'youtube_facade' );

		if ( $enabled ) {
			add_filter( 'psp_buffer', array( $this, 'optimize' ), 10 );
		}

		// We handle lazy loading ourselves with LCP-aware logic.
		if ( PSP_Options::get( 'lazyload_images' ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_false' );
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function optimize( $html ) {
		if ( PSP_Options::get( 'youtube_facade' ) ) {
			$html = $this->youtube_facades( $html );
		}
		if ( PSP_Options::get( 'lazyload_images' ) || PSP_Options::get( 'add_missing_dimensions' ) ) {
			$html = $this->process_images( $html );
		}
		if ( PSP_Options::get( 'lazyload_iframes' ) ) {
			$html = $this->lazyload_iframes( $html );
		}
		return $html;
	}

	/**
	 * Lazy-load images, add missing dimensions, prioritize the LCP candidate.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function process_images( $html ) {
		$lazy       = (bool) PSP_Options::get( 'lazyload_images' );
		$dimensions = (bool) PSP_Options::get( 'add_missing_dimensions' );
		$skip       = max( 0, (int) PSP_Options::get( 'lazyload_skip_first' ) );
		$excludes   = PSP_Options::get_lines( 'lazyload_exclude' );
		$count      = 0;
		$lcp_src    = null;

		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( $lazy, $dimensions, $skip, $excludes, &$count, &$lcp_src ) {
				$tag = $m[0];
				$count++;

				if ( PSP_Assets::is_excluded( $tag, $excludes ) || false !== stripos( $tag, 'data-psp-skip' ) ) {
					return $tag;
				}

				if ( $dimensions && ( false === stripos( $tag, ' width=' ) || false === stripos( $tag, ' height=' ) ) ) {
					$tag = $this->add_dimensions( $tag );
				}

				if ( ! $lazy ) {
					return $tag;
				}

				if ( $count <= $skip ) {
					// Above the fold: load eagerly; first image is the LCP candidate —
					// but avatars/emoji are never real LCP elements.
					$src    = PSP_Assets::attr( $tag, 'src' );
					$is_lcp = $src && ! preg_match( '#gravatar\.com|/smilies/|emoji#i', $src );
					if ( 1 === $count && $is_lcp && false === stripos( $tag, 'fetchpriority' ) ) {
						$tag = str_ireplace( '<img ', '<img fetchpriority="high" ', $tag );
						if ( PSP_Options::get( 'preload_lcp_image' ) ) {
							$lcp_src = PSP_Assets::attr( $tag, 'src' );
						}
					}
					if ( false === stripos( $tag, 'loading=' ) ) {
						$tag = str_ireplace( '<img ', '<img loading="eager" ', $tag );
					}
					if ( false === stripos( $tag, 'decoding=' ) ) {
						$tag = str_ireplace( '<img ', '<img decoding="async" ', $tag );
					}
					return $tag;
				}

				if ( false === stripos( $tag, 'loading=' ) ) {
					$tag = str_ireplace( '<img ', '<img loading="lazy" decoding="async" ', $tag );
				}
				return $tag;
			},
			$html
		);

		// Preload the LCP image from <head>.
		if ( $lcp_src ) {
			$preload = '<link rel="preload" as="image" href="' . esc_url( $lcp_src ) . '" fetchpriority="high">';
			$html    = preg_replace( '/<head(\b[^>]*)?>/i', '$0' . "\n" . $preload, $html, 1 );
		}

		return $html;
	}

	/**
	 * Fill in width/height from attachment metadata or the file itself
	 * to prevent layout shift (CLS).
	 *
	 * @param string $tag IMG tag.
	 * @return string
	 */
	private function add_dimensions( $tag ) {
		$src = PSP_Assets::attr( $tag, 'src' );
		if ( ! $src ) {
			return $tag;
		}

		// WordPress-generated images embed dimensions in the filename.
		if ( preg_match( '/-(\d+)x(\d+)\.(?:jpe?g|png|gif|webp|avif)$/i', strtok( $src, '?' ), $m ) ) {
			$width  = (int) $m[1];
			$height = (int) $m[2];
		} else {
			$path = PSP_Assets::url_to_path( $src );
			if ( ! $path ) {
				return $tag;
			}
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $size ) {
				return $tag;
			}
			list( $width, $height ) = $size;
		}

		if ( false === stripos( $tag, ' width=' ) ) {
			$tag = str_ireplace( '<img ', '<img width="' . $width . '" ', $tag );
		}
		if ( false === stripos( $tag, ' height=' ) ) {
			$tag = str_ireplace( '<img ', '<img height="' . $height . '" ', $tag );
		}
		return $tag;
	}

	/**
	 * Add loading="lazy" to iframes.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function lazyload_iframes( $html ) {
		$excludes = PSP_Options::get_lines( 'lazyload_exclude' );

		return preg_replace_callback(
			'#<iframe\b[^>]*>#i',
			function ( $m ) use ( $excludes ) {
				$tag = $m[0];
				if ( false !== stripos( $tag, 'loading=' ) || PSP_Assets::is_excluded( $tag, $excludes ) ) {
					return $tag;
				}
				return str_ireplace( '<iframe ', '<iframe loading="lazy" ', $tag );
			},
			$html
		);
	}

	/**
	 * Replace YouTube embeds with a click-to-play facade (thumbnail + button).
	 * Saves ~500KB+ of third-party JS per embed until the user actually plays.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function youtube_facades( $html ) {
		$found = false;

		$html = preg_replace_callback(
			'#<iframe\b[^>]*src=["\'][^"\']*(?:youtube\.com/embed/|youtube-nocookie\.com/embed/)([a-zA-Z0-9_\-]{6,})[^"\']*["\'][^>]*></iframe>#i',
			function ( $m ) use ( &$found ) {
				$found = true;
				$id    = $m[1];
				$thumb = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
				return '<div class="psp-yt" data-psp-yt="' . esc_attr( $id ) . '" style="position:relative;cursor:pointer;background:#000 url(' . esc_url( $thumb ) . ') center/cover no-repeat;aspect-ratio:16/9;" role="button" tabindex="0" aria-label="Play video">'
					. '<span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;background:rgba(33,33,33,.8);border-radius:12px;display:flex;align-items:center;justify-content:center;">'
					. '<span style="width:0;height:0;border-style:solid;border-width:11px 0 11px 19px;border-color:transparent transparent transparent #fff;"></span>'
					. '</span></div>';
			},
			$html
		);

		if ( $found ) {
			$script = '<script id="psp-yt-facade">document.addEventListener("click",function(e){var el=e.target.closest("[data-psp-yt]");if(!el)return;var f=document.createElement("iframe");f.src="https://www.youtube.com/embed/"+el.getAttribute("data-psp-yt")+"?autoplay=1";f.allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture";f.allowFullscreen=true;f.style.cssText="position:absolute;inset:0;width:100%;height:100%;border:0;";el.innerHTML="";el.appendChild(f);});</script>';
			$html   = preg_replace( '/<\/body>/i', $script . '</body>', $html, 1 );
		}

		return $html;
	}
}
