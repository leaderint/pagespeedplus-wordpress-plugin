<?php
/**
 * Self-host third-party scripts (e.g. Google Analytics / gtag, Tag Manager).
 *
 * Downloads the listed external scripts to this server and rewrites their
 * <script src> to the local copy — removing the third-party request/DNS and the
 * "serve resources from your origin" / cache-lifetime Lighthouse hits. Files are
 * fetched in the background and refreshed periodically (these scripts change).
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Scripts {

	const REGISTRY_OPTION = 'psp_self_hosted_scripts';
	const QUEUE_OPTION    = 'psp_scripts_queue';
	const REFRESH_TTL     = 43200; // 12h — re-download to pick up upstream changes.

	public function __construct() {
		if ( ! PSP_Options::get( 'self_host_scripts' ) ) {
			return;
		}
		add_action( 'psp_scripts_fetch', array( __CLASS__, 'process_queue' ) );
		add_filter( 'psp_buffer', array( $this, 'optimize' ), 16 );
	}

	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	public function optimize( $html ) {
		$patterns = PSP_Options::get_lines( 'self_host_scripts_urls' );
		if ( ! $patterns ) {
			return $html;
		}

		$registry = get_option( self::REGISTRY_OPTION, array() );
		$registry = is_array( $registry ) ? $registry : array();
		$queue    = array();

		$html = preg_replace_callback(
			'#<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*></script>#i',
			function ( $m ) use ( $patterns, $registry, &$queue ) {
				$src = html_entity_decode( $m[1] );

				// Only touch scripts whose URL matches a configured pattern.
				$matched = false;
				foreach ( $patterns as $p ) {
					if ( '' !== $p && false !== strpos( $src, $p ) ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					return $m[0];
				}

				$key = md5( $src );
				if ( ! empty( $registry[ $key ]['file'] ) && file_exists( PSP_SCRIPTS_CACHE_DIR . $registry[ $key ]['file'] ) ) {
					// Refresh in the background if the local copy is stale.
					if ( ( time() - (int) ( $registry[ $key ]['updated'] ?? 0 ) ) > self::REFRESH_TTL ) {
						$queue[ $key ] = $src;
					}
					$local = PSP_SCRIPTS_CACHE_URL . $registry[ $key ]['file'];
					return str_replace( $m[1], esc_url( $local ), $m[0] );
				}

				$queue[ $key ] = $src; // Not cached yet — fetch in the background, leave original this load.
				return $m[0];
			},
			$html
		);

		if ( $queue ) {
			$existing = get_option( self::QUEUE_OPTION, array() );
			$existing = is_array( $existing ) ? $existing : array();
			update_option( self::QUEUE_OPTION, $queue + $existing, false );
			if ( ! wp_next_scheduled( 'psp_scripts_fetch' ) ) {
				wp_schedule_single_event( time() + 5, 'psp_scripts_fetch' );
			}
		}

		return $html;
	}

	/**
	 * Background worker: download each queued external script to a local file.
	 */
	public static function process_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		$registry = get_option( self::REGISTRY_OPTION, array() );
		$registry = is_array( $registry ) ? $registry : array();

		wp_mkdir_p( PSP_SCRIPTS_CACHE_DIR );

		foreach ( $queue as $key => $url ) {
			$file = self::download( $url, $key );
			if ( $file ) {
				$registry[ $key ] = array( 'file' => $file, 'updated' => time() );
			}
			unset( $queue[ $key ] );
		}

		update_option( self::REGISTRY_OPTION, $registry, false );
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Fetch one external script to PSP_SCRIPTS_CACHE_DIR.
	 *
	 * @param string $url Remote script URL.
	 * @param string $key Cache key.
	 * @return string|false Local filename on success.
	 */
	private static function download( $url, $key ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return false;
		}

		$file = $key . '.js';
		if ( false === file_put_contents( PSP_SCRIPTS_CACHE_DIR . $file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		return $file;
	}

	/**
	 * @return int Number of scripts currently self-hosted.
	 */
	public static function hosted_count() {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		return is_array( $registry ) ? count( $registry ) : 0;
	}

	/**
	 * Clear all downloaded scripts + registry.
	 */
	public static function flush() {
		update_option( self::REGISTRY_OPTION, array(), false );
		update_option( self::QUEUE_OPTION, array(), false );
		if ( is_dir( PSP_SCRIPTS_CACHE_DIR ) ) {
			foreach ( (array) glob( PSP_SCRIPTS_CACHE_DIR . '*' ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				}
			}
		}
	}
}
