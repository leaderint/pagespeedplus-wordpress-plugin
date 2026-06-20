<?php
/**
 * Smart cache invalidation.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Purge {

	public function __construct() {
		// Content changes: purge the post and its related archives.
		add_action( 'save_post', array( $this, 'purge_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'purge_post_by_id' ) );
		add_action( 'trashed_post', array( $this, 'purge_post_by_id' ) );
		add_action( 'comment_post', array( $this, 'purge_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'purge_comment_transition' ), 10, 3 );

		// Site-wide changes: purge everything.
		add_action( 'switch_theme', array( __CLASS__, 'purge_all' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'purge_all' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'purge_all' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'purge_all' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_all' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'purge_all' ) );
		add_action( 'update_option_sidebars_widgets', array( __CLASS__, 'purge_all' ) );
		add_action( 'update_option_' . PSP_Options::OPTION_KEY, array( __CLASS__, 'purge_all' ) );

		// Manual purge requests from the toolbar / settings screen.
		add_action( 'admin_post_psp_purge_all', array( $this, 'handle_manual_purge' ) );
	}

	/**
	 * Purge everything: pages and optimized assets.
	 */
	public static function purge_all() {
		PSP_Page_Cache::clear_all();
		PSP_Assets::clear_asset_cache();
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function purge_post( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$urls = array(
			get_permalink( $post_id ),
			home_url( '/' ),
		);

		// Archives that list this post.
		$post_type_archive = get_post_type_archive_link( $post->post_type );
		if ( $post_type_archive ) {
			$urls[] = $post_type_archive;
		}
		foreach ( (array) get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}
		if ( 'post' === $post->post_type ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
			$page_for_posts = (int) get_option( 'page_for_posts' );
			if ( $page_for_posts ) {
				$urls[] = get_permalink( $page_for_posts );
			}
		}

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			PSP_Page_Cache::clear_url( $url );
		}

		do_action( 'psp_purged_post', $post_id, $urls );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function purge_post_by_id( $post_id ) {
		$url = get_permalink( $post_id );
		if ( $url ) {
			PSP_Page_Cache::clear_url( $url );
		}
		PSP_Page_Cache::clear_url( home_url( '/' ) );
	}

	/**
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   Approval status.
	 */
	public function purge_comment_post( $comment_id, $approved ) {
		if ( 1 === (int) $approved ) {
			$comment = get_comment( $comment_id );
			if ( $comment ) {
				$this->purge_post_by_id( (int) $comment->comment_post_ID );
			}
		}
	}

	/**
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function purge_comment_transition( $new_status, $old_status, $comment ) {
		if ( $new_status !== $old_status && ( 'approved' === $new_status || 'approved' === $old_status ) ) {
			$this->purge_post_by_id( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Toolbar "Purge All" handler.
	 */
	public function handle_manual_purge() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'psp_purge_all' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pagespeedplus' ) );
		}
		self::purge_all();
		wp_safe_redirect( add_query_arg( 'psp_purged', '1', wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}
}
