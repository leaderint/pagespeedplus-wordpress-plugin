<?php
/**
 * License management: activation, daily revalidation, graceful degradation.
 *
 * The plugin's optimization modules only boot while a license is active
 * (see PSP_Plugin). Policy decisions:
 *
 *  - Validate the API key by calling GET https://app.pagespeedplus.com/api/sites
 *    (200 = valid). Revalidate daily via WP-Cron.
 *  - If the API is unreachable, keep the last known-good status for
 *    GRACE_DAYS days ("never break a customer site because our API
 *    hiccuped"). After the grace period, optimizations turn off but
 *    settings are preserved — reactivating restores everything.
 *  - An explicit "invalid"/"expired" response from the API disables
 *    features immediately (with the cache cleanly torn down).
 *
 * Local development: define( 'PSP_LICENSE_DEV', true ) bypasses remote
 * validation entirely.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_License {

	const STATE_OPTION = 'psp_license_state';
	const SITES_OPTION = 'psp_sites';
	const SITES_ENDPOINT = 'https://app.pagespeedplus.com/api/sites';
	const SITE_ENDPOINT  = 'https://app.pagespeedplus.com/api/site';
	const GRACE_DAYS   = 7;

	public function __construct() {
		add_action( 'psp_license_check', array( __CLASS__, 'revalidate' ) );

		if ( ! wp_next_scheduled( 'psp_license_check' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'psp_license_check' );
		}
	}

	/* -------------------------------------------------------------- State */

	/**
	 * Whether optimizations may run right now.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( defined( 'PSP_LICENSE_DEV' ) && PSP_LICENSE_DEV ) {
			return true;
		}

		$state = self::state();
		if ( 'active' !== ( $state['status'] ?? '' ) ) {
			return false;
		}

		// Within the grace window after the last successful validation?
		$last_ok = (int) ( $state['last_ok'] ?? 0 );
		return ( time() - $last_ok ) < ( self::GRACE_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * @return array Stored license state.
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Human-readable status for the admin UI.
	 *
	 * @return array { status, label, detail }
	 */
	public static function status_display() {
		if ( defined( 'PSP_LICENSE_DEV' ) && PSP_LICENSE_DEV ) {
			return array( 'status' => 'active', 'label' => __( 'Development mode', 'pagespeedplus' ), 'detail' => 'PSP_LICENSE_DEV' );
		}

		$state = self::state();
		$status = $state['status'] ?? 'none';

		if ( 'active' === $status && ! self::is_active() ) {
			return array(
				'status' => 'grace_expired',
				'label'  => __( 'Validation overdue', 'pagespeedplus' ),
				'detail' => __( 'We could not reach the license server for over 7 days. Optimizations are paused until validation succeeds.', 'pagespeedplus' ),
			);
		}

		$map = array(
			'none'    => array( __( 'Not connected', 'pagespeedplus' ), __( 'Enter your API key to enable optimizations.', 'pagespeedplus' ) ),
			'active'  => array( __( 'Connected', 'pagespeedplus' ), __( 'Your API key is valid and optimizations are active.', 'pagespeedplus' ) ),
			'invalid' => array( __( 'Invalid key', 'pagespeedplus' ), $state['message'] ?? '' ),
			'expired' => array( __( 'Expired', 'pagespeedplus' ), __( 'Renew at pagespeedplus.com to re-enable optimizations.', 'pagespeedplus' ) ),
		);
		$row = $map[ $status ] ?? $map['none'];

		return array( 'status' => $status, 'label' => $row[0], 'detail' => $row[1] );
	}

	/* ------------------------------------------------------------ Actions */

	/**
	 * Validate an API key by calling GET /api/sites. A 200 means the key is
	 * valid (and gives us the user's site list for the connect dropdown);
	 * 401/403 means it's bad; anything else is treated as an outage.
	 *
	 * @param string $key API key (Bearer token).
	 * @return true|WP_Error
	 */
	public static function activate( $key ) {
		$key = trim( $key );
		if ( '' === $key ) {
			return new WP_Error( 'psp_empty_key', __( 'Please enter your API key.', 'pagespeedplus' ) );
		}

		// Persist the key immediately, regardless of outcome — it's the same key
		// used for cache warming, Critical CSS and updates, so it must be stored
		// even if validation can't complete (dev mode / API down).
		PSP_Options::update( array( 'psp_api_key' => $key ) );

		$sites = self::fetch_sites( $key );
		if ( is_wp_error( $sites ) ) {
			if ( 'psp_invalid_key' === $sites->get_error_code() ) {
				self::store_state( array( 'status' => 'invalid', 'message' => $sites->get_error_message() ) );
				self::on_license_changed();
			}
			return $sites; // invalid OR unreachable — surface to the user.
		}

		update_option( self::SITES_OPTION, $sites, false );
		self::store_state( array(
			'status'  => 'active',
			'last_ok' => time(),
		) );
		self::on_license_changed();

		return true;
	}

	/**
	 * GET /api/sites with the key as a Bearer token.
	 *
	 * @param string $key API key.
	 * @return array|WP_Error Normalized site list on success.
	 */
	public static function fetch_sites( $key ) {
		$endpoint = apply_filters( 'psp_sites_endpoint', self::SITES_ENDPOINT );

		$response = wp_remote_get( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'psp_api_unreachable', __( 'Could not reach pagespeedplus.com. Please try again.', 'pagespeedplus' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return self::normalize_sites( $body );
		}
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'psp_invalid_key', __( 'That API key was rejected. Check it and try again.', 'pagespeedplus' ) );
		}
		return new WP_Error( 'psp_api_unreachable', sprintf( __( 'pagespeedplus.com returned HTTP %d. Please try again.', 'pagespeedplus' ), $code ) );
	}

	/**
	 * Normalize the /api/sites response into [ ['id'=>, 'label'=>, 'url'=>], ... ].
	 * Tolerant of common REST shapes; override via the `psp_normalize_sites`
	 * filter if the real field names differ.
	 *
	 * @param mixed $body Decoded JSON.
	 * @return array
	 */
	public static function normalize_sites( $body ) {
		$list = array();
		if ( is_array( $body ) ) {
			// Bare array, or wrapped under data/sites.
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				$body = $body['data'];
			} elseif ( isset( $body['sites'] ) && is_array( $body['sites'] ) ) {
				$body = $body['sites'];
			}
			foreach ( $body as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$id = $item['id'] ?? $item['site_id'] ?? null;
				if ( null === $id || ! empty( $item['deleted_at'] ) ) {
					continue; // No ID, or a soft-deleted site.
				}
				// Field names per the app.pagespeedplus.com /api/sites response.
				$url   = $item['url'] ?? $item['domain'] ?? $item['site_url'] ?? '';
				$label = $item['site_name'] ?? $item['name'] ?? $item['title'] ?? ( $url ? $url : 'Site ' . $id );
				$list[] = array(
					'id'       => (string) $id,
					'label'    => (string) $label,
					'url'      => (string) $url,
					'platform' => isset( $item['platform'] ) ? (string) $item['platform'] : '',
				);
			}
		}
		return apply_filters( 'psp_normalize_sites', $list, $body );
	}

	/**
	 * Create a new PageSpeedPlus site for this WordPress install.
	 *
	 * @return string|WP_Error New site ID on success.
	 */
	public static function create_site() {
		$key = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'psp_no_key', __( 'Enter your API key first.', 'pagespeedplus' ) );
		}

		$response = wp_remote_post( apply_filters( 'psp_site_endpoint', self::SITE_ENDPOINT ), array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'body'    => array(
				'site_name' => get_bloginfo( 'name' ),
				'url'       => home_url(),
				'tag'       => 'wordpress-plugin',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'psp_api_unreachable', __( 'Could not reach pagespeedplus.com.', 'pagespeedplus' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'psp_create_failed', sprintf( __( 'Could not create the site (HTTP %d).', 'pagespeedplus' ), $code ) );
		}

		// Refresh the site list and select the one matching this install.
		$sites = self::fetch_sites( $key );
		if ( ! is_wp_error( $sites ) ) {
			update_option( self::SITES_OPTION, $sites, false );
			foreach ( $sites as $site ) {
				if ( untrailingslashit( $site['url'] ) === untrailingslashit( home_url() ) ) {
					self::connect_site( $site['id'] ); // Stores the site_id durably.
					return $site['id'];
				}
			}
		}
		return '';
	}

	/**
	 * Connect this WordPress install to a PageSpeedPlus site by storing its
	 * site_id (durably, in the pagespeedplus_settings option). Validates the id
	 * against the account's known sites so only a real API site_id is saved.
	 *
	 * @param string $site_id PageSpeedPlus site id.
	 * @return true|WP_Error
	 */
	public static function connect_site( $site_id ) {
		$site_id = trim( (string) $site_id );
		$match   = null;
		foreach ( self::sites() as $site ) {
			if ( (string) $site['id'] === $site_id ) {
				$match = $site;
				break;
			}
		}
		if ( null === $match ) {
			return new WP_Error( 'psp_unknown_site', __( 'That site is not on your PageSpeedPlus account. Re-check your API key, then try again.', 'pagespeedplus' ) );
		}

		PSP_Options::update( array( 'psp_site_id' => $site_id ) );
		// Cache the connected site's label/url so the UI can show it even if
		// the live list is momentarily unavailable.
		update_option( 'psp_connected_site', array(
			'id'    => $site_id,
			'label' => $match['label'],
			'url'   => $match['url'],
		), false );

		return true;
	}

	/**
	 * The connected site's stored details (id, label, url), or empty array.
	 *
	 * @return array
	 */
	public static function connected_site() {
		$s = get_option( 'psp_connected_site', array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Re-fetch the account's sites from the API and replace the cached list.
	 *
	 * @return int|WP_Error Number of sites on success.
	 */
	public static function refresh_sites() {
		$key = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'psp_no_key', __( 'Enter your API key on the License tab first.', 'pagespeedplus' ) );
		}
		$sites = self::fetch_sites( $key );
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		update_option( self::SITES_OPTION, $sites, false );
		return count( $sites );
	}

	/**
	 * The user's PageSpeedPlus sites (cached from the last successful fetch).
	 *
	 * @return array
	 */
	public static function sites() {
		$sites = get_option( self::SITES_OPTION, array() );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Daily revalidation (cron): the key still works if GET /api/sites is 200.
	 */
	public static function revalidate() {
		$key = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		if ( '' === $key ) {
			return;
		}

		$sites = self::fetch_sites( $key );
		$state = self::state();

		if ( ! is_wp_error( $sites ) ) {
			$state['status']  = 'active';
			$state['last_ok'] = time();
			update_option( self::SITES_OPTION, $sites, false );
		} elseif ( 'psp_invalid_key' === $sites->get_error_code() ) {
			// Key genuinely revoked: disable now.
			$state['status']  = 'invalid';
			$state['message'] = $sites->get_error_message();
		} else {
			// Outage: leave last_ok untouched so the grace window applies.
			return;
		}

		self::store_state( $state );
		self::on_license_changed();
	}

	/**
	 * Disconnect this site: forget the key, state and site list.
	 */
	public static function deactivate() {
		PSP_Options::update( array( 'psp_api_key' => '', 'psp_site_id' => '' ) );
		delete_option( self::STATE_OPTION );
		delete_option( self::SITES_OPTION );
		delete_option( 'psp_connected_site' );
		self::on_license_changed();
	}

	/**
	 * Keep dependent state consistent when the license flips either way:
	 * the advanced-cache config mirrors license state, so the drop-in stops
	 * serving stale caches the moment a license lapses.
	 */
	private static function on_license_changed() {
		if ( class_exists( 'PSP_Page_Cache' ) ) {
			PSP_Page_Cache::write_config();
			if ( ! self::is_active() ) {
				PSP_Page_Cache::clear_all();
			}
		}
	}

	private static function store_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}
}
