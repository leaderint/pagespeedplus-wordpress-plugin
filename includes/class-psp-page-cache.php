<?php
/**
 * Disk-based full page cache.
 *
 * Pages are written to wp-content/cache/pagespeedplus/{host}{path}/ by the
 * output buffer, and served before WordPress loads by the advanced-cache.php
 * drop-in. A standalone config file mirrors the relevant settings so the
 * drop-in can run without WordPress.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Page_Cache {

	public function __construct() {
		if ( PSP_Options::get( 'cache_enabled' ) ) {
			add_filter( 'psp_buffer', array( $this, 'maybe_cache' ), 99 );

			// Self-heal: the drop-in serves nothing without config.php, so if it
			// goes missing (deploy wiped the cache dir, manual delete, etc.)
			// regenerate it instead of silently serving zero cached pages.
			if ( ! file_exists( PSP_CACHE_DIR . 'config.php' ) ) {
				add_action( 'init', array( __CLASS__, 'write_config' ) );
			}
		}

		add_action( 'psp_garbage_collect', array( __CLASS__, 'garbage_collect' ) );
		add_action( 'update_option_' . PSP_Options::OPTION_KEY, array( __CLASS__, 'write_config' ), 10, 0 );
	}

	/**
	 * Write the page to the cache if the request is cacheable.
	 *
	 * @param string $html Final page HTML.
	 * @return string Unmodified HTML.
	 */
	public function maybe_cache( $html ) {
		if ( ! self::is_cacheable() ) {
			return $html;
		}

		$dir = self::request_cache_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return $html;
		}

		$file = $dir . '/' . self::cache_filename();

		$signature = "\n<!-- Cached by PageSpeedPlus on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->";
		$payload   = $html . $signature;

		// Atomic write: temp file then rename.
		$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
		if ( false !== file_put_contents( $tmp, $payload ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( function_exists( 'gzencode' ) ) {
				file_put_contents( $file . '.gz', gzencode( $payload, 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			// Brotli compresses ~15-20% smaller than gzip; only when enabled AND
			// the PHP brotli extension is present (drop-in serves .br to br clients).
			if ( PSP_Options::get( 'brotli_cache' ) && function_exists( 'brotli_compress' ) ) {
				file_put_contents( $file . '.br', brotli_compress( $payload, 11 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		return $html;
	}

	/**
	 * Whether the current request may be cached.
	 *
	 * @return bool
	 */
	public static function is_cacheable() {
		if ( ! PSP_Buffer::should_process() ) {
			return false;
		}
		if ( is_user_logged_in() && ! PSP_Options::get( 'cache_logged_in' ) ) {
			return false;
		}
		if ( is_404() || is_search() || post_password_required() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
			return false;
		}
		if ( http_response_code() && 200 !== http_response_code() ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Query strings: only cache when enabled, except always-ignorable marketing params.
		$query = wp_parse_url( $uri, PHP_URL_QUERY );
		if ( $query && ! PSP_Options::get( 'cache_query_strings' ) && ! self::is_ignorable_query( $query ) ) {
			return false;
		}

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( PSP_Options::path_matches( $path, PSP_Options::get_lines( 'cache_exclude_urls' ) ) ) {
			return false;
		}

		foreach ( PSP_Options::get_lines( 'cache_exclude_cookies' ) as $cookie_pattern ) {
			foreach ( array_keys( $_COOKIE ) as $name ) {
				if ( false !== strpos( $name, $cookie_pattern ) ) {
					return false;
				}
			}
		}
		// Never cache for commenters / carts identified by core cookies.
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, 'comment_author' ) || 0 === strpos( $name, 'wp_postpass' ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'psp_is_cacheable', true );
	}

	/**
	 * Whether a query string consists only of parameters that don't change page content.
	 *
	 * @param string $query Raw query string.
	 * @return bool
	 */
	private static function is_ignorable_query( $query ) {
		parse_str( $query, $params );
		$ignorable = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid', 'ref', 'mc_cid', 'mc_eid' );
		return empty( array_diff( array_keys( $params ), $ignorable ) );
	}

	/**
	 * Cache directory for the current request.
	 *
	 * @return string
	 */
	public static function request_cache_dir() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/[^a-z0-9._\-]/i', '', $_SERVER['HTTP_HOST'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = preg_replace( '/\.{2,}/', '', $path ); // No traversal.
		return rtrim( PSP_CACHE_DIR . $host . $path, '/' );
	}

	/**
	 * Cache filename for the current request (device-aware).
	 *
	 * @return string
	 */
	public static function cache_filename() {
		$mobile = PSP_Options::get( 'cache_mobile_separate' ) && wp_is_mobile();
		return $mobile ? 'index-mobile.html' : 'index.html';
	}

	/**
	 * Delete the whole page cache.
	 */
	public static function clear_all() {
		self::rrmdir( PSP_CACHE_DIR );
		wp_mkdir_p( PSP_CACHE_DIR );
		self::write_config();
		do_action( 'psp_cache_cleared' );
	}

	/**
	 * Delete the cached copies of a single URL.
	 *
	 * @param string $url Absolute URL.
	 */
	public static function clear_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return;
		}
		// Must match the write path, which keys by sanitized HTTP_HOST (host + port, colon stripped).
		$host = $parts['host'] . ( ! empty( $parts['port'] ) ? $parts['port'] : '' );
		$host = strtolower( preg_replace( '/[^a-z0-9._\-]/i', '', $host ) );
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$dir  = rtrim( PSP_CACHE_DIR . $host . $path, '/' );
		foreach ( array( 'index.html', 'index.html.gz', 'index.html.br', 'index-mobile.html', 'index-mobile.html.gz', 'index-mobile.html.br' ) as $name ) {
			$file = $dir . '/' . $name;
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Remove expired cache files (hourly cron).
	 */
	public static function garbage_collect() {
		$lifetime = (int) PSP_Options::get( 'cache_lifetime' );
		if ( $lifetime <= 0 || ! is_dir( PSP_CACHE_DIR ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( PSP_CACHE_DIR, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		$now = time();
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && ( $now - $file->getMTime() ) > $lifetime ) {
				unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Install the advanced-cache.php drop-in and try to enable WP_CACHE.
	 */
	public static function install_advanced_cache() {
		$source = PSP_DIR . 'dropins/advanced-cache.php';
		$target = WP_CONTENT_DIR . '/advanced-cache.php';

		// Don't clobber another plugin's drop-in.
		if ( file_exists( $target ) ) {
			$contents = file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === strpos( (string) $contents, 'PageSpeedPlus' ) ) {
				return false;
			}
		}

		copy( $source, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::write_config();
		self::maybe_define_wp_cache( true );
		return true;
	}

	/**
	 * Remove our drop-in and WP_CACHE constant.
	 */
	public static function uninstall_advanced_cache() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $target ) ) {
			$contents = file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== strpos( (string) $contents, 'PageSpeedPlus' ) ) {
				unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		self::maybe_define_wp_cache( false );
	}

	/**
	 * Write a standalone config file the drop-in can read without WordPress.
	 */
	public static function write_config() {
		wp_mkdir_p( PSP_CACHE_DIR );
		// The drop-in serves before WordPress loads, so license state and the
		// master kill switch must be baked into its config — a lapsed license
		// or a global "off" stops cache serving too.
		$active = ! class_exists( 'PSP_Plugin' ) || PSP_Plugin::optimizations_active();
		$config = array(
			'enabled'         => $active && PSP_Options::get( 'cache_enabled' ),
			'lifetime'        => (int) PSP_Options::get( 'cache_lifetime' ),
			'mobile_separate' => (bool) PSP_Options::get( 'cache_mobile_separate' ),
			'cache_logged_in' => (bool) PSP_Options::get( 'cache_logged_in' ),
			'exclude_cookies' => array_merge(
				array( 'comment_author', 'wp_postpass' ),
				PSP_Options::get_lines( 'cache_exclude_cookies' )
			),
			'exclude_urls'    => array_merge(
				PSP_Options::get_lines( 'cache_exclude_urls' ),
				PSP_Options::get_lines( 'disable_on_urls' )
			),
		);
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			PSP_CACHE_DIR . 'config.php',
			'<?php return ' . var_export( $config, true ) . ';' // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		);
	}

	/**
	 * Add or remove `define('WP_CACHE', true)` in wp-config.php.
	 *
	 * @param bool $enable Whether to add (true) or remove (false).
	 */
	private static function maybe_define_wp_cache( $enable ) {
		$config_path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $config_path ) && file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			$config_path = dirname( ABSPATH ) . '/wp-config.php';
		}
		if ( ! file_exists( $config_path ) || ! is_writable( $config_path ) ) {
			return;
		}

		$contents = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$marker   = "define( 'WP_CACHE', true ); // Added by PageSpeedPlus";

		if ( $enable ) {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return; // Already on, possibly defined by the user.
			}
			if ( false !== strpos( $contents, $marker ) ) {
				return;
			}
			$contents = preg_replace( '/^<\?php/', "<?php\n" . $marker, $contents, 1 );
		} else {
			if ( false === strpos( $contents, $marker ) ) {
				return;
			}
			$contents = str_replace( "\n" . $marker, '', $contents );
			$contents = str_replace( $marker, '', $contents );
		}

		file_put_contents( $config_path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
