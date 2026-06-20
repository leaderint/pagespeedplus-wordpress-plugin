<?php
/**
 * Cache preloader: crawls the sitemap in WP-Cron batches so visitors
 * always hit a warm cache.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Preloader {

	const QUEUE_OPTION = 'psp_preload_queue';
	const BATCH_SIZE   = 10;

	public function __construct() {
		add_action( 'psp_preload_batch', array( __CLASS__, 'process_batch' ) );

		// Re-warm after a full purge.
		if ( PSP_Options::get( 'preload_enabled' ) ) {
			add_action( 'psp_cache_cleared', array( __CLASS__, 'start' ) );
		}
	}

	/**
	 * Build the URL queue from the sitemap and kick off processing.
	 */
	/**
	 * Cache-warm endpoint on the PageSpeedPlus app (off-server warming).
	 */
	const REMOTE_ENDPOINT = 'https://app.pagespeedplus.com/api/site/cache-warm/start';

	/**
	 * @return true|WP_Error|null true on local schedule, remote result, or null if caching off.
	 */
	public static function start() {
		if ( ! PSP_Options::get( 'cache_enabled' ) ) {
			return null;
		}

		// Off-server warming: hand off to PageSpeedPlus. Its servers issue the
		// requests to our public URLs from up to 13 locations. The origin still
		// renders each page once to fill the cache (that's what warming is) —
		// but there's no WP-Cron load and no loopback self-request amplification
		// here, and PageSpeedPlus paces the crawl externally.
		if ( 'pagespeedplus' === PSP_Options::get( 'warmer_mode' ) ) {
			return self::trigger_remote();
		}

		$sitemap = (string) PSP_Options::get( 'preload_sitemap' );
		if ( ! $sitemap ) {
			$sitemap = home_url( '/wp-sitemap.xml' );
		}

		$urls = self::fetch_sitemap_urls( $sitemap, 2 );
		if ( ! $urls ) {
			$urls = array( home_url( '/' ) );
		}

		update_option( self::QUEUE_OPTION, array_values( array_unique( $urls ) ), false );

		if ( ! wp_next_scheduled( 'psp_preload_batch' ) ) {
			wp_schedule_single_event( time() + 10, 'psp_preload_batch' );
		}
		return true;
	}

	/**
	 * Trigger off-server cache warming via the PageSpeedPlus app API.
	 * Warming runs on PageSpeedPlus infrastructure against the site's public
	 * URLs — nothing is queued or fetched locally.
	 *
	 * @return true|WP_Error
	 */
	public static function trigger_remote() {
		$key     = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		$site_id = trim( (string) PSP_Options::get( 'psp_site_id' ) );
		if ( '' === $key || '' === $site_id ) {
			return new WP_Error( 'psp_warm_unconfigured', __( 'PageSpeedPlus cache warming needs an API key and Site ID.', 'pagespeedplus' ) );
		}

		$scope = 'monitored' === PSP_Options::get( 'warm_scope' ) ? 'monitored' : 'full';

		$url = add_query_arg(
			array( 'site_id' => rawurlencode( $site_id ), 'scope' => $scope ),
			apply_filters( 'psp_cache_warm_endpoint', self::REMOTE_ENDPOINT )
		);

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'psp_warm_http', sprintf( __( 'PageSpeedPlus warming request returned HTTP %d.', 'pagespeedplus' ), $code ) );
		}

		update_option( 'psp_warm_last_remote', time(), false );
		return true;
	}

	/**
	 * Request a batch of queued URLs, then reschedule until the queue is empty.
	 */
	public static function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $url ) {
			// Desktop pass.
			wp_remote_get( $url, array(
				'timeout'    => 10,
				'user-agent' => 'PageSpeedPlus Preloader',
				'blocking'   => false,
				'sslverify'  => false,
			) );
			// Mobile pass for the separate mobile cache.
			if ( PSP_Options::get( 'cache_mobile_separate' ) ) {
				wp_remote_get( $url, array(
					'timeout'    => 10,
					'user-agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile PageSpeedPlus Preloader',
					'blocking'   => false,
					'sslverify'  => false,
				) );
			}
		}

		if ( $queue ) {
			wp_schedule_single_event( time() + 30, 'psp_preload_batch' );
		} else {
			delete_option( self::QUEUE_OPTION );
		}
	}

	/**
	 * Recursively fetch URLs from a sitemap or sitemap index.
	 *
	 * @param string $sitemap_url Sitemap URL.
	 * @param int    $depth       Recursion budget for sitemap indexes.
	 * @return array
	 */
	private static function fetch_sitemap_urls( $sitemap_url, $depth ) {
		if ( $depth < 0 ) {
			return array();
		}

		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return array();
		}

		$urls = array();
		if ( 'sitemapindex' === $xml->getName() ) {
			foreach ( $xml->sitemap as $child ) {
				$urls = array_merge( $urls, self::fetch_sitemap_urls( (string) $child->loc, $depth - 1 ) );
			}
		} else {
			foreach ( $xml->url as $entry ) {
				$loc = (string) $entry->loc;
				if ( $loc ) {
					$urls[] = $loc;
				}
			}
		}

		return $urls;
	}

	/**
	 * @return int URLs still queued.
	 */
	public static function pending_count() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
