<?php
/**
 * WP-CLI commands: wp pagespeedplus <command>
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_CLI {

	/**
	 * Purge all caches (pages + optimized assets).
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus purge
	 *
	 * @subcommand purge
	 */
	public function purge() {
		PSP_Purge::purge_all();
		WP_CLI::success( 'All PageSpeedPlus caches purged.' );
	}

	/**
	 * Purge the cache for a single URL.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL to purge.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus purge-url https://example.com/about/
	 *
	 * @subcommand purge-url
	 */
	public function purge_url( $args ) {
		PSP_Page_Cache::clear_url( $args[0] );
		WP_CLI::success( 'Purged: ' . $args[0] );
	}

	/**
	 * Start the cache preloader.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus preload
	 *
	 * @subcommand preload
	 */
	public function preload() {
		$result = PSP_Preloader::start();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 'pagespeedplus' === PSP_Options::get( 'warmer_mode' ) ) {
			WP_CLI::success( 'PageSpeedPlus off-server cache warming triggered.' );
		} else {
			WP_CLI::success( sprintf( 'Preload queued: %d URLs.', PSP_Preloader::pending_count() ) );
		}
	}

	/**
	 * Convert the media library to WebP/AVIF.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Process the whole queue immediately instead of via WP-Cron batches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus webp --now
	 *
	 * @subcommand webp
	 */
	public function webp( $args, $assoc_args ) {
		$queued = PSP_WebP::start_bulk();
		WP_CLI::log( sprintf( 'Queued %d attachments.', $queued ) );
		if ( isset( $assoc_args['now'] ) ) {
			while ( PSP_WebP::pending_count() > 0 ) {
				PSP_WebP::process_batch();
				WP_CLI::log( sprintf( '%d remaining…', PSP_WebP::pending_count() ) );
			}
		}
		WP_CLI::success( 'WebP conversion ' . ( isset( $assoc_args['now'] ) ? 'complete.' : 'running in background batches.' ) );
	}

	/**
	 * Generate critical CSS for all page types via the PageSpeedPlus API.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Process the whole queue immediately instead of via WP-Cron batches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus critical-css --now
	 *
	 * @subcommand critical-css
	 */
	public function critical_css( $args, $assoc_args ) {
		$queued = PSP_Critical_CSS::start();
		WP_CLI::log( sprintf( 'Queued %d page types.', $queued ) );
		if ( isset( $assoc_args['now'] ) ) {
			while ( PSP_Critical_CSS::pending_count() > 0 ) {
				PSP_Critical_CSS::process_batch();
			}
			foreach ( PSP_Critical_CSS::status() as $context => $row ) {
				WP_CLI::log( sprintf( '%-24s %s', $context, $row['error'] ? 'ERROR: ' . $row['error'] : strlen( $row['css'] ) . ' bytes' ) );
			}
		}
		WP_CLI::success( 'Critical CSS generation ' . ( isset( $assoc_args['now'] ) ? 'complete.' : 'running in background.' ) );
	}

	/**
	 * Master kill switch: turn ALL optimizations off (as if the plugin
	 * were deactivated) or back on.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on | off
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus master off
	 *     wp pagespeedplus master on
	 *
	 * @subcommand master
	 */
	public function master( $args ) {
		$state = $args[0] ?? '';
		if ( ! in_array( $state, array( 'on', 'off' ), true ) ) {
			WP_CLI::error( 'Usage: wp pagespeedplus master <on|off>' );
		}
		PSP_Plugin::set_master( 'on' === $state );
		WP_CLI::success( 'on' === $state ? 'Optimizations enabled.' : 'All optimizations disabled — site behaves as if the plugin were deactivated.' );
	}

	/**
	 * Manage the license: status, activate <key>, deactivate.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status | activate | deactivate
	 *
	 * [<key>]
	 * : License key (for activate).
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus license status
	 *     wp pagespeedplus license activate psp_abc123
	 *
	 * @subcommand license
	 */
	public function license( $args ) {
		$action = $args[0] ?? 'status';
		switch ( $action ) {
			case 'activate':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Usage: wp pagespeedplus license activate <key>' );
				}
				$result = PSP_License::activate( $args[1] );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}
				WP_CLI::success( 'License activated.' );
				break;
			case 'deactivate':
				PSP_License::deactivate();
				WP_CLI::success( 'License deactivated for this site.' );
				break;
			default:
				$display = PSP_License::status_display();
				WP_CLI::log( 'Status: ' . $display['status'] . ' — ' . $display['label'] );
				if ( $display['detail'] ) {
					WP_CLI::log( $display['detail'] );
				}
				WP_CLI::log( 'Optimizations: ' . ( PSP_License::is_active() ? 'enabled' : 'disabled' ) );
		}
	}

	/**
	 * Get or set a plugin setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * [<value>]
	 * : New value. Omit to read the current value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pagespeedplus setting cache_enabled
	 *     wp pagespeedplus setting delay_js 1
	 *
	 * @subcommand setting
	 */
	public function setting( $args ) {
		$key = $args[0];
		if ( ! array_key_exists( $key, PSP_Options::defaults() ) ) {
			WP_CLI::error( 'Unknown setting: ' . $key );
		}
		if ( isset( $args[1] ) ) {
			PSP_Options::update( array( $key => is_numeric( $args[1] ) ? (int) $args[1] : $args[1] ) );
			PSP_Page_Cache::write_config();
			WP_CLI::success( $key . ' = ' . $args[1] );
		} else {
			WP_CLI::line( var_export( PSP_Options::get( $key ), true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
