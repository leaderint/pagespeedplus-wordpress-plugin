<?php
/**
 * Admin settings UI: tabbed page under its own top-level menu.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_psp_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_psp_preload', array( $this, 'handle_preload' ) );
		add_action( 'admin_post_psp_webp_bulk', array( $this, 'handle_webp_bulk' ) );
		add_action( 'admin_post_psp_generate_ccss', array( $this, 'handle_generate_ccss' ) );
		add_action( 'admin_post_psp_toggle_master', array( $this, 'handle_toggle_master' ) );
		add_action( 'admin_post_psp_activate_license', array( $this, 'handle_activate_license' ) );
		add_action( 'admin_post_psp_deactivate_license', array( $this, 'handle_deactivate_license' ) );
		add_action( 'admin_post_psp_connect_site', array( $this, 'handle_connect_site' ) );
		add_action( 'admin_post_psp_create_site', array( $this, 'handle_create_site' ) );
		add_action( 'admin_post_psp_refresh_sites', array( $this, 'handle_refresh_sites' ) );
		add_action( 'admin_post_psp_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_psp_import_settings', array( $this, 'handle_import_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PSP_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'PageSpeedPlus', 'pagespeedplus' ),
			__( 'PageSpeedPlus', 'pagespeedplus' ),
			'manage_options',
			'pagespeedplus',
			array( $this, 'render' ),
			'dashicons-performance',
			81
		);
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_pagespeedplus' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'psp-admin', PSP_URL . 'assets/admin.css', array(), PSP_VERSION );
	}

	/**
	 * @param array $links Plugin row links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pagespeedplus' ) ) . '">' . esc_html__( 'Settings', 'pagespeedplus' ) . '</a>' );
		return $links;
	}

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['psp_purged'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PageSpeedPlus: all caches purged.', 'pagespeedplus' ) . '</p></div>';
		}
		if ( isset( $_GET['psp_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved. Caches were purged so changes take effect immediately.', 'pagespeedplus' ) . '</p></div>';
		}
		if ( isset( $_GET['psp_preloading'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache preloading started. It runs in the background via WP-Cron.', 'pagespeedplus' ) . '</p></div>';
		}
		if ( isset( $_GET['psp_warm_remote'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PageSpeedPlus cache warming triggered. Your URLs are being warmed off-server from your configured locations — track progress in your PageSpeedPlus dashboard.', 'pagespeedplus' ) . '</p></div>';
		}
		if ( isset( $_GET['psp_warm_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['psp_warm_error'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['psp_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['psp_error'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['psp_imported'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Settings imported: %d values applied. Caches were purged.', 'pagespeedplus' ), (int) $_GET['psp_imported'] ) . '</p></div>';
		}
		if ( isset( $_GET['psp_webp_queued'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'WebP conversion started: %d images queued. Conversion runs in the background via WP-Cron.', 'pagespeedplus' ), (int) $_GET['psp_webp_queued'] ) . '</p></div>';
		}
		if ( isset( $_GET['psp_license_ok'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( sprintf( __( 'API key validated — optimizations are now enabled. Choose which site to connect on the <a href="%s">Dashboard</a>.', 'pagespeedplus' ), esc_url( admin_url( 'admin.php?page=pagespeedplus' ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['psp_site_connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Site connected. Cache warming will run against it.', 'pagespeedplus' ) . '</p></div>';
		}
		if ( isset( $_GET['psp_sites_refreshed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Site list refreshed from PageSpeedPlus — %d sites.', 'pagespeedplus' ), (int) $_GET['psp_sites_refreshed'] ) . '</p></div>';
		}
		if ( isset( $_GET['psp_license_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['psp_license_error'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['psp_ccss_queued'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Critical CSS generation started for %d page types. It runs in the background via WP-Cron.', 'pagespeedplus' ), (int) $_GET['psp_ccss_queued'] ) . '</p></div>';
		}
		// phpcs:enable

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['psp_master'] ) ) {
			if ( 'off' === $_GET['psp_master'] ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'PageSpeedPlus: all optimizations are OFF and caches were purged. The site now behaves as if the plugin were deactivated.', 'pagespeedplus' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PageSpeedPlus: optimizations re-enabled.', 'pagespeedplus' ) . '</p></div>';
			}
		}
		// phpcs:enable

		// Persistent site-wide reminder while the kill switch is off.
		if ( PSP_License::is_active() && ! PSP_Options::get( 'enabled' ) && ! isset( $_GET['psp_master'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-warning"><p><strong>PageSpeedPlus:</strong> ' . wp_kses_post( sprintf( __( 'all optimizations are currently disabled by the kill switch. <a href="%s">Re-enable them</a> when you\'re ready.', 'pagespeedplus' ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_toggle_master&state=on' ), 'psp_toggle_master' ) ) ) ) . '</p></div>';
		}

		// Nudge unlicensed installs from the plugins screen.
		$screen = get_current_screen();
		if ( $screen && 'plugins' === $screen->id && ! PSP_License::is_active() ) {
			echo '<div class="notice notice-warning"><p><strong>PageSpeedPlus:</strong> ' . wp_kses_post( sprintf( __( 'optimizations are inactive until you <a href="%s">activate your license</a>.', 'pagespeedplus' ), esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=license' ) ) ) ) . '</p></div>';
		}

		// Warn when an image format is enabled but the server can't do it
		// (e.g. WebP turned on, then migrated to a host without Imagick/GD).
		$support = PSP_WebP::support();
		$missing = array();
		if ( PSP_Options::get( 'webp_enabled' ) && ! $support['webp'] ) {
			$missing[] = 'WebP';
		}
		if ( PSP_Options::get( 'avif_enabled' ) && ! $support['avif'] ) {
			$missing[] = 'AVIF';
		}
		if ( $missing ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post( sprintf(
				/* translators: %1$s: format names, %2$s: settings URL */
				__( '<strong>PageSpeedPlus:</strong> %1$s conversion is enabled but your server has no image library that supports it, so no images are being converted. Ask your host to enable the Imagick or GD PHP extension, or turn it off on the <a href="%2$s">Media tab</a>.', 'pagespeedplus' ),
				esc_html( implode( ' & ', $missing ) ),
				esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=media' ) )
			) ) . '</p></div>';
		}

		// Warn if the drop-in is missing while caching is on.
		if ( PSP_Options::get( 'cache_enabled' ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
			$screen = get_current_screen();
			if ( $screen && 'toplevel_page_pagespeedplus' === $screen->id ) {
				echo '<div class="notice notice-warning"><p>' . wp_kses_post( __( '<strong>PageSpeedPlus:</strong> <code>WP_CACHE</code> is not enabled, so cached pages are generated but not served at full speed. Add <code>define( \'WP_CACHE\', true );</code> to your <code>wp-config.php</code>.', 'pagespeedplus' ) ) . '</p></div>';
			}
		}
	}

	/* ------------------------------------------------------------- Render */

	private function tabs() {
		return array(
			'dashboard' => array( __( 'Dashboard', 'pagespeedplus' ), 'dashicons-dashboard' ),
			'cache'     => array( __( 'Cache', 'pagespeedplus' ), 'dashicons-archive' ),
			'css'       => array( __( 'CSS', 'pagespeedplus' ), 'dashicons-admin-appearance' ),
			'js'        => array( __( 'JavaScript', 'pagespeedplus' ), 'dashicons-editor-code' ),
			'media'     => array( __( 'Media', 'pagespeedplus' ), 'dashicons-format-image' ),
			'fonts'     => array( __( 'Fonts', 'pagespeedplus' ), 'dashicons-editor-textcolor' ),
			'tweaks'    => array( __( 'Tweaks', 'pagespeedplus' ), 'dashicons-admin-tools' ),
			'cdn'       => array( __( 'CDN', 'pagespeedplus' ), 'dashicons-admin-site-alt3' ),
			'webvitals' => array( __( 'Web Vitals', 'pagespeedplus' ), 'dashicons-chart-line' ),
			'license'   => array( __( 'License', 'pagespeedplus' ), 'dashicons-admin-network' ),
		);
	}

	/**
	 * Section title shown above each settings tab's card.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function tab_heading( $tab ) {
		$headings = array(
			'cache'    => __( 'Page Cache, Browser Caching & Warming', 'pagespeedplus' ),
			'css'      => __( 'CSS Optimization', 'pagespeedplus' ),
			'js'       => __( 'JavaScript Optimization', 'pagespeedplus' ),
			'media'    => __( 'Images, Iframes & Next-Gen Formats', 'pagespeedplus' ),
			'fonts'    => __( 'Fonts & Resource Hints', 'pagespeedplus' ),
			'tweaks'   => __( 'WordPress Tweaks', 'pagespeedplus' ),
			'cdn'      => __( 'CDN', 'pagespeedplus' ),
			'webvitals' => __( 'Real User Monitoring', 'pagespeedplus' ),
		);
		return isset( $headings[ $tab ] ) ? $headings[ $tab ] : '';
	}

	public function render() {
		$tabs     = $this->tabs();
		$licensed = PSP_License::is_active();
		$master   = (bool) PSP_Options::get( 'enabled' );
		$default  = $licensed ? 'dashboard' : 'license';
		$active   = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap psp-wrap">
			<h1 class="psp-sr-only">PageSpeedPlus</h1>

			<header class="psp-masthead">
				<div class="psp-masthead-top">
					<div class="psp-brand">
						<span class="psp-brandmark">
							<img src="<?php echo esc_url( PSP_URL . 'assets/img/pagespeed-plus-logo.png' ); ?>" alt="PageSpeedPlus" width="300" height="164">
						</span>
						<span class="psp-version">v<?php echo esc_html( PSP_VERSION ); ?></span>
					</div>
					<div class="psp-masthead-actions">
						<?php if ( $licensed ) : ?>
							<?php if ( $master ) : ?>
								<a class="psp-btn psp-btn-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_toggle_master&state=off' ), 'psp_toggle_master' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Turn OFF all optimizations? The site will behave as if PageSpeedPlus were deactivated until you turn it back on.', 'pagespeedplus' ) ); ?>');">
									<?php esc_html_e( 'Disable All', 'pagespeedplus' ); ?>
								</a>
							<?php else : ?>
								<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_toggle_master&state=on' ), 'psp_toggle_master' ) ); ?>">
									<?php esc_html_e( 'Re-enable Optimizations', 'pagespeedplus' ); ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
						<a class="psp-btn <?php echo $master && $licensed ? 'psp-btn-accent' : 'psp-btn-ghost'; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_purge_all' ), 'psp_purge_all' ) ); ?>">
							<?php esc_html_e( 'Purge All', 'pagespeedplus' ); ?>
						</a>
					</div>
				</div>
				<nav class="psp-tabs">
					<?php foreach ( $tabs as $slug => $tab ) : ?>
						<a class="psp-tab <?php echo $slug === $active ? 'is-active' : ''; ?>"
							href="<?php echo esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=' . $slug ) ); ?>">
							<span class="dashicons <?php echo esc_attr( $tab[1] ); ?>"></span>
							<?php echo esc_html( $tab[0] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			</header>

			<div class="psp-content">
				<?php
				// Big, hard-to-miss warning: features are running but no API key is set.
				// (When unlicensed and not in dev mode the whole UI is locked instead.)
				if ( $licensed && '' === trim( (string) PSP_Options::get( 'psp_api_key' ) ) ) :
					?>
					<div class="psp-alert">
						<span class="dashicons dashicons-warning"></span>
						<div class="psp-alert-body">
							<strong><?php esc_html_e( 'No PageSpeedPlus API key set.', 'pagespeedplus' ); ?></strong>
							<p><?php esc_html_e( 'Your API key powers license validation, off-server cache warming, automatic Critical CSS and plugin updates. Without it, those features can\'t run. Add it on the License tab.', 'pagespeedplus' ); ?></p>
						</div>
						<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=license' ) ); ?>"><?php esc_html_e( 'Enter API Key', 'pagespeedplus' ); ?></a>
					</div>
					<?php
				endif;
				?>
				<?php if ( 'license' === $active ) : ?>
					<?php $this->render_license(); ?>
				<?php elseif ( ! $licensed ) : ?>
					<?php $this->render_locked(); ?>
				<?php elseif ( 'dashboard' === $active ) : ?>
					<?php $this->render_dashboard(); ?>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="psp_save_settings">
						<input type="hidden" name="tab" value="<?php echo esc_attr( $active ); ?>">
						<?php wp_nonce_field( 'psp_save_settings' ); ?>
						<div class="psp-card">
							<div class="psp-card-head">
								<span class="psp-kicker"><?php echo esc_html( $tabs[ $active ][0] ); ?></span>
								<h2><?php echo esc_html( $this->tab_heading( $active ) ); ?></h2>
							</div>
							<?php call_user_func( array( $this, 'render_' . $active ) ); ?>
						</div>
						<div class="psp-savebar">
							<span class="psp-hint"><?php esc_html_e( 'Saving purges all caches so changes apply immediately.', 'pagespeedplus' ); ?></span>
							<button type="submit" class="psp-btn psp-btn-accent"><?php esc_html_e( 'Save Changes', 'pagespeedplus' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<script>
		// Show a spinner + busy label on forms that hit the server (key/site checks).
		document.addEventListener( 'submit', function ( e ) {
			var f = e.target;
			if ( ! f.classList || ! f.classList.contains( 'psp-busy-form' ) ) { return; }
			var btn = f.querySelector( 'button[type="submit"]' );
			if ( ! btn || btn.dataset.pspBusy === '1' ) { return; }
			btn.dataset.pspBusy = '1';
			var label = btn.getAttribute( 'data-busy' ) || 'Working…';
			btn.innerHTML = '<span class="psp-spinner"></span>' + label;
			btn.classList.add( 'is-busy' );
		}, true );
		</script>
		<?php
	}

	/**
	 * The on/off optimization toggles, used for the "X of Y active" counter.
	 * Numeric/text settings and the master kill switch are intentionally excluded —
	 * this counts the optimizations a user actually flips on.
	 */
	private function toggle_settings() {
		return array(
			'cache_enabled',
			'cache_logged_in',
			'cache_mobile_separate',
			'cache_query_strings',
			'minify_html',
			'minify_css',
			'combine_css',
			'async_css',
			'content_visibility',
			'minify_js',
			'combine_js',
			'defer_js',
			'delay_js',
			'prefetch_links',
			'rum_enabled',
			'webp_enabled',
			'avif_enabled',
			'lazyload_images',
			'lazyload_iframes',
			'lazyload_bg',
			'lqip_enabled',
			'add_missing_dimensions',
			'youtube_facade',
			'preload_lcp_image',
			'font_display_swap',
			'preconnect_fonts',
			'self_host_fonts',
			'self_host_scripts',
			'auto_resource_hints',
			'disable_emojis',
			'disable_embeds',
			'disable_dashicons',
			'remove_query_strings',
			'disable_xmlrpc',
			'disable_jquery_migrate',
			'disable_block_css',
			'disable_comment_reply',
			'disable_feed_links',
			'disable_rest_head_link',
			'disable_rsd_wlw',
			'disable_shortlink',
			'disable_generator',
			'cdn_enabled',
			'preload_enabled',
			'browser_cache',
			'gzip_compression',
			'brotli_cache',
		);
	}

	private function render_dashboard() {
		$toggles = $this->toggle_settings();
		$total   = count( $toggles );
		$active  = 0;
		foreach ( $toggles as $key ) {
			if ( PSP_Options::get( $key ) ) {
				$active++;
			}
		}
		$pct = $total ? round( ( $active / $total ) * 100 ) : 0;
		?>
		<div class="psp-meter">
			<div class="psp-meter-figure" aria-hidden="true">
				<span class="psp-meter-active"><?php echo esc_html( number_format_i18n( $active ) ); ?></span><span class="psp-meter-total">/<?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<div class="psp-meter-body">
				<div class="psp-meter-title"><?php esc_html_e( 'Optimizations active', 'pagespeedplus' ); ?></div>
				<p class="psp-meter-sub">
					<?php
					printf(
						/* translators: 1: active count, 2: total count. */
						esc_html__( '%1$s of %2$s available settings are switched on. Enable one at a time and re-test.', 'pagespeedplus' ),
						'<strong>' . esc_html( number_format_i18n( $active ) ) . '</strong>',
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</p>
				<div class="psp-meter-bar"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
			</div>
		</div>

		<?php $this->site_connect_card(); ?>

		<div class="psp-card" style="margin-top:18px;">
			<div class="psp-card-head">
				<span class="psp-kicker"><?php esc_html_e( 'Safety', 'pagespeedplus' ); ?></span>
				<h2><?php esc_html_e( 'Disable Everything On Specific URLs', 'pagespeedplus' ); ?></h2>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="psp_save_settings">
				<input type="hidden" name="tab" value="dashboard">
				<?php wp_nonce_field( 'psp_save_settings' ); ?>
				<?php $this->textarea( 'disable_on_urls', __( 'URL Patterns', 'pagespeedplus' ), __( 'One pattern per line. Matching pages get NO caching and NO optimizations at all — served exactly as WordPress renders them. Use * as a wildcard, e.g. /admin* or /account/*. Ideal for custom dashboards, page builders, member areas, or any page that misbehaves. This is the targeted version of the global kill switch above.', 'pagespeedplus' ), 5 ); ?>
				<div style="padding:0 22px 18px;">
					<button type="submit" class="psp-btn psp-btn-accent"><?php esc_html_e( 'Save URL Rules', 'pagespeedplus' ); ?></button>
				</div>
			</form>
		</div>

		<div class="psp-card" style="margin-top:18px;">
			<div class="psp-card-head">
				<span class="psp-kicker"><?php esc_html_e( 'Backup', 'pagespeedplus' ); ?></span>
				<h2><?php esc_html_e( 'Import / Export Settings', 'pagespeedplus' ); ?></h2>
			</div>
			<div class="psp-row">
				<div class="psp-row-info">
					<h3><?php esc_html_e( 'Export', 'pagespeedplus' ); ?></h3>
					<p><?php esc_html_e( 'Download all PageSpeedPlus settings as a JSON file — handy for backups or copying config to another site.', 'pagespeedplus' ); ?></p>
				</div>
				<div class="psp-row-control">
					<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_export_settings' ), 'psp_export_settings' ) ); ?>"><?php esc_html_e( 'Export Settings', 'pagespeedplus' ); ?></a>
				</div>
			</div>
			<div class="psp-row">
				<div class="psp-row-info">
					<h3><?php esc_html_e( 'Import', 'pagespeedplus' ); ?></h3>
					<p><?php esc_html_e( 'Upload a previously exported JSON file. Only recognized settings are applied; your API key and site connection are part of the export, so review before importing onto a different site.', 'pagespeedplus' ); ?></p>
				</div>
				<div class="psp-row-control">
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="psp_import_settings">
						<?php wp_nonce_field( 'psp_import_settings' ); ?>
						<input type="file" name="psp_import_file" accept="application/json,.json" required>
						<button type="submit" class="psp-btn psp-btn-accent" style="margin-top:10px;"><?php esc_html_e( 'Import Settings', 'pagespeedplus' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_cache() {
		// Cache actions up top for quick access. (Purge is also always available
		// from the masthead button on every tab.) Preload uses the warmer settings below.
		$this->row_open( __( 'Purge all caches', 'pagespeedplus' ), __( 'Delete all cached pages and optimized CSS/JS files. Also available any time from the header button.', 'pagespeedplus' ) );
		?>
		<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_purge_all' ), 'psp_purge_all' ) ); ?>"><?php esc_html_e( 'Purge All Caches', 'pagespeedplus' ); ?></a>
		<?php
		$this->row_close();

		$this->row_open( __( 'Preload cache now', 'pagespeedplus' ), __( 'Crawl your sitemap in the background so visitors always get a cache hit. Uses the warmer settings below.', 'pagespeedplus' ) );
		?>
		<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_preload' ), 'psp_preload' ) ); ?>"><?php esc_html_e( 'Start Preloading', 'pagespeedplus' ); ?></a>
		<?php
		$this->row_close();

		$this->checkbox( 'cache_enabled', __( 'Enable Page Cache', 'pagespeedplus' ), __( 'Store fully rendered pages as static files and serve them before WordPress loads. The single biggest TTFB improvement.', 'pagespeedplus' ) );
		$this->number( 'cache_lifetime', __( 'Cache Lifetime (seconds)', 'pagespeedplus' ), __( 'How long cached pages stay valid. Default 36000 (10 hours).', 'pagespeedplus' ) );
		$this->checkbox( 'cache_mobile_separate', __( 'Separate Mobile Cache', 'pagespeedplus' ), __( 'Keep a separate cached copy for mobile devices. Required for mobile-specific themes/plugins.', 'pagespeedplus' ) );
		$this->checkbox( 'cache_logged_in', __( 'Cache for Logged-in Users', 'pagespeedplus' ), __( 'Usually off: logged-in users see personalized content.', 'pagespeedplus' ) );
		$this->checkbox( 'cache_query_strings', __( 'Cache Query Strings', 'pagespeedplus' ), __( 'Cache URLs with query strings. UTM/click-ID parameters are always treated as cacheable regardless.', 'pagespeedplus' ) );
		$this->textarea( 'cache_exclude_urls', __( 'Excluded URLs', 'pagespeedplus' ), __( 'One pattern per line. A URL containing a pattern is never cached. Use * as a wildcard, e.g. /admin* or /shop/*/cart. (Other optimizations still apply — use "Disable Everything On URLs" on the Dashboard for that.)', 'pagespeedplus' ) );
		$this->textarea( 'cache_exclude_cookies', __( 'Excluded Cookies', 'pagespeedplus' ), __( 'One pattern per line. Visitors holding a matching cookie bypass the cache (e.g. cart cookies).', 'pagespeedplus' ) );
		$this->checkbox( 'browser_cache', __( 'Browser Cache Headers', 'pagespeedplus' ), __( 'Write far-future expires headers for static assets to .htaccess (Apache/LiteSpeed only). On Nginx, .htaccess is ignored — use the Nginx Server Rules below instead.', 'pagespeedplus' ) );
		$this->checkbox( 'gzip_compression', __( 'GZIP Compression', 'pagespeedplus' ), __( 'Enable mod_deflate compression via .htaccess (Apache/LiteSpeed only). On Nginx this toggle has no effect — add the Nginx Server Rules below to your server block instead.', 'pagespeedplus' ) );
		$brotli_ok = function_exists( 'brotli_compress' );
		$this->checkbox( 'brotli_cache', __( 'Brotli Compression (cached pages)', 'pagespeedplus' ), $brotli_ok
			? __( 'Pre-compress cached HTML pages with Brotli (~15-20%% smaller than gzip) and serve them to supporting browsers; falls back to gzip otherwise. Works on any server — handled by the cache drop-in. Doubles the per-page cache footprint on disk.', 'pagespeedplus' )
			: __( 'Not available: your server doesn\'t have the PHP brotli extension. Ask your host to enable it, then turn this on. (Cached pages are still gzip-compressed.)', 'pagespeedplus' ), ! $brotli_ok );

		// Server-side equivalent of the two toggles above, for Nginx users.
		$this->row_open( __( 'Nginx Server Rules', 'pagespeedplus' ), __( 'On Apache/LiteSpeed the rules above are written to .htaccess automatically. On Nginx, .htaccess is ignored — copy this into your server block for browser caching and compression.', 'pagespeedplus' ) );
		?>
		<pre class="psp-pre"><?php echo esc_html( PSP_Htaccess::nginx_rules() ); ?></pre>
		<?php
		$this->row_close();

		// Cache warming — keep it with the cache settings it warms.
		$this->checkbox( 'preload_enabled', __( 'Auto-Warm After Purge', 'pagespeedplus' ), __( 'After a FULL cache purge, automatically re-warm using the Warming Scope below (full site or monitored URLs). This does not warm individual pages — when a single post is edited, only its own cache is cleared and it\'s re-cached on the next visit.', 'pagespeedplus' ) );
		$this->select( 'warmer_mode', __( 'Cache Warmer', 'pagespeedplus' ), array(
			'local'         => __( 'Local crawler (WP-Cron, on this server)', 'pagespeedplus' ),
			'pagespeedplus' => __( 'PageSpeedPlus (off-server, recommended)', 'pagespeedplus' ),
		), __( 'PageSpeedPlus runs the crawler on our servers and requests your public URLs from up to 13 locations — no WP-Cron load and no loopback self-requests here, and we pace the crawl externally. Off-server warming targets the PageSpeedPlus site you connect on the Dashboard. Your server still renders each page once to fill the cache (that is how warming works). Needs a publicly reachable site (not localhost/.test). The local crawler does the same job via WP-Cron on this server instead.', 'pagespeedplus' ) );
		$this->select( 'warm_scope', __( 'Warming Scope', 'pagespeedplus' ), array(
			'full'      => __( 'Full site (entire sitemap)', 'pagespeedplus' ),
			'monitored' => __( 'Monitored URLs only', 'pagespeedplus' ),
		), __( 'Full site warms every URL in your sitemap; Monitored URLs warms just the high-value pages you track in PageSpeedPlus.', 'pagespeedplus' ) );

		// Recurring/scheduled warming + warm regions are configured in the PSP
		// app, not in the plugin — the plugin only fires one-off warms.
		$warm_site = (string) PSP_Options::get( 'psp_site_id' );
		$this->row_open( __( 'Scheduled Warms', 'pagespeedplus' ), __( '"Start Preloading" above triggers a one-off warm. Recurring schedules and the warm regions (which of the global locations run) are set up in your PageSpeedPlus dashboard, not here.', 'pagespeedplus' ) );
		if ( $warm_site ) {
			$warm_url = sprintf( apply_filters( 'psp_cache_warm_url', 'https://app.pagespeedplus.com/site/%d' ), (int) $warm_site );
			echo '<a class="psp-btn" href="' . esc_url( $warm_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Manage scheduled warms in PageSpeedPlus ↗', 'pagespeedplus' ) . '</a>';
		} else {
			echo '<span class="psp-pill is-off">' . esc_html__( 'No site connected', 'pagespeedplus' ) . '</span> <span class="description">' . esc_html__( 'Connect a site on the Dashboard to manage scheduled warms.', 'pagespeedplus' ) . '</span>';
		}
		$this->row_close();

		$this->text( 'preload_sitemap', __( 'Sitemap URL (local crawler)', 'pagespeedplus' ), __( 'Used by the local crawler only. Leave empty to use the default wp-sitemap.xml.', 'pagespeedplus' ) );
	}

	private function render_css() {
		$this->checkbox( 'minify_css', __( 'Minify CSS', 'pagespeedplus' ), __( 'Strip comments and whitespace from local stylesheets.', 'pagespeedplus' ) );
		$this->checkbox( 'combine_css', __( 'Combine CSS', 'pagespeedplus' ), __( 'Merge local stylesheets into one file. Test carefully — most beneficial on HTTP/1.1, can hurt on HTTP/2.', 'pagespeedplus' ) );
		$this->checkbox( 'async_css', __( 'Load CSS Asynchronously', 'pagespeedplus' ), __( 'Eliminates render-blocking CSS. Provide Critical CSS below to avoid a flash of unstyled content.', 'pagespeedplus' ) );
		$this->ccss_status_row();
		$this->textarea( 'critical_css', __( 'Manual Critical CSS (fallback)', 'pagespeedplus' ), __( 'Paste raw CSS only — do NOT include <style> tags; PageSpeedPlus adds them. Inlined in <head> when Async CSS is on and no generated CSS exists for the page type.', 'pagespeedplus' ), 10, "body{margin:0}\n.site-header{background:#fff}\nh1{font-size:2rem;line-height:1.2}" );
		$this->textarea( 'css_exclude', __( 'Excluded Stylesheets', 'pagespeedplus' ), __( 'One pattern per line, matched anywhere in the stylesheet <link> tag (its handle/id, URL or filename) — a fragment is enough, no full URL needed. Matching stylesheets are left untouched.', 'pagespeedplus' ), 4, "dashicons\nwp-block-library\nelementor-icons" );
		$this->checkbox( 'content_visibility', __( 'Content Visibility', 'pagespeedplus' ), __( 'Add content-visibility:auto to below-the-fold sections so the browser skips rendering them until scrolled near — faster initial render. Only apply it to containers that are reliably off-screen.', 'pagespeedplus' ) );
		$this->textarea( 'content_visibility_selectors', __( 'Content Visibility Selectors', 'pagespeedplus' ), __( 'One CSS selector per line (e.g. footer, #comments, .site-footer). Avoid above-the-fold or sticky elements.', 'pagespeedplus' ) );
		$this->number( 'content_visibility_size', __( 'Reserved Height (px)', 'pagespeedplus' ), __( 'Estimated height reserved for each skipped section to prevent layout shift. Default 600.', 'pagespeedplus' ) );
	}

	private function ccss_status_row() {
		// Automatic generation depends on the PageSpeedPlus cloud service, which
		// isn't live yet. Until then, hide the generate UI and point people at the
		// manual Critical CSS box below (which works today).
		if ( ! PSP_Critical_CSS::backend_available() ) {
			$this->row_open( __( 'Automatic Critical CSS', 'pagespeedplus' ), __( 'Automatic per-page-type generation is coming soon. For now, paste your own critical CSS in the box below to pair with Async CSS.', 'pagespeedplus' ) );
			echo '<span class="psp-pill is-off">' . esc_html__( 'Coming soon', 'pagespeedplus' ) . '</span>';
			$this->row_close();
			return;
		}

		$status  = PSP_Critical_CSS::status();
		$pending = PSP_Critical_CSS::pending_count();

		$this->row_open( __( 'Generated Critical CSS', 'pagespeedplus' ), __( 'Per-page-type critical CSS generated from your live pages. Uses your PageSpeedPlus API key from the License tab.', 'pagespeedplus' ) );
		?>
		<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_generate_ccss' ), 'psp_generate_ccss' ) ); ?>"><?php esc_html_e( 'Generate Critical CSS Now', 'pagespeedplus' ); ?></a>
		<?php if ( $pending ) : ?>
			<span class="description"> <?php printf( esc_html__( '%d page types still in queue…', 'pagespeedplus' ), (int) $pending ); ?></span>
		<?php endif; ?>
		<?php if ( $status ) : ?>
			<table class="psp-mini-table">
				<thead><tr><th><?php esc_html_e( 'Page Type', 'pagespeedplus' ); ?></th><th><?php esc_html_e( 'Status', 'pagespeedplus' ); ?></th><th><?php esc_html_e( 'Updated', 'pagespeedplus' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $status as $context => $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $context ); ?></code></td>
						<td><?php echo ! empty( $row['error'] ) ? '<span class="psp-pill is-off">' . esc_html( $row['error'] ) . '</span>' : ( ! empty( $row['css'] ) ? '<span class="psp-pill is-on">✓ ' . esc_html( size_format( strlen( $row['css'] ) ) ) . '</span>' : '—' ); ?></td>
						<td><?php echo ! empty( $row['updated'] ) ? esc_html( human_time_diff( $row['updated'] ) . ' ' . __( 'ago', 'pagespeedplus' ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<span class="description"><?php esc_html_e( 'Nothing generated yet. Add your API key, save, then click Generate.', 'pagespeedplus' ); ?></span>
		<?php endif; ?>
		<?php
		$this->row_close();
	}

	private function render_js() {
		$this->checkbox( 'minify_js', __( 'Minify JavaScript', 'pagespeedplus' ), __( 'Conservatively strip comments/whitespace from local, non-minified scripts.', 'pagespeedplus' ) );
		$this->checkbox( 'combine_js', __( 'Combine JavaScript', 'pagespeedplus' ), __( 'Merge local scripts into one file. Test carefully.', 'pagespeedplus' ) );
		$this->checkbox( 'defer_js', __( 'Defer JavaScript', 'pagespeedplus' ), __( 'Add the defer attribute to scripts so they don\'t block rendering.', 'pagespeedplus' ) );
		$this->textarea( 'defer_js_exclude', __( 'Defer Exclusions', 'pagespeedplus' ), __( 'One pattern per line, matched as a substring of the whole script tag — a filename fragment like jquery.min.js is enough (no full URL needed). jQuery is excluded by default because inline scripts often depend on it.', 'pagespeedplus' ), 4, "jquery.min.js\nrecaptcha\n/wp-includes/js/" );
		$this->checkbox( 'delay_js', __( 'Delay JavaScript Until Interaction', 'pagespeedplus' ), __( 'The biggest Total Blocking Time win: no JS runs until the visitor moves the mouse, scrolls, taps or types. Test your site thoroughly after enabling.', 'pagespeedplus' ) );
		$this->number( 'delay_js_timeout', __( 'Delay Timeout (seconds)', 'pagespeedplus' ), __( 'Force-load scripts after this many seconds even without interaction. 0 (recommended) = load only on interaction; a timeout can fire during a Lighthouse test and hurt your TBT score.', 'pagespeedplus' ) );
		$this->textarea( 'delay_js_exclude', __( 'Delay Exclusions', 'pagespeedplus' ), __( 'One pattern per line, matched as a substring of the script tag — a filename fragment is enough. Exclude consent banners and above-the-fold sliders.', 'pagespeedplus' ), 4, "cookie-consent\nslider\nrecaptcha" );
		$this->checkbox( 'prefetch_links', __( 'Prefetch Links on Hover', 'pagespeedplus' ), __( 'Preload the next page the moment a visitor hovers (desktop) or taps (mobile) a link, so navigation feels instant. Skips external links, downloads, cart/checkout/admin, query-string and nofollow links; respects Save-Data and slow connections.', 'pagespeedplus' ) );
		$this->textarea( 'prefetch_exclude', __( 'Prefetch Exclusions', 'pagespeedplus' ), __( 'One URL path fragment per line to never prefetch. Add data-no-prefetch to a specific link to skip it.', 'pagespeedplus' ), 4, "/cart\n/checkout\n/my-account\n?add-to-cart" );

		// Self-host third-party scripts.
		$this->checkbox( 'self_host_scripts', __( 'Self-Host Third-Party Scripts', 'pagespeedplus' ), __( 'Download the external scripts listed below to your server and serve them locally — removes the third-party request/DNS and fixes the "serve static assets with an efficient cache policy" warning for them. Fetched in the background and refreshed every 12h.', 'pagespeedplus' ) );
		if ( PSP_Options::get( 'self_host_scripts' ) ) {
			$hosted = PSP_Scripts::hosted_count();
			$this->row_open( __( 'Self-Hosted Scripts Status', 'pagespeedplus' ), __( 'External scripts currently downloaded and served from your server.', 'pagespeedplus' ) );
			if ( $hosted ) {
				echo '<span class="psp-pill is-ok">' . sprintf( esc_html( _n( '%d script self-hosted', '%d scripts self-hosted', $hosted, 'pagespeedplus' ) ), (int) $hosted ) . '</span>';
			} else {
				echo '<span class="psp-pill is-off">' . esc_html__( 'None yet', 'pagespeedplus' ) . '</span> <span class="description">' . esc_html__( 'Add URLs below, save, then load a front-end page to trigger the download.', 'pagespeedplus' ) . '</span>';
			}
			$this->row_close();
		}
		$this->textarea( 'self_host_scripts_urls', __( 'Scripts to Self-Host', 'pagespeedplus' ), __( 'One external script URL (or a matching fragment) per line. Any <script src> containing a line is downloaded and served locally. Best for analytics/tag scripts. Test that tracking still works after enabling.', 'pagespeedplus' ), 4, "https://www.googletagmanager.com/gtag/js\nhttps://www.google-analytics.com/analytics.js" );

		// Script Manager — dequeue specific assets, optionally per-URL.
		$this->textarea( 'script_manager_rules', __( 'Script Manager', 'pagespeedplus' ), __( 'Dequeue scripts/styles by their WordPress handle. One rule per line: "handle" disables it everywhere; "handle | /path*" disables it only on matching URL paths (* wildcard). Removes both the script and style for that handle. Find handles in your page source or with Query Monitor.', 'pagespeedplus' ), 4, "jquery-ui-core\nwp-block-library | /landing*\nwpforms-full | /shop*" );
	}

	private function render_media() {
		$this->checkbox( 'lazyload_images', __( 'Lazy Load Images', 'pagespeedplus' ), __( 'Native loading="lazy" for below-the-fold images.', 'pagespeedplus' ) );
		$this->number( 'lazyload_skip_first', __( 'Skip First N Images', 'pagespeedplus' ), __( 'Above-the-fold images load eagerly; the first gets fetchpriority="high" (LCP optimization).', 'pagespeedplus' ) );
		$this->checkbox( 'preload_lcp_image', __( 'Preload LCP Image', 'pagespeedplus' ), __( 'Add a <link rel="preload"> for the first image on the page.', 'pagespeedplus' ) );
		$this->checkbox( 'add_missing_dimensions', __( 'Add Missing Image Dimensions', 'pagespeedplus' ), __( 'Add width/height attributes to prevent layout shift (CLS).', 'pagespeedplus' ) );
		$this->checkbox( 'lazyload_iframes', __( 'Lazy Load Iframes', 'pagespeedplus' ), __( 'Native lazy loading for iframes (maps, videos, embeds).', 'pagespeedplus' ) );
		$this->checkbox( 'lazyload_bg', __( 'Lazy Load Background Images', 'pagespeedplus' ), __( 'Defer inline-style CSS background images (e.g. hero sections) until they near the viewport, via a tiny IntersectionObserver script. Add an above-the-fold hero to the exclusion list below so it isn\'t deferred (it would hurt LCP). Only catches inline background-image styles, not those set in CSS files.', 'pagespeedplus' ) );
		$this->checkbox( 'lqip_enabled', __( 'Blurry Placeholders (LQIP)', 'pagespeedplus' ), __( 'Show a tiny blurred preview behind lazy-loaded images while the full image loads, reducing the "empty box" effect. Placeholders are generated on upload (needs GD); existing images get them as they\'re re-uploaded or bulk-processed.', 'pagespeedplus' ) );
		$this->checkbox( 'youtube_facade', __( 'YouTube Facade', 'pagespeedplus' ), __( 'Replace YouTube embeds with a lightweight thumbnail; the player loads on click. Saves 500KB+ per embed.', 'pagespeedplus' ) );
		$this->textarea( 'lazyload_exclude', __( 'Lazy Load Exclusions', 'pagespeedplus' ), __( 'One pattern per line, matched anywhere in the tag — a filename fragment is enough. Matching images/iframes are never lazy-loaded.', 'pagespeedplus' ), 4, "logo.svg\nhero-banner.jpg\n/wp-content/uploads/icons/" );

		$support = PSP_WebP::support();
		$names   = array( 'imagick' => 'Imagick', 'gd' => 'GD' );
		$engine  = $support['webp'] ? $support['webp'] : $support['avif'];
		$this->row_open( __( 'Image Engine', 'pagespeedplus' ), __( 'The server library PageSpeedPlus uses to generate next-gen image copies. WebP and AVIF need it — without it those options below are disabled.', 'pagespeedplus' ) );
		if ( $engine ) {
			$formats = array();
			if ( $support['webp'] ) {
				$formats[] = 'WebP';
			}
			if ( $support['avif'] ) {
				$formats[] = 'AVIF';
			}
			echo '<span class="psp-pill is-ok">' . esc_html( isset( $names[ $engine ] ) ? $names[ $engine ] : strtoupper( $engine ) ) . '</span> ';
			echo '<span class="description">' . sprintf( esc_html__( 'Ready for %s.', 'pagespeedplus' ), esc_html( implode( ' + ', $formats ) ) ) . '</span>';
		} else {
			echo '<span class="psp-pill is-off">' . esc_html__( 'None installed', 'pagespeedplus' ) . '</span> ';
			echo '<span class="description">' . esc_html__( 'Neither Imagick nor GD with WebP support is available. Ask your host to enable the Imagick or GD PHP extension.', 'pagespeedplus' ) . '</span>';
		}
		$this->row_close();

		$this->checkbox( 'webp_enabled', __( 'WebP Conversion', 'pagespeedplus' ), $support['webp']
			? sprintf( __( 'Create WebP copies of JPEG/PNG images (typically 25–35%% smaller) and serve them automatically. Engine: %s.', 'pagespeedplus' ), strtoupper( $support['webp'] ) )
			: __( 'Not available: your server has neither Imagick nor GD with WebP support. Ask your host to enable the Imagick or GD PHP extension.', 'pagespeedplus' ), ! $support['webp'] );
		$this->number( 'webp_quality', __( 'WebP/AVIF Quality', 'pagespeedplus' ), __( '1–100. Default 82 is visually lossless for most photos.', 'pagespeedplus' ) );
		$this->checkbox( 'avif_enabled', __( 'AVIF Conversion', 'pagespeedplus' ), $support['avif']
			? sprintf( __( 'Also create AVIF copies (~50%% smaller than JPEG). Served only to browsers that support it. Engine: %s.', 'pagespeedplus' ), strtoupper( $support['avif'] ) )
			: __( 'Not available: your server image library lacks AVIF support (needs Imagick with AVIF, or PHP 8.1+ with GD).', 'pagespeedplus' ), ! $support['avif'] );
		$this->webp_bulk_row();
	}

	private function webp_bulk_row() {
		$pending = PSP_WebP::pending_count();

		$this->row_open( __( 'Convert Existing Images', 'pagespeedplus' ), __( 'New uploads convert automatically. This converts the existing Media Library in background batches.', 'pagespeedplus' ) );
		?>
		<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_webp_bulk' ), 'psp_webp_bulk' ) ); ?>"><?php esc_html_e( 'Convert Media Library Now', 'pagespeedplus' ); ?></a>
		<?php if ( $pending ) : ?>
			<span class="description"><?php printf( esc_html__( '%d images still in queue…', 'pagespeedplus' ), (int) $pending ); ?></span>
		<?php endif; ?>
		<?php
		$this->row_close();
	}

	private function render_fonts() {
		$this->checkbox( 'font_display_swap', __( 'Font Display Swap', 'pagespeedplus' ), __( 'Add display=swap to Google Fonts so text renders immediately with a fallback font.', 'pagespeedplus' ) );
		$this->checkbox( 'preconnect_fonts', __( 'Preconnect to Font Origins', 'pagespeedplus' ), __( 'Add preconnect hints for fonts.googleapis.com / fonts.gstatic.com when Google Fonts are detected.', 'pagespeedplus' ) );
		$this->checkbox( 'self_host_fonts', __( 'Self-Host Google Fonts', 'pagespeedplus' ), __( 'Download Google Fonts to your server and serve them locally — removes the render-blocking request to fonts.googleapis.com / fonts.gstatic.com (faster, and GDPR-friendly). Fonts are fetched in the background on first load; the original Google request is used until the local copy is ready.', 'pagespeedplus' ) );
		if ( PSP_Options::get( 'self_host_fonts' ) ) {
			$hosted = PSP_Fonts::hosted_count();
			$this->row_open( __( 'Self-Hosted Status', 'pagespeedplus' ), __( 'Google Fonts stylesheets currently downloaded and served from your server.', 'pagespeedplus' ) );
			if ( $hosted ) {
				echo '<span class="psp-pill is-ok">' . sprintf( esc_html( _n( '%d stylesheet self-hosted', '%d stylesheets self-hosted', $hosted, 'pagespeedplus' ) ), (int) $hosted ) . '</span>';
			} else {
				echo '<span class="psp-pill is-off">' . esc_html__( 'None yet', 'pagespeedplus' ) . '</span> <span class="description">' . esc_html__( 'Visit a front-end page using Google Fonts to trigger the background download.', 'pagespeedplus' ) . '</span>';
			}
			$this->row_close();
		}
		$this->textarea( 'preload_fonts', __( 'Preload Fonts', 'pagespeedplus' ), __( 'One font file URL per line (woff2 recommended). Use for your theme\'s main text/heading fonts.', 'pagespeedplus' ), 4, "https://www.example.com/wp-content/themes/your-theme/fonts/inter-regular.woff2\nhttps://www.example.com/wp-content/themes/your-theme/fonts/inter-bold.woff2" );
		$this->textarea( 'preconnect', __( 'Preconnect Origins', 'pagespeedplus' ), __( 'One origin per line (e.g. https://cdn.example.com) to establish early connections. A bare host like cdn.example.com also works — only the origin is used.', 'pagespeedplus' ), 4, "https://fonts.gstatic.com\nhttps://cdn.example.com" );
		$this->textarea( 'dns_prefetch', __( 'DNS Prefetch Origins', 'pagespeedplus' ), __( 'One origin per line for lower-priority third parties (e.g. https://www.googletagmanager.com).', 'pagespeedplus' ), 4, "https://www.googletagmanager.com\nhttps://www.google-analytics.com" );
		$this->checkbox( 'auto_resource_hints', __( 'Auto DNS-Prefetch Third Parties', 'pagespeedplus' ), __( 'Automatically add a dns-prefetch hint for every external host referenced on the page (scripts, styles, images), so DNS lookups start early. Cheap and safe; preconnect stays manual above (over-preconnecting hurts).', 'pagespeedplus' ) );
	}

	private function render_tweaks() {
		$this->checkbox( 'minify_html', __( 'Minify HTML', 'pagespeedplus' ), __( 'Remove comments and collapse whitespace in the page HTML.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_emojis', __( 'Disable Emoji Script', 'pagespeedplus' ), __( 'Removes the wp-emoji script (~10KB) — modern browsers render emoji natively.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_embeds', __( 'Disable WordPress Embeds', 'pagespeedplus' ), __( 'Removes wp-embed.js if you don\'t embed other WordPress posts.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_dashicons', __( 'Disable Dashicons for Visitors', 'pagespeedplus' ), __( 'Dashicons are only needed for logged-in users in most themes.', 'pagespeedplus' ) );
		$this->checkbox( 'remove_query_strings', __( 'Remove Query Strings from Assets', 'pagespeedplus' ), __( 'Strips ?ver= from CSS/JS URLs for proxy caching.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_xmlrpc', __( 'Disable XML-RPC', 'pagespeedplus' ), __( 'Reduces attack surface and bot load if you don\'t use remote publishing.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_jquery_migrate', __( 'Disable jQuery Migrate', 'pagespeedplus' ), __( 'Removes the jquery-migrate compatibility script on the front end. Safe unless an old theme/plugin relies on deprecated jQuery APIs — test after enabling.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_block_css', __( 'Disable Block (Gutenberg) CSS', 'pagespeedplus' ), __( 'Dequeues the block-library, global-styles and classic-theme stylesheets on the front end. Big win on classic themes that don\'t use blocks — but it CAN break block/FSE themes, so test thoroughly.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_comment_reply', __( 'Conditionally Remove comment-reply.js', 'pagespeedplus' ), __( 'Loads the threaded-comment-reply script only on singular pages that actually have threaded comments open.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_feed_links', __( 'Remove Feed Links', 'pagespeedplus' ), __( 'Removes the RSS/Atom feed auto-discovery <link> tags from the <head>. Feeds still work at their URLs.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_rest_head_link', __( 'Remove REST API Head Link', 'pagespeedplus' ), __( 'Removes the REST API discovery <link> from the <head>. The REST API itself keeps working (the editor/admin are unaffected).', 'pagespeedplus' ) );
		$this->checkbox( 'disable_rsd_wlw', __( 'Remove RSD / WLW Links', 'pagespeedplus' ), __( 'Removes the Really Simple Discovery and Windows Live Writer manifest <link> tags — only needed by legacy blog clients.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_shortlink', __( 'Remove Shortlink', 'pagespeedplus' ), __( 'Removes the wp-shortlink <link> tag and HTTP header.', 'pagespeedplus' ) );
		$this->checkbox( 'disable_generator', __( 'Remove WordPress Version', 'pagespeedplus' ), __( 'Removes the generator meta tag that advertises your WordPress version (minor hardening).', 'pagespeedplus' ) );
		$this->select( 'heartbeat_dashboard', __( 'Heartbeat: Dashboard', 'pagespeedplus' ), array( 'default' => __( 'Default', 'pagespeedplus' ), 'slow' => __( 'Slow (120s)', 'pagespeedplus' ), 'disable' => __( 'Disable', 'pagespeedplus' ) ), __( "WordPress' Heartbeat API polls admin-ajax.php on a timer to power live features (notifications, plugin badges). This controls the polling rate on wp-admin screens. Slow it to cut background CPU/AJAX load on busy dashboards.", 'pagespeedplus' ) );
		$this->select( 'heartbeat_editor', __( 'Heartbeat: Post Editor', 'pagespeedplus' ), array( 'default' => __( 'Default', 'pagespeedplus' ), 'slow' => __( 'Slow (120s)', 'pagespeedplus' ), 'disable' => __( 'Disable (turns off autosave!)', 'pagespeedplus' ) ), __( 'Heartbeat in the post editor drives autosave and post-lock detection. Leave on Default unless you have a reason not to — disabling turns OFF autosave and revision saving while editing.', 'pagespeedplus' ) );
		$this->select( 'heartbeat_frontend', __( 'Heartbeat: Frontend', 'pagespeedplus' ), array( 'default' => __( 'Default', 'pagespeedplus' ), 'slow' => __( 'Slow (120s)', 'pagespeedplus' ), 'disable' => __( 'Disable', 'pagespeedplus' ) ), __( 'Heartbeat on public-facing pages is rarely needed and just adds admin-ajax.php requests. Disabling is safe for most sites (a few membership/e-commerce plugins use it — re-enable if something breaks).', 'pagespeedplus' ) );
	}

	private function render_cdn() {
		$this->checkbox( 'cdn_enabled', __( 'Enable CDN Rewriting', 'pagespeedplus' ), __( 'Rewrite static asset URLs to your CDN hostname. Requires a CDN URL below.', 'pagespeedplus' ) );
		$this->text( 'cdn_url', __( 'CDN URL', 'pagespeedplus' ), __( 'e.g. cdn.example.com or https://cdn.example.com', 'pagespeedplus' ) );
		$this->textarea( 'cdn_included_dirs', __( 'Included Directories', 'pagespeedplus' ), __( 'One per line. URLs inside these directories are rewritten.', 'pagespeedplus' ), 4, "wp-content/uploads\nwp-content/themes\nwp-includes" );
		$this->textarea( 'cdn_exclude', __( 'Exclusions', 'pagespeedplus' ), __( 'One pattern per line. Matching URLs are never rewritten.', 'pagespeedplus' ), 4, ".php\nwp-content/uploads/private\nsitemap" );
		?>
		<script>
		// Make the CDN URL mandatory whenever CDN rewriting is switched on, so the
		// browser blocks the save with a native prompt (server also enforces this).
		( function () {
			var box = document.querySelector( 'input[type=checkbox][name="psp[cdn_enabled]"]' );
			var url = document.querySelector( 'input[name="psp[cdn_url]"]' );
			if ( ! box || ! url ) { return; }
			function sync() {
				url.required = box.checked;
				if ( box.checked && ! url.value.trim() ) {
					url.setCustomValidity( '<?php echo esc_js( __( 'Enter a CDN URL before enabling CDN rewriting.', 'pagespeedplus' ) ); ?>' );
				} else {
					url.setCustomValidity( '' );
				}
			}
			box.addEventListener( 'change', sync );
			url.addEventListener( 'input', sync );
			sync();
		} )();
		</script>
		<?php
	}

	private function render_webvitals() {
		$this->checkbox( 'rum_enabled', __( 'Real User Monitoring', 'pagespeedplus' ), __( 'Add the PageSpeedPlus web-vitals beacon to every page so real visitors\' Core Web Vitals (LCP, CLS, INP, TTFB) are reported to your PageSpeedPlus dashboard. Runs once per pageview, after load, and never blocks rendering.', 'pagespeedplus' ) );

		// Read-only: RUM follows whatever site is connected on the Dashboard.
		$site_id = (int) PSP_Options::get( 'psp_site_id' );
		if ( $site_id > 0 ) {
			$cs    = PSP_License::connected_site();
			$label = ! empty( $cs['label'] ) ? $cs['label'] : ( 'Site #' . $site_id );
			$this->row_open( __( 'Your Web Vitals Report', 'pagespeedplus' ), __( 'Real-visitor data is reported against the site connected on the Dashboard.', 'pagespeedplus' ) );
			$rum_url = sprintf( apply_filters( 'psp_rum_dashboard_url', 'https://app.pagespeedplus.com/site/%d/rum' ), $site_id );
			echo '<a class="psp-btn psp-btn-accent" href="' . esc_url( $rum_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View Web Vitals report ↗', 'pagespeedplus' ) . '</a>';
			echo '<span class="description" style="display:block;margin-top:10px;">' . esc_html( sprintf( __( 'Reporting against %1$s (site_id %2$d).', 'pagespeedplus' ), $label, $site_id ) ) . '</span>';
			$this->row_close();
		}

		$this->row_open( __( 'Privacy', 'pagespeedplus' ), '' );
		echo '<p class="description" style="margin:0;">' . esc_html__( 'The beacon sends performance timings, the page path and the visitor\'s user agent to PageSpeedPlus. No personal data or cookies. Disclose it in your privacy policy if required in your region.', 'pagespeedplus' ) . '</p>';
		$this->row_close();
	}

	private function render_license() {
		$display = PSP_License::status_display();
		$state   = PSP_License::state();
		$key     = (string) PSP_Options::get( 'psp_api_key' );
		$masked  = $key ? substr( $key, 0, 4 ) . str_repeat( '•', max( 0, strlen( $key ) - 8 ) ) . substr( $key, -4 ) : '';
		$active  = 'active' === $display['status'];
		$has_key = '' !== trim( $key ); // Whether a key has been entered (independent of dev-mode "active").
		?>
		<div class="psp-card">
			<div class="psp-card-head">
				<span class="psp-kicker"><?php esc_html_e( 'Account', 'pagespeedplus' ); ?></span>
				<h2><?php esc_html_e( 'PageSpeedPlus Account', 'pagespeedplus' ); ?></h2>
			</div>
			<div class="psp-row">
				<div class="psp-row-info">
					<h3><?php esc_html_e( 'Status', 'pagespeedplus' ); ?></h3>
					<p><?php esc_html_e( 'Optimizations run while your API key is valid. The same key powers cache warming, Critical CSS and plugin updates.', 'pagespeedplus' ); ?></p>
				</div>
				<div class="psp-row-control">
					<span class="psp-pill <?php echo $active ? 'is-ok' : 'is-off'; ?>"><?php echo $active ? esc_html__( 'Connected', 'pagespeedplus' ) : esc_html__( 'Disconnected', 'pagespeedplus' ); ?></span>
					<?php
					// Supporting note: show the human-readable detail (e.g. "Invalid key:
					// …", "Enter your API key…"). Suppressed in dev mode — the green
					// "Connected" pill is all the user needs, no internal flag exposed.
					$is_dev = defined( 'PSP_LICENSE_DEV' ) && PSP_LICENSE_DEV;
					$note   = $is_dev ? '' : $display['detail'];
					?>
					<?php if ( $note ) : ?><span class="description"><?php echo esc_html( $note ); ?></span><?php endif; ?>
				</div>
			</div>
			<form method="post" class="psp-busy-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="psp_activate_license">
				<?php wp_nonce_field( 'psp_activate_license' ); ?>
				<div class="psp-row">
					<div class="psp-row-info">
						<h3><?php esc_html_e( 'API Key', 'pagespeedplus' ); ?></h3>
						<p><?php esc_html_e( 'Find your key under Account → API Tokens at app.pagespeedplus.com.', 'pagespeedplus' ); ?></p>
					</div>
					<div class="psp-row-control">
						<input type="text" name="psp_license_key" autocomplete="off" spellcheck="false"
							placeholder="<?php echo esc_attr( $masked ? $masked : 'paste your API key' ); ?>" value="">
						<p class="description" style="margin-top:10px;">
							<button type="submit" class="psp-btn psp-btn-accent" data-busy="<?php esc_attr_e( 'Checking your key…', 'pagespeedplus' ); ?>"><?php echo $has_key ? esc_html__( 'Re-check / Change Key', 'pagespeedplus' ) : esc_html__( 'Validate & Connect', 'pagespeedplus' ); ?></button>
							<?php if ( $has_key ) : ?>
								<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_deactivate_license' ), 'psp_deactivate_license' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect this site? Optimizations will stop until a key is connected again.', 'pagespeedplus' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'pagespeedplus' ); ?></a>
							<?php endif; ?>
							<a class="psp-btn" href="https://app.pagespeedplus.com/user/api-tokens" target="_blank" rel="noopener"><?php esc_html_e( 'Get a Key', 'pagespeedplus' ); ?></a>
						</p>
					</div>
				</div>
			</form>

		</div>
		<?php
	}

	/**
	 * Site-connection picker — lives on the Dashboard home. Choose which
	 * PageSpeedPlus site this install maps to (sets psp_site_id), or create one.
	 */
	private function site_connect_card() {
		$sites   = PSP_License::sites();
		$site_id = (string) PSP_Options::get( 'psp_site_id' );
		$has_key = '' !== trim( (string) PSP_Options::get( 'psp_api_key' ) );
		?>
		<div class="psp-card" style="margin-top:18px;">
			<div class="psp-card-head">
				<span class="psp-kicker"><?php esc_html_e( 'Connection', 'pagespeedplus' ); ?></span>
				<h2><?php esc_html_e( 'Connected PageSpeedPlus Site', 'pagespeedplus' ); ?></h2>
			</div>
			<?php if ( ! $has_key ) : ?>
				<div class="psp-card-body">
					<p><?php esc_html_e( 'Add your API key on the License tab first, then choose which of your PageSpeedPlus sites this install maps to.', 'pagespeedplus' ); ?></p>
					<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=license' ) ); ?>"><?php esc_html_e( 'Enter API Key', 'pagespeedplus' ); ?></a>
				</div>
			<?php else : ?>
				<form method="post" class="psp-busy-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="psp_connect_site">
					<?php wp_nonce_field( 'psp_connect_site' ); ?>
					<div class="psp-row">
						<div class="psp-row-info">
							<h3><?php esc_html_e( 'Site', 'pagespeedplus' ); ?></h3>
							<p><?php esc_html_e( 'This is the site cache warming runs against. Pick the one that matches this install.', 'pagespeedplus' ); ?></p>
						</div>
						<div class="psp-row-control">
							<?php if ( $sites ) : ?>
								<div class="psp-field-row">
									<select name="psp_site_id">
										<option value=""><?php esc_html_e( '— Select a site —', 'pagespeedplus' ); ?></option>
										<?php foreach ( $sites as $site ) : ?>
											<option value="<?php echo esc_attr( $site['id'] ); ?>" <?php selected( $site_id, $site['id'] ); ?>>
												<?php echo esc_html( $site['label'] . ( $site['url'] && $site['label'] !== $site['url'] ? ' (' . $site['url'] . ')' : '' ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="psp-btn psp-btn-accent" data-busy="<?php esc_attr_e( 'Connecting…', 'pagespeedplus' ); ?>"><?php esc_html_e( 'Connect Site', 'pagespeedplus' ); ?></button>
									<a class="psp-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_refresh_sites' ), 'psp_refresh_sites' ) ); ?>" title="<?php esc_attr_e( 'Re-fetch your sites from PageSpeedPlus', 'pagespeedplus' ); ?>">↻ <?php esc_html_e( 'Refresh list', 'pagespeedplus' ); ?></a>
								</div>
								<div class="psp-field-row psp-field-row--alt">
									<span class="psp-or"><?php esc_html_e( 'or', 'pagespeedplus' ); ?></span>
									<a class="psp-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_create_site' ), 'psp_create_site' ) ); ?>"><?php esc_html_e( 'Create a new site in PageSpeed Plus', 'pagespeedplus' ); ?></a>
								</div>
							<?php else : ?>
								<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'No sites found on your account yet.', 'pagespeedplus' ); ?></p>
								<div class="psp-field-row">
									<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_create_site' ), 'psp_create_site' ) ); ?>"><?php esc_html_e( 'Create a new site in PageSpeed Plus', 'pagespeedplus' ); ?></a>
									<a class="psp-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psp_refresh_sites' ), 'psp_refresh_sites' ) ); ?>">↻ <?php esc_html_e( 'Refresh list', 'pagespeedplus' ); ?></a>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Shown on every tab while no active license exists.
	 */
	private function render_locked() {
		?>
		<div class="psp-card">
			<div class="psp-card-body" style="text-align:center;padding:48px 32px;">
				<span class="dashicons dashicons-lock" style="font-size:40px;width:40px;height:40px;color:#62748a;"></span>
				<h2 style="margin:14px 0 8px;"><?php esc_html_e( 'Activate PageSpeedPlus to unlock optimizations', 'pagespeedplus' ); ?></h2>
				<p style="max-width:480px;margin:0 auto 20px;"><?php esc_html_e( 'Page caching, delayed JavaScript, WebP conversion, Critical CSS and all other optimizations start working as soon as your license is active.', 'pagespeedplus' ); ?></p>
				<a class="psp-btn psp-btn-accent" href="<?php echo esc_url( admin_url( 'admin.php?page=pagespeedplus&tab=license' ) ); ?>"><?php esc_html_e( 'Enter License Key', 'pagespeedplus' ); ?></a>
				<a class="psp-btn" href="https://pagespeedplus.com/pricing" target="_blank" rel="noopener"><?php esc_html_e( 'View Plans', 'pagespeedplus' ); ?></a>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------- Field helpers */

	/**
	 * Open a setting row: title + description on the left, control on the right.
	 *
	 * @param string $label Setting title.
	 * @param string $desc  Short explanation.
	 */
	private function row_open( $label, $desc = '' ) {
		?>
		<div class="psp-row">
			<div class="psp-row-info">
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php if ( $desc ) : ?><p><?php echo esc_html( $desc ); ?></p><?php endif; ?>
			</div>
			<div class="psp-row-control">
		<?php
	}

	private function row_close() {
		echo '</div></div>';
	}

	private function checkbox( $key, $label, $desc = '', $disabled = false ) {
		$this->row_open( $label, $desc );
		// When disabled, the checkbox submits nothing; the hidden 0 still posts,
		// so saving the page also forces the stored value off.
		?>
		<label class="psp-toggle <?php echo $disabled ? 'is-disabled' : ''; ?>">
			<input type="hidden" name="psp[<?php echo esc_attr( $key ); ?>]" value="0">
			<input type="checkbox" name="psp[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! $disabled && PSP_Options::get( $key ), 1 ); ?> <?php disabled( $disabled ); ?>>
			<span class="psp-slider"></span>
		</label>
		<?php
		$this->row_close();
	}

	private function text( $key, $label, $desc = '' ) {
		$this->row_open( $label, $desc );
		?>
		<input type="text" name="psp[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( PSP_Options::get( $key ) ); ?>">
		<?php
		$this->row_close();
	}

	private function number( $key, $label, $desc = '' ) {
		$this->row_open( $label, $desc );
		?>
		<input type="number" min="0" name="psp[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( PSP_Options::get( $key ) ); ?>">
		<?php
		$this->row_close();
	}

	private function textarea( $key, $label, $desc = '', $rows = 4, $placeholder = '' ) {
		$this->row_open( $label, $desc );
		?>
		<textarea rows="<?php echo (int) $rows; ?>" name="psp[<?php echo esc_attr( $key ); ?>]" spellcheck="false"<?php echo $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>><?php echo esc_textarea( PSP_Options::get( $key ) ); ?></textarea>
		<?php
		$this->row_close();
	}

	private function select( $key, $label, array $choices, $desc = '' ) {
		$this->row_open( $label, $desc );
		?>
		<select name="psp[<?php echo esc_attr( $key ); ?>]">
			<?php foreach ( $choices as $value => $text ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( PSP_Options::get( $key ), $value ); ?>><?php echo esc_html( $text ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		$this->row_close();
	}

	/* ------------------------------------------------------------- Actions */

	public function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_save_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}

		$raw      = isset( $_POST['psp'] ) && is_array( $_POST['psp'] ) ? wp_unslash( $_POST['psp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$defaults = PSP_Options::defaults();
		$clean    = array();

		foreach ( $raw as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			if ( is_int( $defaults[ $key ] ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( 'critical_css' === $key ) {
				$clean[ $key ] = wp_strip_all_tags( (string) $value );
			} else {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}

		// CDN rewriting needs a hostname — refuse to enable it without one,
		// otherwise asset URLs would be rewritten to nothing.
		if ( ! empty( $clean['cdn_enabled'] ) ) {
			$cdn_url = isset( $clean['cdn_url'] ) ? trim( $clean['cdn_url'] ) : trim( (string) PSP_Options::get( 'cdn_url' ) );
			if ( '' === $cdn_url ) {
				wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=cdn&psp_error=' . rawurlencode( __( 'Enter a CDN URL before enabling CDN rewriting.', 'pagespeedplus' ) ) ) );
				exit;
			}
		}

		PSP_Options::update( $clean );

		// Keep dependent state in sync.
		PSP_Page_Cache::write_config();
		PSP_Htaccess::update_rules();
		if ( ! empty( $clean['cache_enabled'] ) ) {
			PSP_Page_Cache::install_advanced_cache();
		}

		$tab = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'dashboard';
		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=' . $tab . '&psp_saved=1' ) );
		exit;
	}

	public function handle_preload() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_preload' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$remote = 'pagespeedplus' === PSP_Options::get( 'warmer_mode' );
		$result = PSP_Preloader::start();

		if ( $remote && is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'psp_warm_error', rawurlencode( $result->get_error_message() ), admin_url( 'admin.php?page=pagespeedplus&tab=cache' ) ) );
			exit;
		}
		$flag = $remote ? 'psp_warm_remote' : 'psp_preloading';
		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=cache&' . $flag . '=1' ) );
		exit;
	}

	public function handle_webp_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_webp_bulk' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$queued = PSP_WebP::start_bulk();
		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=media&psp_webp_queued=' . $queued ) );
		exit;
	}

	public function handle_toggle_master() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_toggle_master' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$on = isset( $_GET['state'] ) && 'on' === $_GET['state'];
		PSP_Plugin::set_master( $on );
		$referer = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'psp_master', $on ? 'on' : 'off', $referer ? $referer : admin_url( 'admin.php?page=pagespeedplus' ) ) );
		exit;
	}

	public function handle_activate_license() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_activate_license' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$key = isset( $_POST['psp_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['psp_license_key'] ) ) : '';
		if ( '' === $key ) {
			$key = (string) PSP_Options::get( 'psp_api_key' ); // Empty input = re-validate current key.
		}
		$result = PSP_License::activate( $key );
		$arg    = is_wp_error( $result ) ? array( 'psp_license_error' => rawurlencode( $result->get_error_message() ) ) : array( 'psp_license_ok' => 1 );
		wp_safe_redirect( add_query_arg( $arg, admin_url( 'admin.php?page=pagespeedplus&tab=license' ) ) );
		exit;
	}

	public function handle_deactivate_license() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_deactivate_license' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		PSP_License::deactivate();
		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=license' ) );
		exit;
	}

	public function handle_connect_site() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_connect_site' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$site_id = isset( $_POST['psp_site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['psp_site_id'] ) ) : '';

		// Only ever persist a real PageSpeedPlus site_id (or empty to clear).
		// Reject anything not in the account's site list so a bad/forged value
		// can't be stored — the warmer relies on this id being valid.
		if ( '' !== $site_id ) {
			$result = PSP_License::connect_site( $site_id );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( add_query_arg( 'psp_license_error', rawurlencode( $result->get_error_message() ), admin_url( 'admin.php?page=pagespeedplus' ) ) );
				exit;
			}
		} else {
			PSP_Options::update( array( 'psp_site_id' => '' ) );
		}
		wp_safe_redirect( add_query_arg( 'psp_site_connected', '1', admin_url( 'admin.php?page=pagespeedplus' ) ) );
		exit;
	}

	public function handle_refresh_sites() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_refresh_sites' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$result = PSP_License::refresh_sites();
		$arg    = is_wp_error( $result )
			? array( 'psp_license_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'psp_sites_refreshed' => (int) $result );
		wp_safe_redirect( add_query_arg( $arg, admin_url( 'admin.php?page=pagespeedplus' ) ) );
		exit;
	}

	public function handle_create_site() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_create_site' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$result = PSP_License::create_site();
		$arg    = is_wp_error( $result )
			? array( 'psp_license_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'psp_site_connected' => '1' );
		wp_safe_redirect( add_query_arg( $arg, admin_url( 'admin.php?page=pagespeedplus' ) ) );
		exit;
	}

	public function handle_generate_ccss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_generate_ccss' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		if ( ! PSP_Critical_CSS::backend_available() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=css&psp_error=' . rawurlencode( __( 'Automatic Critical CSS generation isn\'t available yet. Paste critical CSS manually for now.', 'pagespeedplus' ) ) ) );
			exit;
		}
		$queued = PSP_Critical_CSS::start();
		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&tab=css&psp_ccss_queued=' . $queued ) );
		exit;
	}

	/**
	 * Download all settings as a JSON file.
	 */
	public function handle_export_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_export_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$payload = array(
			'plugin'   => 'pagespeedplus',
			'version'  => PSP_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => get_option( PSP_Options::OPTION_KEY, array() ),
		);
		$filename = 'pagespeedplus-settings-' . gmdate( 'Ymd' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Import settings from an uploaded JSON file. Only known keys are applied.
	 */
	public function handle_import_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_import_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		$err = admin_url( 'admin.php?page=pagespeedplus&psp_error=' . rawurlencode( __( 'Import failed: please upload a valid PageSpeedPlus settings JSON file.', 'pagespeedplus' ) ) );

		if ( empty( $_FILES['psp_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['psp_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( $err );
			exit;
		}
		$raw    = file_get_contents( $_FILES['psp_import_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput
		$parsed = json_decode( (string) $raw, true );
		if ( ! is_array( $parsed ) || empty( $parsed['settings'] ) || ! is_array( $parsed['settings'] ) ) {
			wp_safe_redirect( $err );
			exit;
		}

		// Apply only keys we recognize, coerced to the type of their default.
		$defaults = PSP_Options::defaults();
		$clean    = array();
		foreach ( $parsed['settings'] as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$clean[ $key ] = is_int( $defaults[ $key ] ) ? (int) $value : ( is_array( $value ) ? $value : (string) $value );
		}
		PSP_Options::update( $clean );
		PSP_Page_Cache::write_config();
		PSP_Htaccess::update_rules();

		wp_safe_redirect( admin_url( 'admin.php?page=pagespeedplus&psp_imported=' . count( $clean ) ) );
		exit;
	}
}
