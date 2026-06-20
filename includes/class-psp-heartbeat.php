<?php
/**
 * WordPress Heartbeat API control. Reduces admin-ajax.php server load.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Heartbeat {

	public function __construct() {
		add_action( 'init', array( $this, 'apply' ), 99 );
		add_filter( 'heartbeat_settings', array( $this, 'settings' ) );
	}

	/**
	 * Current context: dashboard, editor or frontend.
	 *
	 * @return string
	 */
	private function context() {
		global $pagenow;
		if ( ! is_admin() ) {
			return 'frontend';
		}
		if ( isset( $pagenow ) && in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return 'editor';
		}
		return 'dashboard';
	}

	/**
	 * Setting for the current context.
	 *
	 * @return string default | slow | disable
	 */
	private function mode() {
		return (string) PSP_Options::get( 'heartbeat_' . $this->context(), 'default' );
	}

	public function apply() {
		if ( 'disable' === $this->mode() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function settings( $settings ) {
		if ( 'slow' === $this->mode() ) {
			$settings['interval'] = 120;
		}
		return $settings;
	}
}
