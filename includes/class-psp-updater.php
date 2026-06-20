<?php
/**
 * Self-hosted plugin updates, license-gated.
 *
 * Premium plugins aren't distributed via wordpress.org, so we feed our own
 * update info into WordPress's update system. The package URL returned by
 * the API should be a short-lived signed URL tied to the license key.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Updater {

	const CACHE_KEY = 'psp_update_info';

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
	}

	/**
	 * Check our API for a newer version (cached for 12 hours).
	 *
	 * @return array|null { new_version, package, requires, tested, changelog }
	 */
	private function get_update_info() {
		$key = trim( (string) PSP_Options::get( 'psp_api_key' ) );
		if ( '' === $key || ! PSP_License::is_active() ) {
			return null;
		}

		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$endpoint = apply_filters(
			'psp_update_endpoint',
			'https://api.pagespeedplus.com/v1/plugin/update'
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'key'     => $key,
				'site'    => home_url(),
				'version' => PSP_VERSION,
			) ),
		) );

		$info = array();
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['new_version'] ) && ! empty( $data['package'] ) ) {
				$info = $data;
			}
		}

		set_site_transient( self::CACHE_KEY, $info, 12 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$info = $this->get_update_info();
		if ( ! $info || version_compare( PSP_VERSION, $info['new_version'], '>=' ) ) {
			return $transient;
		}

		$basename = plugin_basename( PSP_FILE );

		$transient->response[ $basename ] = (object) array(
			'slug'        => 'pagespeedplus',
			'plugin'      => $basename,
			'new_version' => $info['new_version'],
			'package'     => $info['package'],
			'url'         => 'https://pagespeedplus.com/wordpress-plugin',
			'requires'    => $info['requires'] ?? '5.8',
			'tested'      => $info['tested'] ?? '',
		);

		return $transient;
	}

	/**
	 * "View details" modal content.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'pagespeedplus' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$info = $this->get_update_info();

		return (object) array(
			'name'          => 'PageSpeedPlus',
			'slug'          => 'pagespeedplus',
			'version'       => $info['new_version'] ?? PSP_VERSION,
			'author'        => '<a href="https://pagespeedplus.com">PageSpeedPlus</a>',
			'homepage'      => 'https://pagespeedplus.com/wordpress-plugin',
			'requires'      => $info['requires'] ?? '5.8',
			'tested'        => $info['tested'] ?? '',
			'download_link' => $info['package'] ?? '',
			'sections'      => array(
				'changelog' => $info['changelog'] ?? __( 'See pagespeedplus.com for release notes.', 'pagespeedplus' ),
			),
		);
	}

	public function flush_cache() {
		delete_site_transient( self::CACHE_KEY );
	}
}
