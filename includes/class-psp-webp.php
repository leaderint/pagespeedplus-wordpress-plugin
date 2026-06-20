<?php
/**
 * WebP / AVIF image conversion and delivery.
 *
 * Generation: .webp (and optionally .avif) siblings are created next to the
 * original file using the "file.jpg.webp" naming convention (collision-proof,
 * same as EWWW/ShortPixel). New uploads convert automatically; existing media
 * converts in WP-Cron batches.
 *
 * Delivery: on Apache/LiteSpeed, .htaccess rules content-negotiate via the
 * Accept header with `Vary: Accept` — this is transparent to the page cache.
 * On other servers we rewrite image URLs in the HTML buffer to .webp
 * (universal browser support).
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_WebP {

	const QUEUE_OPTION = 'psp_webp_queue';
	const BATCH_SIZE   = 10;

	public function __construct() {
		// Bail unless WebP is both enabled AND the server can actually do it —
		// an option left on after a server migration must not act.
		if ( ! PSP_Options::get( 'webp_enabled' ) || ! self::support()['webp'] ) {
			return;
		}

		// Convert new uploads.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_attachment' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_siblings' ) );

		// Bulk conversion batches.
		add_action( 'psp_webp_batch', array( __CLASS__, 'process_batch' ) );

		// HTML fallback delivery for non-Apache servers.
		if ( self::needs_html_rewrite() ) {
			add_filter( 'psp_buffer', array( $this, 'rewrite_html' ), 15 );
		}
	}

	/**
	 * Whether we must rewrite URLs in HTML (no .htaccess negotiation available).
	 *
	 * @return bool
	 */
	public static function needs_html_rewrite() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$apache = false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
		return ! $apache;
	}

	/**
	 * Available conversion engines per format.
	 *
	 * @return array  e.g. [ 'webp' => 'imagick'|'gd'|false, 'avif' => ... ]
	 */
	public static function support() {
		$support = array( 'webp' => false, 'avif' => false );

		if ( class_exists( 'Imagick' ) ) {
			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );
			if ( in_array( 'WEBP', $formats, true ) ) {
				$support['webp'] = 'imagick';
			}
			if ( in_array( 'AVIF', $formats, true ) ) {
				$support['avif'] = 'imagick';
			}
		}
		if ( ! $support['webp'] && function_exists( 'imagewebp' ) ) {
			$support['webp'] = 'gd';
		}
		if ( ! $support['avif'] && function_exists( 'imageavif' ) ) {
			$support['avif'] = 'gd';
		}
		// Allow hosts/users to force-disable a flaky engine.
		return apply_filters( 'psp_webp_support', $support );
	}

	/* ----------------------------------------------------------- Generate */

	/**
	 * Convert an attachment (original + all sizes) on upload.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function convert_attachment( $metadata, $attachment_id ) {
		foreach ( self::attachment_files( $attachment_id, $metadata ) as $file ) {
			self::convert_file( $file );
		}
		return $metadata;
	}

	/**
	 * All files belonging to an attachment (original + sizes).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata (loaded if null).
	 * @return array Absolute paths.
	 */
	private static function attachment_files( $attachment_id, $metadata = null ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return array();
		}
		$files = array( $file );

		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		if ( ! empty( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		return array_filter( $files, 'file_exists' );
	}

	/**
	 * Create .webp (and optionally .avif) siblings for one image file.
	 *
	 * @param string $file Absolute path to a jpg/png.
	 * @return bool Whether at least one sibling now exists.
	 */
	public static function convert_file( $file ) {
		$support = self::support();
		$quality = max( 1, min( 100, (int) PSP_Options::get( 'webp_quality', 82 ) ) );
		$done    = false;

		$targets = array( 'webp' );
		if ( PSP_Options::get( 'avif_enabled' ) ) {
			$targets[] = 'avif';
		}

		foreach ( $targets as $format ) {
			$dest = $file . '.' . $format;
			if ( file_exists( $dest ) ) {
				$done = true;
				continue;
			}
			if ( ! $support[ $format ] ) {
				continue;
			}
			if ( 'imagick' === $support[ $format ] ) {
				$done = self::convert_imagick( $file, $dest, $format, $quality ) || $done;
			} else {
				$done = self::convert_gd( $file, $dest, $format, $quality ) || $done;
			}
		}
		return $done;
	}

	private static function convert_imagick( $file, $dest, $format, $quality ) {
		try {
			$image = new Imagick( $file );
			$image->setImageFormat( $format );
			$image->setImageCompressionQuality( $quality );
			$result = $image->writeImage( $dest );
			$image->destroy();
			// Don't ship a "compressed" file bigger than the original.
			if ( $result && filesize( $dest ) >= filesize( $file ) ) {
				unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}
			return (bool) $result;
		} catch ( Exception $e ) {
			return false;
		}
	}

	private static function convert_gd( $file, $dest, $format, $quality ) {
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$image = @imagecreatefromjpeg( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_PNG:
				$image = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $image ) {
					imagepalettetotruecolor( $image );
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
				break;
			default:
				return false;
		}
		if ( ! $image ) {
			return false;
		}
		$ok = 'avif' === $format ? imageavif( $image, $dest, $quality ) : imagewebp( $image, $dest, $quality );
		imagedestroy( $image );
		if ( $ok && filesize( $dest ) >= filesize( $file ) ) {
			unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		return (bool) $ok;
	}

	/**
	 * Remove converted siblings when an attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_siblings( $attachment_id ) {
		foreach ( self::attachment_files( $attachment_id ) as $file ) {
			foreach ( array( '.webp', '.avif' ) as $ext ) {
				if ( file_exists( $file . $ext ) ) {
					unlink( $file . $ext ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
	}

	/* --------------------------------------------------------------- Bulk */

	/**
	 * Queue every unconverted image attachment — plus theme images, which are
	 * often the LCP element (hero images) — and start batch processing.
	 *
	 * @return int Queued count.
	 */
	public static function start_bulk() {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$queue = array_map( 'intval', $ids );

		// Theme (and child theme) images, queued as "file:<path>" entries.
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $theme_dir ) {
			foreach ( self::find_images( $theme_dir ) as $file ) {
				$queue[] = 'file:' . $file;
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( $queue && ! wp_next_scheduled( 'psp_webp_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'psp_webp_batch' );
		}
		return count( $queue );
	}

	/**
	 * Recursively find jpg/png files under a directory.
	 *
	 * @param string $dir Directory.
	 * @return array Absolute paths.
	 */
	private static function find_images( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$found    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( '/\.(jpe?g|png)$/i', $file->getFilename() ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	/**
	 * Convert one batch from the queue, then reschedule.
	 */
	public static function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $item ) {
			if ( is_string( $item ) && 0 === strpos( $item, 'file:' ) ) {
				$file = substr( $item, 5 );
				// Only convert files inside wp-content (queue entries are trusted,
				// but cheap to enforce).
				if ( file_exists( $file ) && 0 === strpos( realpath( $file ), realpath( WP_CONTENT_DIR ) ) ) {
					self::convert_file( $file );
				}
				continue;
			}
			foreach ( self::attachment_files( (int) $item ) as $file ) {
				self::convert_file( $file );
			}
		}

		if ( $queue ) {
			wp_schedule_single_event( time() + 15, 'psp_webp_batch' );
		} else {
			delete_option( self::QUEUE_OPTION );
		}
	}

	/**
	 * @return int Attachments still queued for conversion.
	 */
	public static function pending_count() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}

	/* ------------------------------------------------------------ Deliver */

	/**
	 * Rewrite local jpg/png URLs to their .webp sibling in src, srcset and
	 * inline background styles. Only used when .htaccess negotiation isn't
	 * available; WebP browser support is universal.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function rewrite_html( $html ) {
		// Cover all of wp-content (uploads, themes, plugins) — hero images
		// frequently live in the theme, and they're usually the LCP element.
		$base_url = content_url();
		$base_dir = WP_CONTENT_DIR;
		$checked  = array();

		return preg_replace_callback(
			'#' . preg_quote( $base_url, '#' ) . '/[^\s"\'>()?,]+\.(?:jpe?g|png)#i',
			function ( $m ) use ( $base_url, $base_dir, &$checked ) {
				$url = $m[0];
				if ( ! isset( $checked[ $url ] ) ) {
					$file            = $base_dir . rawurldecode( substr( $url, strlen( $base_url ) ) );
					$checked[ $url ] = false === strpos( $file, '..' ) && file_exists( $file . '.webp' );
				}
				return $checked[ $url ] ? $url . '.webp' : $url;
			},
			$html
		);
	}

	/**
	 * .htaccess content-negotiation rules (used by PSP_Htaccess).
	 *
	 * @return string
	 */
	public static function htaccess_rules() {
		return <<<RULES
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_ACCEPT} image/avif
RewriteCond %{REQUEST_FILENAME}.avif -f
RewriteRule ^(.+)\\.(jpe?g|png)$ \$0.avif [T=image/avif,E=PSPIMG:1,L]
RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^(.+)\\.(jpe?g|png)$ \$0.webp [T=image/webp,E=PSPIMG:1,L]
</IfModule>
<IfModule mod_headers.c>
Header append Vary Accept env=PSPIMG
</IfModule>
<IfModule mod_mime.c>
AddType image/webp .webp
AddType image/avif .avif
</IfModule>

RULES;
	}
}
