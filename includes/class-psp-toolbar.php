<?php
/**
 * Admin toolbar: Purge All / Purge This Page shortcuts.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Toolbar {

	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_menu' ), 90 );
		add_action( 'admin_post_psp_purge_url', array( $this, 'handle_purge_url' ) );
	}

	/**
	 * @param WP_Admin_Bar $bar Toolbar instance.
	 */
	public function add_menu( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( array(
			'id'    => 'pagespeedplus',
			'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px;"></span>' . esc_html__( 'PageSpeedPlus', 'pagespeedplus' ),
			'href'  => admin_url( 'admin.php?page=pagespeedplus' ),
		) );

		$bar->add_node( array(
			'parent' => 'pagespeedplus',
			'id'     => 'psp-purge-all',
			'title'  => esc_html__( 'Purge All Caches', 'pagespeedplus' ),
			'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=psp_purge_all' ), 'psp_purge_all' ),
		) );

		if ( ! is_admin() ) {
			global $wp;
			$current_url = home_url( add_query_arg( array(), $wp->request ) );
			$bar->add_node( array(
				'parent' => 'pagespeedplus',
				'id'     => 'psp-purge-url',
				'title'  => esc_html__( 'Purge This Page', 'pagespeedplus' ),
				'href'   => wp_nonce_url(
					admin_url( 'admin-post.php?action=psp_purge_url&url=' . rawurlencode( $current_url ) ),
					'psp_purge_url'
				),
			) );
		}

		// Kill switch: reachable from the frontend page the user is looking at
		// when something appears broken.
		if ( PSP_License::is_active() ) {
			$master = (bool) PSP_Options::get( 'enabled' );
			$bar->add_node( array(
				'parent' => 'pagespeedplus',
				'id'     => 'psp-master-toggle',
				'title'  => $master
					? '<span style="color:#f86368;">' . esc_html__( 'Disable All Optimizations', 'pagespeedplus' ) . '</span>'
					: '<span style="color:#8bc34a;">' . esc_html__( 'Re-enable Optimizations', 'pagespeedplus' ) . '</span>',
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=psp_toggle_master&state=' . ( $master ? 'off' : 'on' ) ), 'psp_toggle_master' ),
			) );
		}

		$bar->add_node( array(
			'parent' => 'pagespeedplus',
			'id'     => 'psp-settings',
			'title'  => esc_html__( 'Settings', 'pagespeedplus' ),
			'href'   => admin_url( 'admin.php?page=pagespeedplus' ),
		) );
	}

	public function handle_purge_url() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_purge_url' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		if ( $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			PSP_Page_Cache::clear_url( $url );
		}
		wp_safe_redirect( $url ? $url : admin_url() );
		exit;
	}
}
