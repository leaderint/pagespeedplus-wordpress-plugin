<?php
/**
 * Plugin bootstrap: loads modules, handles lifecycle.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Plugin {

	/**
	 * @var PSP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return PSP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_files();

		// Licensing and updates always run, so an unlicensed install can
		// still activate a key and receive updates. Purge hooks always run
		// so the toolbar/admin "Purge" buttons work in every state.
		new PSP_License();
		new PSP_Updater();
		new PSP_Purge();

		// Optimization modules only boot with an active license AND the
		// master switch on. Off = identical to a deactivated plugin.
		if ( self::optimizations_active() ) {
			// HTML buffer pipeline must start as early as possible on the frontend.
			PSP_Buffer::instance()->init();

			new PSP_Page_Cache();
			new PSP_Minify_HTML();
			new PSP_Assets();
			new PSP_Delay_JS();
			new PSP_Prefetch();
			new PSP_RUM();
			new PSP_Media();
			new PSP_WebP();
			new PSP_Critical_CSS();
			new PSP_Fonts();
			new PSP_Hints();
			new PSP_Tweaks();
			new PSP_Heartbeat();
			new PSP_CDN();
			new PSP_Preloader();
		}

		if ( is_admin() ) {
			new PSP_Admin();
		}

		new PSP_Toolbar();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pagespeedplus', 'PSP_CLI' );
		}

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Whether any optimization may run: active license AND master switch on.
	 *
	 * @return bool
	 */
	public static function optimizations_active() {
		return PSP_License::is_active() && PSP_Options::get( 'enabled' );
	}

	/**
	 * Flip the master kill switch and bring all dependent state along:
	 * purge caches, sync the drop-in config, add/remove .htaccess rules.
	 * Off must leave the site exactly as a deactivated plugin would.
	 *
	 * @param bool $on New state.
	 */
	public static function set_master( $on ) {
		PSP_Options::update( array( 'enabled' => $on ? 1 : 0 ) );

		PSP_Page_Cache::clear_all(); // Also rewrites the drop-in config with the new state.
		PSP_Assets::clear_asset_cache();

		if ( $on ) {
			PSP_Htaccess::update_rules();
		} else {
			PSP_Htaccess::remove_rules();
		}

		do_action( 'psp_master_toggled', (bool) $on );
	}

	private function load_files() {
		$files = array(
			'class-psp-buffer.php',
			'class-psp-page-cache.php',
			'class-psp-purge.php',
			'class-psp-minifier.php',
			'class-psp-minify-html.php',
			'class-psp-assets.php',
			'class-psp-delay-js.php',
			'class-psp-prefetch.php',
			'class-psp-rum.php',
			'class-psp-media.php',
			'class-psp-webp.php',
			'class-psp-critical-css.php',
			'class-psp-fonts.php',
			'class-psp-hints.php',
			'class-psp-tweaks.php',
			'class-psp-heartbeat.php',
			'class-psp-cdn.php',
			'class-psp-preloader.php',
			'class-psp-htaccess.php',
			'class-psp-license.php',
			'class-psp-updater.php',
			'class-psp-toolbar.php',
			'class-psp-admin.php',
			'class-psp-cli.php',
		);
		foreach ( $files as $file ) {
			require_once PSP_DIR . 'includes/' . $file;
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'pagespeedplus', false, dirname( plugin_basename( PSP_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: create cache dirs, install drop-in, write .htaccess rules, schedule crons.
	 */
	public static function activate() {
		self::instance(); // Ensure files are loaded.

		if ( ! get_option( PSP_Options::OPTION_KEY ) ) {
			update_option( PSP_Options::OPTION_KEY, PSP_Options::defaults() );
		}

		wp_mkdir_p( PSP_CACHE_DIR );
		wp_mkdir_p( PSP_ASSET_CACHE_DIR );

		PSP_Page_Cache::install_advanced_cache();
		PSP_Htaccess::update_rules();

		if ( ! wp_next_scheduled( 'psp_garbage_collect' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'psp_garbage_collect' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Deactivation: remove drop-in, .htaccess rules, crons, cached files.
	 */
	public static function deactivate() {
		self::instance();

		PSP_Page_Cache::uninstall_advanced_cache();
		PSP_Htaccess::remove_rules();
		PSP_Page_Cache::clear_all();

		wp_clear_scheduled_hook( 'psp_garbage_collect' );
		wp_clear_scheduled_hook( 'psp_preload_batch' );
		wp_clear_scheduled_hook( 'psp_webp_batch' );
		wp_clear_scheduled_hook( 'psp_ccss_batch' );
		wp_clear_scheduled_hook( 'psp_license_check' );

		flush_rewrite_rules();
	}
}
