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
			|| PSP_Options::get( 'add_missing_dimensions' ) || PSP_Options::get( 'youtube_facade' )
			|| PSP_Options::get( 'lazyload_bg' );

		if ( $enabled ) {
			add_filter( 'psp_buffer', array( $this, 'optimize' ), 10 );
		}

		// We handle lazy loading ourselves with LCP-aware logic.
		if ( PSP_Options::get( 'lazyload_images' ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_false' );
		}

		// Generate a tiny blurred placeholder (.lqip sibling) for new uploads.
		if ( PSP_Options::get( 'lqip_enabled' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_lqip_for_attachment' ), 10, 2 );
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
		if ( PSP_Options::get( 'lazyload_bg' ) ) {
			$html = $this->lazyload_bg_images( $html );
		}
		return $html;
	}

	/**
	 * Lazy-load inline-style CSS background images: move the background-image URL
	 * to a data attribute and swap it in via IntersectionObserver near the
	 * viewport. Only handles the explicit `background-image: url(...)` inline
	 * property (not the `background:` shorthand or CSS-file backgrounds).
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private function lazyload_bg_images( $html ) {
		$excludes = PSP_Options::get_lines( 'lazyload_exclude' );

		$html = preg_replace_callback(
			'#<([a-z][a-z0-9]*)\b([^>]*?)\bstyle=("|\')(.*?)\3([^>]*)>#i',
			function ( $m ) use ( $excludes ) {
				$style = $m[4];
				if ( false === stripos( $style, 'background-image' ) || false === stripos( $style, 'url(' ) ) {
					return $m[0];
				}
				if ( PSP_Assets::is_excluded( $m[0], $excludes ) || false !== stripos( $m[0], 'data-psp-skip' ) || false !== stripos( $m[0], 'data-psp-bg' ) ) {
					return $m[0];
				}
				if ( ! preg_match( '#background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#i', $style, $u ) ) {
					return $m[0];
				}
				$url       = trim( $u[1] );
				$new_style = trim( preg_replace( '#background-image\s*:\s*url\([^)]*\)\s*;?#i', '', $style ) );
				$style_attr = '' !== $new_style ? ' style=' . $m[3] . $new_style . $m[3] : '';
				return '<' . $m[1] . ' data-psp-bg="' . esc_url( $url ) . '"' . $m[2] . $style_attr . $m[5] . '>';
			},
			$html
		);

		if ( false !== strpos( $html, 'data-psp-bg' ) ) {
			$script = '<script id="psp-lazy-bg">(function(){var e=document.querySelectorAll("[data-psp-bg]");if(!e.length||!("IntersectionObserver" in window))return;var o=new IntersectionObserver(function(es,ob){es.forEach(function(en){if(en.isIntersecting){var el=en.target;el.style.backgroundImage="url(\'"+el.getAttribute("data-psp-bg")+"\')";el.removeAttribute("data-psp-bg");ob.unobserve(el);}});},{rootMargin:"200px"});e.forEach(function(el){o.observe(el);});})();</script>';
			$html   = preg_replace( '/<\/body>/i', $script . '</body>', $html, 1 );
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

				// LQIP: show a blurry placeholder behind the image while it loads.
				// Only when the image has no inline style of its own (avoid clobbering).
				if ( PSP_Options::get( 'lqip_enabled' ) && false === stripos( $tag, ' style=' ) ) {
					$uri = $this->lqip_uri( PSP_Assets::attr( $tag, 'src' ) );
					if ( $uri ) {
						$tag = str_ireplace( '<img ', '<img style="background-image:url(' . $uri . ');background-size:cover;background-position:center;" ', $tag );
					}
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

	/* ----------------------------------------------------------------- LQIP */

	/**
	 * Read the cached LQIP data-URI for an image URL, if one exists.
	 *
	 * @param string $src Image URL.
	 * @return string Data URI, or '' if none.
	 */
	private function lqip_uri( $src ) {
		if ( ! $src ) {
			return '';
		}
		$path = PSP_Assets::url_to_path( strtok( $src, '?' ) );
		if ( ! $path || ! is_readable( $path . '.lqip' ) ) {
			return '';
		}
		$uri = trim( (string) file_get_contents( $path . '.lqip' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 0 === strpos( $uri, 'data:image/' ) ? $uri : '';
	}

	/**
	 * Generate .lqip siblings for an uploaded attachment.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function generate_lqip_for_attachment( $metadata, $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( $file && preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			self::generate_lqip( $file );
		}
		return $metadata;
	}

	/**
	 * Create a tiny (~20px) blurred JPEG placeholder for an image and store it as
	 * a base64 data-URI in a "<file>.lqip" sibling. Uses GD; safe no-op without it.
	 *
	 * @param string $file Absolute image path.
	 * @return bool Success.
	 */
	public static function generate_lqip( $file ) {
		$dest = $file . '.lqip';
		if ( file_exists( $dest ) ) {
			return true;
		}
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return false;
		}
		$data = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		if ( ! $data ) {
			return false;
		}
		$img = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $img ) {
			return false;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		if ( $w < 1 || $h < 1 ) {
			imagedestroy( $img );
			return false;
		}
		$tw   = 20;
		$th   = max( 1, (int) round( $h * ( $tw / $w ) ) );
		$tiny = imagecreatetruecolor( $tw, $th );
		imagecopyresampled( $tiny, $img, 0, 0, 0, 0, $tw, $th, $w, $h );

		ob_start();
		imagejpeg( $tiny, null, 40 );
		$bytes = ob_get_clean();

		imagedestroy( $img );
		imagedestroy( $tiny );

		if ( ! $bytes ) {
			return false;
		}
		$uri = 'data:image/jpeg;base64,' . base64_encode( $bytes );
		return false !== file_put_contents( $dest, $uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
