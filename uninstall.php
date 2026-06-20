<?php
/**
 * Uninstall cleanup: remove options, crons, cache files and drop-in.
 *
 * @package PageSpeedPlus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pagespeedplus_settings' );
delete_option( 'psp_preload_queue' );
delete_option( 'psp_webp_queue' );
delete_option( 'psp_ccss_queue' );
delete_option( 'psp_critical_css_store' );
delete_option( 'psp_warm_last_remote' );
delete_option( 'psp_self_hosted_fonts' );
delete_option( 'psp_fonts_queue' );
delete_option( 'psp_self_hosted_scripts' );
delete_option( 'psp_scripts_queue' );
// (disable_on_urls lives inside pagespeedplus_settings, already removed above.)
delete_option( 'psp_license_state' );
delete_option( 'psp_sites' );
delete_option( 'psp_connected_site' );
delete_site_transient( 'psp_update_info' );

wp_clear_scheduled_hook( 'psp_garbage_collect' );
wp_clear_scheduled_hook( 'psp_preload_batch' );
wp_clear_scheduled_hook( 'psp_webp_batch' );
wp_clear_scheduled_hook( 'psp_ccss_batch' );
wp_clear_scheduled_hook( 'psp_fonts_fetch' );
wp_clear_scheduled_hook( 'psp_scripts_fetch' );
wp_clear_scheduled_hook( 'psp_license_check' );

// Remove our advanced-cache drop-in.
$psp_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $psp_dropin ) ) {
	$psp_contents = file_get_contents( $psp_dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false !== strpos( (string) $psp_contents, 'PageSpeedPlus' ) ) {
		unlink( $psp_dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

// Remove cache directories.
$psp_dirs = array(
	WP_CONTENT_DIR . '/cache/pagespeedplus/',
	WP_CONTENT_DIR . '/cache/pagespeedplus-assets/',
	WP_CONTENT_DIR . '/cache/pagespeedplus-fonts/',
	WP_CONTENT_DIR . '/cache/pagespeedplus-scripts/',
);
foreach ( $psp_dirs as $psp_dir ) {
	if ( ! is_dir( $psp_dir ) ) {
		continue;
	}
	$psp_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $psp_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $psp_iterator as $psp_item ) {
		if ( $psp_item->isDir() ) {
			rmdir( $psp_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			unlink( $psp_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	rmdir( $psp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

// Remove .htaccess rules.
if ( file_exists( ABSPATH . '.htaccess' ) && is_writable( ABSPATH . '.htaccess' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	if ( function_exists( 'insert_with_markers' ) ) {
		insert_with_markers( ABSPATH . '.htaccess', 'PageSpeedPlus', array() );
	}
}
