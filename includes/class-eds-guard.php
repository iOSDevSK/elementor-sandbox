<?php
/**
 * What the demo account may do. The role itself has almost nothing (read + its
 * own copies); while a request runs in the account's live workspace it receives,
 * in memory only, what Elementor's editor checks for, and every object check
 * (edit / delete / publish a post) is allowed only for the workspace's copies.
 * Everything that would change the real site or take the design away is refused:
 * exports and imports, the template library's writes, Elementor's settings and
 * tools, WordPress's own admin screens, any write through REST or AJAX that the
 * editor does not need.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Guard {
	/** elementor_ajax actions the editor needs inside a workspace */
	const AJAX_ACTIONS = array(
		'get_document_config', 'save_builder', 'discard_changes', 'get_revisions', 'get_revision_diff',
		'get_library_data', 'get_template_data', 'get_templates', 'get_tags', 'get_editor_prefs',
		'query_control_value_titles', 'get_query_data', 'get_global_classes', 'get_dynamic_value',
		'update_editor_user_prefs', 'get_widgets_config', 'render_widget', 'get_atomic_widget_preview',
	);
	/** REST routes (prefix) the editor may write to inside a workspace */
	const REST_WRITES = array( '/elementor/v1/global-classes', '/elementor/v1/variables', '/elementor/v1/globals', '/elementor/v1/kit-elements-defaults', '/elementor/v1/default-styles', '/elementor/v1/user-data/current-user', '/elementor/v1/favorites', '/h2e/v1/details', '/h2e/v1/decorations', '/eds/v1/' );
	/** REST routes (prefix) refused even for reading */
	const REST_BLOCKED = array( '/elementor/v1/import-export', '/elementor/v1/kits', '/elementor/v1/cloud-kits', '/elementor/v1/kit-taxonomies', '/elementor/v1/template-library', '/elementor/v1/library/connect', '/elementor/v1/system-info', '/elementor/v1/mcp-proxy', '/elementor/v1/angie', '/elementor/v1/settings', '/elementor/v1/design-system-sync', '/elementor/v1/operations', '/elementor/v1/cache', '/elementor-pro/', '/elementor-one/', '/elementor-ai/', '/elementor-mcp-composer/', '/mcp', '/angie', '/wp/v2/users', '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes', '/wp/v2/menu', '/wp/v2/navigation', '/wp/v2/global-styles', '/wp/v2/templates', '/wp/v2/template-parts', '/wp/v2/block-directory', '/wp-site-health/' );

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'caps' ), 20, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'object_caps' ), 20, 4 );
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin' ), 1 );
		add_action( 'wp_ajax_elementor_ajax', array( __CLASS__, 'filter_elementor_ajax' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'restrict_rest' ), 5, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'block_frontend_writes' ), -200 );
		add_filter( 'elementor/user/is_current_user_can_edit', array( __CLASS__, 'can_edit' ), 20, 1 );
		add_filter( 'get_user_option_elementor_enable_ai', array( __CLASS__, 'no_ai' ), 20, 3 );
	}

	private static function demo_session() {
		return EDS_Plugin::is_demo_user() && EDS_Context::is_session();
	}

	/** In memory, for the account's own workspace requests only. */
	public static function caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || empty( $user->allcaps[ EDS_CAP ] ) ) {
			return $allcaps;
		}
		foreach ( array( 'manage_options', 'edit_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_themes', 'switch_themes', 'export', 'import', 'unfiltered_html', 'unfiltered_upload', 'delete_users', 'create_users', 'promote_users', 'update_core', 'manage_network' ) as $never ) {
			unset( $allcaps[ $never ] );
		}
		if ( ! EDS_Context::is_session() || get_current_user_id() !== (int) $user->ID ) {
			return $allcaps;
		}
		// what Elementor's editor checks for; object checks (edit THIS post) are in object_caps()
		foreach ( array( 'edit_posts', 'edit_pages', 'edit_published_posts', 'edit_published_pages', 'publish_posts', 'publish_pages', 'edit_others_posts', 'edit_others_pages', 'upload_files', 'edit_theme_options', 'elementor_global_classes_update_class' ) as $grant ) {
			$allcaps[ $grant ] = true;
		}
		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( 0 === strpos( $cap, 'elementor_atomic_' ) ) {
				$allcaps[ $cap ] = true;
			}
		}
		// Elementor's variables REST route checks manage_options for a write: only that route
		if ( self::is_rest_route( '/elementor/v1/variables' ) ) {
			$allcaps['manage_options'] = true;
		}
		return $allcaps;
	}

	/** Editing, deleting or publishing a post: only the workspace's own copies. */
	public static function object_caps( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'edit_page', 'delete_page', 'read_private_post' ), true ) ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->allcaps[ EDS_CAP ] ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( EDS_Context::is_session() && $post_id && EDS_Elementor::owned_by_current( $post_id ) ) {
			return array( 'read' );
		}
		return array( 'do_not_allow' );
	}

	public static function can_edit( $can ) {
		if ( ! EDS_Plugin::is_demo_user() ) {
			return $can;
		}
		$id = get_the_ID();
		return self::demo_session() && $id && EDS_Elementor::owned_by_current( $id ) ? $can : false;
	}

	public static function no_ai( $value, $option, $user ) {
		return EDS_Plugin::is_demo_user( $user ) ? '0' : $value;
	}

	/** wp-admin for the demo account: its launcher page and the Elementor editor of its own copies. */
	public static function restrict_admin() {
		if ( ! EDS_Plugin::is_demo_user() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $action, array( 'elementor_ajax', 'heartbeat', 'query-attachments', 'eds_activity', 'wp-compression-test', 'get-attachment' ), true ) ) {
				return;
			}
			if ( 'upload-attachment' === $action && self::demo_session() ) {
				return; // images only, see EDS_Elementor::image_mimes()
			}
			wp_die( esc_html__( 'That action is disabled in the demo.', 'elementor-sandbox' ), '', array( 'response' => 403 ) );
		}
		$file = basename( (string) ( $_SERVER['PHP_SELF'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'admin.php' === $file && EDS_Admin::PAGE === $page ) {
			return;
		}
		if ( 'async-upload.php' === $file && self::demo_session() ) {
			return; // images for the workspace (types and size limited in EDS_Elementor)
		}
		if ( 'admin-post.php' === $file && isset( $_REQUEST['action'] ) && 'eds_reset' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return; // "Start over" (nonce checked by its handler)
		}
		if ( 'post.php' === $file && isset( $_GET['action'], $_GET['post'] ) && 'elementor' === $_GET['action'] && self::demo_session() && EDS_Elementor::owned_by_current( (int) $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( 'admin.php' === $file && EDS_Bridge_Admin_Pages::allowed( $page ) && self::demo_session() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . EDS_Admin::PAGE ) );
		exit;
	}

	/** Only the editor's own actions, in the account's own workspace. */
	public static function filter_elementor_ajax() {
		if ( ! EDS_Plugin::is_demo_user() ) {
			return;
		}
		if ( ! self::demo_session() ) {
			wp_send_json_error( array( 'message' => 'demo workspace expired' ), 410 );
		}
		$raw     = isset( $_POST['actions'] ) ? (string) wp_unslash( $_POST['actions'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$actions = json_decode( $raw, true );
		foreach ( (array) $actions as $a ) {
			$name = (string) ( $a['action'] ?? '' );
			if ( ! in_array( $name, self::AJAX_ACTIONS, true ) ) {
				/** a blocked editor action (logged under WP_DEBUG so the list can be refined) */
				do_action( 'eds_blocked_ajax', $name );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'elementor-sandbox: blocked elementor_ajax action ' . $name ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				}
				wp_send_json_error( array( 'message' => 'not available in the demo: ' . $name ), 403 );
			}
			if ( 'save_builder' === $name ) {
				$doc = (int) ( $a['data']['editor_post_id'] ?? ( $_POST['editor_post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( $doc && ! EDS_Elementor::owned_by_current( $doc ) ) {
					wp_send_json_error( array( 'message' => 'not a demo document' ), 403 );
				}
				$limit = EDS_Store::consume_save_limit( EDS_Context::id() );
				if ( is_wp_error( $limit ) ) {
					wp_send_json_error( array( 'message' => $limit->get_error_message() ), 429 );
				}
				EDS_Store::touch( EDS_Context::id() );
			}
		}
	}

	private static function is_rest_route( $prefix ) {
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$route = (string) wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		} else {
			$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$prefix0 = '/' . rest_get_url_prefix();
			$at     = strpos( $uri, $prefix0 );
			$route  = false === $at ? '' : strtok( substr( $uri, $at + strlen( $prefix0 ) ), '?' );
		}
		return 0 === strpos( $route, $prefix );
	}

	public static function restrict_rest( $result, $server, $request ) {
		if ( null !== $result || ! EDS_Plugin::is_demo_user() ) {
			return $result;
		}
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );
		// the account's own profile, read only (the block editor and Elementor read it)
		$own = '/wp/v2/users/me' === $route && 'GET' === $method;
		foreach ( self::REST_BLOCKED as $b ) {
			if ( ! $own && 0 === strpos( $route, $b ) ) {
				return new WP_Error( 'eds_rest_blocked', __( 'Not available in the demo.', 'elementor-sandbox' ), array( 'status' => 403 ) );
			}
		}
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}
		if ( ! self::demo_session() ) {
			return new WP_Error( 'eds_expired', __( 'The demo workspace has expired.', 'elementor-sandbox' ), array( 'status' => 410 ) );
		}
		foreach ( self::REST_WRITES as $w ) {
			if ( 0 === strpos( $route, $w ) ) {
				EDS_Store::touch( EDS_Context::id() );
				return $result;
			}
		}
		return new WP_Error( 'eds_rest_locked', __( 'The demo cannot change this.', 'elementor-sandbox' ), array( 'status' => 403 ) );
	}

	/** A form or any other POST on the front end of the real site by the demo account. */
	public static function block_frontend_writes() {
		if ( EDS_Plugin::is_demo_user() && ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_die( esc_html__( 'The demo cannot submit this.', 'elementor-sandbox' ), '', array( 'response' => 403 ) );
		}
	}
}

/** Bridge for Elementor screens the demo may open (its Design details live in the workspace). */
final class EDS_Bridge_Admin_Pages {
	public static function allowed( $page ) {
		return in_array( $page, array( 'h2e-design-details' ), true );
	}
}
