<?php
/**
 * Automatic critical CSS, generated per page-type via the PageSpeedPlus API.
 *
 * Each context (front page, single post, page, archive, ...) gets its own
 * critical CSS generated from a representative URL and stored locally. The
 * Async CSS feature then inlines the matching CSS for the request, falling
 * back to the manually entered global critical CSS.
 *
 * Generation runs in WP-Cron, one context per batch, so a slow API call
 * never blocks a page load.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Critical_CSS {

	const STORE_OPTION = 'psp_critical_css_store';
	const QUEUE_OPTION = 'psp_ccss_queue';
	const API_ENDPOINT = 'https://api.pagespeedplus.com/v1/critical-css';

	public function __construct() {
		add_action( 'psp_ccss_batch', array( __CLASS__, 'process_batch' ) );

		// Regenerate when the theme changes — old critical CSS would be wrong.
		add_action( 'switch_theme', array( __CLASS__, 'flush' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Whether the cloud Critical CSS generation service is available.
	 *
	 * The generation backend (api.pagespeedplus.com) is not built yet, so this
	 * is OFF by default and the auto-generation UI stays hidden — the manual
	 * Critical CSS field still works. Flip the PSP_CCSS_BACKEND constant (or the
	 * psp_ccss_backend_available filter) to true once the endpoint is live.
	 *
	 * @return bool
	 */
	public static function backend_available() {
		$available = defined( 'PSP_CCSS_BACKEND' ) && PSP_CCSS_BACKEND;
		return (bool) apply_filters( 'psp_ccss_backend_available', $available );
	}

	/* ----------------------------------------------------------- Contexts */

	/**
	 * Contexts we generate critical CSS for, with a representative URL each.
	 *
	 * @return array context => url
	 */
	public static function contexts() {
		$contexts = array(
			'front_page' => home_url( '/' ),
		);

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			$sample = get_posts( array(
				'post_type'      => $post_type,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			) );
			if ( $sample ) {
				$contexts[ 'singular_' . $post_type ] = get_permalink( $sample[0] );
			}
		}

		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts ) {
			$contexts['blog'] = get_permalink( $page_for_posts );
		}

		$categories = get_terms( array( 'taxonomy' => 'category', 'number' => 1, 'hide_empty' => true ) );
		if ( $categories && ! is_wp_error( $categories ) ) {
			$link = get_term_link( $categories[0] );
			if ( ! is_wp_error( $link ) ) {
				$contexts['archive'] = $link;
			}
		}

		return apply_filters( 'psp_ccss_contexts', $contexts );
	}

	/**
	 * Context key for the current frontend request.
	 *
	 * @return string
	 */
	public static function current_context() {
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'blog';
		}
		if ( is_singular() ) {
			return 'singular_' . get_post_type();
		}
		if ( is_archive() || is_search() ) {
			return 'archive';
		}
		return 'front_page';
	}

	/**
	 * Critical CSS for the current request, or '' if none stored.
	 * Falls back: exact context -> generic singular_post -> front_page.
	 *
	 * @return string
	 */
	public static function for_current_request() {
		$store = get_option( self::STORE_OPTION, array() );
		if ( ! is_array( $store ) || ! $store ) {
			return '';
		}
		foreach ( array( self::current_context(), 'singular_post', 'front_page' ) as $key ) {
			if ( ! empty( $store[ $key ]['css'] ) ) {
				return (string) $store[ $key ]['css'];
			}
		}
		return '';
	}

	/* --------------------------------------------------------- Generation */

	/**
	 * Queue all contexts for (re)generation and start the cron worker.
	 *
	 * @return int Number of contexts queued.
	 */
	public static function start() {
		if ( ! self::backend_available() ) {
			return 0; // Generation service not built yet — no-op.
		}
		$contexts = self::contexts();
		update_option( self::QUEUE_OPTION, $contexts, false );
		if ( $contexts && ! wp_next_scheduled( 'psp_ccss_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'psp_ccss_batch' );
		}
		return count( $contexts );
	}

	/**
	 * Generate critical CSS for one queued context, then reschedule.
	 */
	public static function process_batch() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}

		$context = array_key_first( $queue );
		$url     = $queue[ $context ];
		unset( $queue[ $context ] );
		update_option( self::QUEUE_OPTION, $queue, false );

		$result = self::generate_for_url( $url );

		$store = get_option( self::STORE_OPTION, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}
		if ( is_wp_error( $result ) ) {
			$store[ $context ] = array(
				'css'     => isset( $store[ $context ]['css'] ) ? $store[ $context ]['css'] : '',
				'error'   => $result->get_error_message(),
				'updated' => time(),
				'url'     => $url,
			);
		} else {
			$store[ $context ] = array(
				'css'     => $result,
				'error'   => '',
				'updated' => time(),
				'url'     => $url,
			);
		}
		update_option( self::STORE_OPTION, $store, false );

		if ( $queue ) {
			wp_schedule_single_event( time() + 10, 'psp_ccss_batch' );
		} else {
			delete_option( self::QUEUE_OPTION );
			// New critical CSS means cached pages are stale.
			PSP_Page_Cache::clear_all();
		}
	}

	/**
	 * Call the PageSpeedPlus API for one URL.
	 *
	 * @param string $url Page URL.
	 * @return string|WP_Error Critical CSS on success.
	 */
	public static function generate_for_url( $url ) {
		$api_key = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		if ( '' === $api_key ) {
			return new WP_Error( 'psp_no_key', __( 'No PageSpeedPlus API key configured.', 'pagespeedplus' ) );
		}

		$endpoint = apply_filters( 'psp_ccss_endpoint', self::API_ENDPOINT );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( array(
				'url'    => $url,
				'source' => 'wp-plugin/' . PSP_VERSION,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['css'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : sprintf( __( 'API returned HTTP %d.', 'pagespeedplus' ), $code );
			return new WP_Error( 'psp_api_error', $message );
		}

		return PSP_Minifier::css( wp_strip_all_tags( (string) $body['css'] ) );
	}

	/**
	 * Drop all stored critical CSS (theme changed etc.).
	 */
	public static function flush() {
		delete_option( self::STORE_OPTION );
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Status rows for the admin UI.
	 *
	 * @return array
	 */
	public static function status() {
		$store = get_option( self::STORE_OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	/**
	 * @return int Contexts still queued.
	 */
	public static function pending_count() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
