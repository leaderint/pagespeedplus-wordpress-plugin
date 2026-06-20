<?php
/**
 * WordPress bloat removal: emojis, embeds, dashicons, query strings, XML-RPC.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Tweaks {

	public function __construct() {
		if ( PSP_Options::get( 'disable_emojis' ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}
		if ( PSP_Options::get( 'disable_embeds' ) ) {
			add_action( 'init', array( $this, 'disable_embeds' ), 9999 );
		}
		if ( PSP_Options::get( 'disable_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_dashicons' ) );
		}
		if ( PSP_Options::get( 'remove_query_strings' ) ) {
			add_filter( 'style_loader_src', array( $this, 'strip_version' ), 15 );
			add_filter( 'script_loader_src', array( $this, 'strip_version' ), 15 );
		}
		if ( PSP_Options::get( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}
		if ( PSP_Options::get( 'disable_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}
		if ( PSP_Options::get( 'disable_block_css' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'remove_block_css' ), 100 );
			add_action( 'init', array( $this, 'remove_block_css_actions' ) );
		}
		if ( PSP_Options::get( 'disable_comment_reply' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_remove_comment_reply' ), 100 );
		}

		// <head> link/meta cleanup — simple remove_action()s, registered on init
		// so they run before wp_head fires.
		if ( PSP_Options::get( 'disable_rest_head_link' ) || PSP_Options::get( 'disable_rsd_wlw' ) || PSP_Options::get( 'disable_shortlink' ) || PSP_Options::get( 'disable_feed_links' ) || PSP_Options::get( 'disable_generator' ) ) {
			add_action( 'init', array( $this, 'head_cleanup' ) );
		}
	}

	/**
	 * Drop the jQuery Migrate dependency from jQuery on the front end.
	 *
	 * @param WP_Scripts $scripts Scripts registry.
	 */
	public function remove_jquery_migrate( $scripts ) {
		if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
			return;
		}
		$jquery = $scripts->registered['jquery'];
		if ( ! empty( $jquery->deps ) ) {
			$jquery->deps = array_diff( $jquery->deps, array( 'jquery-migrate' ) );
		}
	}

	/**
	 * Dequeue WordPress block-editor CSS on the front end (block library,
	 * theme.json global styles, classic-theme compat). May affect block/FSE
	 * themes — opt-in.
	 */
	public function remove_block_css() {
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Stop the SVG duotone filter block from printing in the body.
	 */
	public function remove_block_css_actions() {
		remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		remove_action( 'in_admin_header', 'wp_global_styles_render_svg_filters' );
	}

	/**
	 * Drop comment-reply.js on pages that don't need threaded comments.
	 */
	public function maybe_remove_comment_reply() {
		if ( ! is_singular() || ! comments_open() || ! get_option( 'thread_comments' ) ) {
			wp_dequeue_script( 'comment-reply' );
		}
	}

	/**
	 * Remove unused <head> discovery links and meta.
	 */
	public function head_cleanup() {
		if ( PSP_Options::get( 'disable_rest_head_link' ) ) {
			// Removes only the discovery <link>/header — the REST API still works.
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( PSP_Options::get( 'disable_rsd_wlw' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( PSP_Options::get( 'disable_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( PSP_Options::get( 'disable_feed_links' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( PSP_Options::get( 'disable_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', function ( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
		} );
		add_filter( 'wp_resource_hints', function ( $urls, $relation_type ) {
			if ( 'dns-prefetch' === $relation_type ) {
				$urls = array_filter( $urls, function ( $url ) {
					return false === strpos( is_array( $url ) ? $url['href'] : $url, 'https://s.w.org/images/core/emoji/' );
				} );
			}
			return $urls;
		}, 10, 2 );
	}

	public function disable_embeds() {
		global $wp;
		$wp->public_query_vars = array_diff( $wp->public_query_vars, array( 'embed' ) );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'tiny_mce_plugins', function ( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, array( 'wpembed' ) ) : array();
		} );
		add_filter( 'rewrite_rules_array', function ( $rules ) {
			foreach ( $rules as $rule => $rewrite ) {
				if ( false !== strpos( $rewrite, 'embed=true' ) ) {
					unset( $rules[ $rule ] );
				}
			}
			return $rules;
		} );
	}

	public function disable_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function strip_version( $src ) {
		if ( $src && false !== strpos( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
}
