<?php
/**
 * Elementor in a workspace: its own copies of the site's pages, the header /
 * footer / 404 / layout templates and the active kit (Site Settings, global
 * classes, variables), edited in the real Elementor editor and rendered in the
 * public preview. The real documents, kit, classes and CSS files are never
 * written by a workspace request:
 *
 *  - pages      → private post type `eds_page` (publicly queryable for Elementor's
 *                 preview, out of search, sitemaps and admin lists);
 *  - templates  → `elementor_library` copies with the same template type;
 *  - kit        → a published kit copy; Elementor's own "new kit" action clones the
 *                 global class posts for it; `elementor_active_kit` points to it
 *                 only inside the workspace;
 *  - CSS files  → `uploads/elementor-sandbox/<workspace>/` (Elementor's files base
 *                 dir filter), so a kit save that clears Elementor's CSS cache or a
 *                 file built with sandbox styles never touches the site's files;
 *  - post meta  → caches Elementor writes while rendering (`_elementor_css`, element
 *                 cache, page assets) are refused for documents that are not the
 *                 workspace's own, and "delete for all posts" is narrowed to them.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Elementor {
	const PT = 'eds_page';
	const OWNED = '_eds_owned';
	const WS = '_eds_workspace';
	const ORIGINAL = '_eds_original';

	/** metas that are caches / indexes of the original: never copied */
	const SKIP_META = array(
		'_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_controls_usage',
		'_elementor_used_global_class', '_elementor_used_global_class_preview',
		'_elementor_global_class_usage_indexed', '_elementor_global_class_usage_indexed_preview',
		'elementor-interactions-cache', '_edit_lock', '_edit_last', '_wp_old_slug', '_eds_owned', '_eds_workspace', '_eds_original',
	);
	/** Elementor caches written while rendering: refused on documents not owned by the workspace */
	const CACHE_META = array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', 'elementor-interactions-cache' );

	/** @var array original id => clone id of this request's workspace */
	private static $map = array();
	/** @var int */
	private static $kit = 0;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eds_objects';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				workspace_id bigint(20) unsigned NOT NULL,
				kind varchar(20) NOT NULL,
				original_id bigint(20) unsigned NOT NULL,
				clone_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (workspace_id,kind,original_id),
				KEY clone_id (clone_id)
			) {$wpdb->get_charset_collate()};"
		);
	}

	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/** Primitive caps of the demo role on its own copies (object checks in EDS_Guard). */
	public static function role_caps() {
		return array( 'edit_eds_pages', 'edit_published_eds_pages', 'publish_eds_pages', 'read_private_eds_pages', 'delete_eds_pages', 'delete_published_eds_pages' );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_type' ), 1 );
		// demo copies are never read by search engines or SEO plugins
		add_filter( 'wpseo_indexable_excluded_post_types', array( __CLASS__, 'exclude_type' ) );
		add_filter( 'wpseo_sitemap_exclude_post_type', array( __CLASS__, 'exclude_type_bool' ), 10, 2 );
		EDS_Context::on_start( array( __CLASS__, 'start' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_copies' ) );
	}

	/**
	 * Workspace copies of templates and of the kit are Elementor library posts:
	 * listings (Templates screen, the editor's "My templates", exports) show the
	 * site's own and, in a workspace, its own copies only.
	 */
	public static function hide_copies( $q ) {
		$types = (array) $q->get( 'post_type' );
		if ( ! array_intersect( $types, array( 'elementor_library', 'any' ) ) || $q->get( 'eds_all' ) || $q->get( 'p' ) || $q->get( 'post__in' ) ) {
			return;
		}
		$meta   = (array) $q->get( 'meta_query' );
		$ws     = EDS_Context::id();
		$meta[] = $ws
			? array(
				'relation' => 'OR',
				array( 'key' => self::OWNED, 'compare' => 'NOT EXISTS' ),
				array( 'key' => self::WS, 'value' => (string) $ws ),
			)
			: array( 'key' => self::OWNED, 'compare' => 'NOT EXISTS' );
		$q->set( 'meta_query', $meta );
	}

	public static function register_type() {
		register_post_type(
			self::PT,
			array(
				'label'               => __( 'Demo pages', 'elementor-sandbox' ),
				'public'              => false,
				'publicly_queryable'  => true, // Elementor's editor preview loads it by ?p=
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'capability_type'     => array( 'eds_page', 'eds_pages' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'elementor', 'revisions', 'page-attributes', 'custom-fields' ),
			)
		);
		add_post_type_support( self::PT, 'elementor' );
	}

	// ------------------------------------------------------------ mapping

	public static function rows( $ws_id, $kind = null ) {
		global $wpdb;
		$table = self::table();
		$sql   = $kind
			? $wpdb->prepare( "SELECT * FROM {$table} WHERE workspace_id = %d AND kind = %s", $ws_id, $kind ) // phpcs:ignore WordPress.DB
			: $wpdb->prepare( "SELECT * FROM {$table} WHERE workspace_id = %d", $ws_id ); // phpcs:ignore WordPress.DB
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** original id => clone id for a workspace (all kinds). */
	public static function map( $ws_id ) {
		$out = array();
		foreach ( self::rows( $ws_id ) as $r ) {
			$out[ (int) $r['original_id'] ] = (int) $r['clone_id'];
		}
		return $out;
	}

	public static function clone_of( $original_id ) {
		return self::$map[ (int) $original_id ] ?? 0;
	}

	public static function original_of( $clone_id ) {
		$k = array_search( (int) $clone_id, self::$map, true );
		return false === $k ? 0 : (int) $k;
	}

	/** Is this post one of the current workspace's own copies (or their revisions / autosaves)? */
	public static function owned_by_current( $post_id ) {
		$ws = EDS_Context::id();
		if ( ! $ws || ! $post_id ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( in_array( $post->post_type, array( 'revision' ), true ) || ( 'inherit' === $post->post_status && $post->post_parent ) ) {
			$post = get_post( $post->post_parent ) ?: $post;
		}
		return (int) get_post_meta( $post->ID, self::WS, true ) === $ws;
	}

	private static function add_row( $ws_id, $kind, $original, $clone ) {
		global $wpdb;
		$wpdb->replace( self::table(), array( 'workspace_id' => $ws_id, 'kind' => $kind, 'original_id' => $original, 'clone_id' => $clone ), array( '%d', '%s', '%d', '%d' ) ); // phpcs:ignore WordPress.DB
	}

	// ------------------------------------------------------------ snapshot

	/** The documents a workspace gets its own copy of: [ original id => kind ]. */
	public static function documents() {
		$docs = array();
		if ( class_exists( 'H2E_Site' ) ) {
			$job = H2E_Site::job();
			foreach ( array_keys( (array) ( $job['pages'] ?? array() ) ) as $id ) {
				if ( 'page' === get_post_type( $id ) ) {
					$docs[ (int) $id ] = 'page';
				}
			}
			foreach ( (array) ( $job['templates'] ?? array() ) as $t ) {
				if ( ! empty( $t['id'] ) ) {
					$docs[ (int) $t['id'] ] = 'template';
				}
			}
			if ( ! empty( $job['notFoundPage'] ) ) {
				$docs[ (int) $job['notFoundPage'] ] = 'page' === get_post_type( $job['notFoundPage'] ) ? 'page' : 'template';
			}
			// layouts chosen in Appearance → Site templates
			$st = (array) get_option( 'h2e_site_templates', array() );
			foreach ( array( 'article', 'archive', 'search', 'not_found' ) as $k ) {
				if ( ! empty( $st[ $k ] ) && is_numeric( $st[ $k ] ) && 'elementor_library' === get_post_type( (int) $st[ $k ] ) ) {
					$docs[ (int) $st[ $k ] ] = 'template';
				}
			}
			foreach ( (array) ( $st['contexts'] ?? array() ) as $ctx ) {
				foreach ( array( 'header', 'footer' ) as $w ) {
					if ( ! empty( $ctx[ $w ] ) && is_numeric( $ctx[ $w ] ) && 'elementor_library' === get_post_type( (int) $ctx[ $w ] ) ) {
						$docs[ (int) $ctx[ $w ] ] = 'template';
					}
				}
			}
		}
		$front = (int) get_option( 'page_on_front' );
		if ( $front && 'page' === get_post_type( $front ) ) {
			$docs[ $front ] = 'page';
		}
		// card templates named by a Posts listing in any of them
		foreach ( array_keys( $docs ) as $id ) {
			$data = (string) get_post_meta( $id, '_elementor_data', true );
			if ( preg_match_all( '/"card_template":"?(\d+)/', $data, $m ) ) {
				foreach ( $m[1] as $tid ) {
					if ( 'elementor_library' === get_post_type( (int) $tid ) ) {
						$docs[ (int) $tid ] = 'template';
					}
				}
			}
		}
		/** The documents a demo workspace copies (original id => 'page' | 'template'). */
		return (array) apply_filters( 'eds_documents', $docs );
	}

	public static function snapshot( $ws_id ) {
		$ready = EDS_Plugin::dependencies_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$kit = self::clone_kit( $ws_id );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		foreach ( self::documents() as $id => $kind ) {
			$clone = self::clone_post( $id, $ws_id, 'page' === $kind ? self::PT : null );
			if ( is_wp_error( $clone ) ) {
				return $clone;
			}
			self::add_row( $ws_id, $kind, $id, $clone );
		}
		// documents name each other (card templates): point them at the copies
		$map = self::map( $ws_id );
		foreach ( self::rows( $ws_id ) as $r ) {
			if ( 'kit' === $r['kind'] ) {
				continue;
			}
			$data = (string) get_post_meta( (int) $r['clone_id'], '_elementor_data', true );
			$new  = preg_replace_callback(
				'/("card_template":"?)(\d+)/',
				function ( $m ) use ( $map ) {
					return $m[1] . ( $map[ (int) $m[2] ] ?? $m[2] );
				},
				$data
			);
			if ( $new !== $data ) {
				update_post_meta( (int) $r['clone_id'], '_elementor_data', wp_slash( $new ) );
			}
		}
		return true;
	}

	private static function clone_post( $id, $ws_id, $post_type = null ) {
		$p = get_post( $id );
		if ( ! $p ) {
			return new WP_Error( 'eds_snapshot', __( 'A page of the site could not be copied.', 'elementor-sandbox' ), array( 'status' => 500 ) );
		}
		$new = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $post_type ?: $p->post_type,
					'post_status'    => 'publish',
					'post_title'     => $p->post_title,
					'post_name'      => 'eds-' . $ws_id . '-' . $p->post_name,
					'post_content'   => $p->post_content,
					'post_excerpt'   => $p->post_excerpt,
					'post_author'    => get_current_user_id(),
					'menu_order'     => $p->menu_order,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		self::copy_meta(
			$id,
			$new,
			function ( $key ) {
				return in_array( $key, self::SKIP_META, true ) || 0 === strpos( $key, '_yoast_' );
			}
		);
		update_post_meta( $new, self::OWNED, 1 );
		update_post_meta( $new, self::WS, $ws_id );
		update_post_meta( $new, self::ORIGINAL, $id );
		return (int) $new;
	}

	/**
	 * Meta and taxonomy terms of $from onto $to. Replaced, not added: inserting an
	 * Elementor library post already gives it a template type ("page"), and a kit or
	 * template whose first type value is "page" is a page to Elementor.
	 */
	private static function copy_meta( $from, $to, callable $skip ) {
		foreach ( get_post_meta( $from ) as $key => $values ) {
			if ( $skip( $key ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( $values as $v ) {
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		foreach ( get_object_taxonomies( get_post_type( $to ) ) as $tax ) {
			$terms = wp_get_object_terms( $from, $tax, array( 'fields' => 'ids' ) );
			wp_set_object_terms( $to, is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ), $tax );
		}
	}

	/** A kit of the workspace: settings, variables and its own copies of the global classes. */
	private static function clone_kit( $ws_id ) {
		$orig = (int) get_option( 'elementor_active_kit' );
		if ( ! $orig || 'elementor_library' !== get_post_type( $orig ) ) {
			return new WP_Error( 'eds_snapshot', __( 'The site has no Elementor kit.', 'elementor-sandbox' ), array( 'status' => 500 ) );
		}
		$new = wp_insert_post(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => 'publish', // a kit that is not published renders the site unstyled
				'post_title'  => 'Demo kit ' . $ws_id,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		self::copy_meta(
			$orig,
			$new,
			function ( $key ) {
				// Elementor's new-kit action makes the kit its own class / style posts
				return in_array( $key, self::SKIP_META, true ) || in_array( $key, array( '_elementor_global_classes_post_ids', '_elementor_default_styles_post_ids' ), true );
			}
		);
		update_post_meta( $new, self::OWNED, 1 );
		update_post_meta( $new, self::WS, $ws_id );
		update_post_meta( $new, self::ORIGINAL, $orig );
		if ( 'kit' !== get_post_meta( $new, '_elementor_template_type', true ) ) {
			wp_delete_post( $new, true );
			return new WP_Error( 'eds_snapshot', __( 'The demo could not copy the Elementor kit.', 'elementor-sandbox' ), array( 'status' => 500 ) );
		}
		do_action( 'elementor/kit/after_new_kit_created', array( 'new_kit_id' => (int) $new, 'previous_kit_id' => $orig ) );
		// the kit's class / default-style posts belong to the workspace too (swept with it)
		foreach ( array( '_elementor_global_classes_post_ids', '_elementor_default_styles_post_ids' ) as $key ) {
			$ids = get_post_meta( $new, $key, true );
			foreach ( is_array( $ids ) ? $ids : array() as $cid ) {
				if ( is_numeric( $cid ) && (int) $cid > 0 ) {
					update_post_meta( (int) $cid, self::OWNED, 1 );
					update_post_meta( (int) $cid, self::WS, $ws_id );
				}
			}
		}
		self::add_row( $ws_id, 'kit', $orig, (int) $new );
		return (int) $new;
	}

	// ------------------------------------------------------------ cleanup

	public static function delete_workspace_data( array $ids ) {
		foreach ( $ids as $ws_id ) {
			$prev = self::scope_open( (int) $ws_id );
			foreach ( self::rows( $ws_id ) as $r ) {
				self::delete_clone( (int) $r['clone_id'], 'kit' === $r['kind'] );
			}
			// anything else the workspace created (revisions are deleted with their post)
			foreach ( get_posts( array( 'post_type' => array_values( get_post_types() ), 'eds_all' => true, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::WS, 'meta_value' => (int) $ws_id ) ) as $pid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				self::delete_clone( (int) $pid, 'elementor_library' === get_post_type( $pid ) && 'kit' === get_post_meta( $pid, '_elementor_template_type', true ) );
			}
			self::scope_close( $prev );
			delete_option( self::opts_key( $ws_id ) );
			global $wpdb;
			$wpdb->delete( self::table(), array( 'workspace_id' => (int) $ws_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			self::delete_dir( self::css_dir( (int) $ws_id ) );
		}
	}

	public static function delete_all_owned() {
		global $wpdb;
		self::delete_workspace_data( array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT workspace_id FROM ' . self::table() ) ) ); // phpcs:ignore WordPress.DB
		foreach ( get_posts( array( 'post_type' => array_values( get_post_types() ), 'eds_all' => true, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::OWNED, 'meta_value' => 1 ) ) as $pid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			self::delete_clone( (int) $pid, 'kit' === get_post_meta( $pid, '_elementor_template_type', true ) );
		}
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eds\\_ws\\_%\\_opts'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB
		self::delete_dir( trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'elementor-sandbox' );
	}

	public static function adopt( $id, $post = null, $update = false ) {
		if ( $update || get_post_meta( $id, self::WS, true ) ) {
			return;
		}
		update_post_meta( $id, self::OWNED, 1 );
		update_post_meta( $id, self::WS, EDS_Context::id() );
	}

	private static function delete_clone( $id, $is_kit ) {
		if ( ! $id || ! get_post_meta( $id, self::OWNED, true ) ) {
			return; // never anything the plugin did not create
		}
		if ( $is_kit ) {
			// the kit's own global class posts
			$ids = get_post_meta( $id, '_elementor_global_classes_post_ids', true );
			$ids = is_string( $ids ) ? json_decode( $ids, true ) : $ids;
			foreach ( (array) $ids as $cid ) {
				if ( is_numeric( $cid ) && 'e_global_class' === get_post_type( (int) $cid ) ) {
					wp_delete_post( (int) $cid, true );
				}
			}
			$_GET['force_delete_kit'] = 1; // Elementor refuses to delete a kit otherwise
		}
		wp_delete_post( $id, true );
		// Elementor's per-document font lists (written as site options)
		delete_option( 'elementor_atomic_styles_fonts-local-' . $id . '-frontend' );
		delete_option( 'elementor_atomic_styles_fonts-local-' . $id . '-preview' );
		if ( $is_kit ) {
			unset( $_GET['force_delete_kit'] );
		}
	}

	public static function css_dir( $ws_id ) {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'elementor-sandbox/' . absint( $ws_id ) . '/';
	}

	public static function css_url( $ws_id ) {
		return trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . 'elementor-sandbox/' . absint( $ws_id ) . '/';
	}

	private static function delete_dir( $dir ) {
		$base = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'elementor-sandbox';
		if ( 0 !== strpos( wp_normalize_path( $dir ), wp_normalize_path( $base ) ) || ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	// ------------------------------------------------------------ workspace requests

	public static function start( $ws, $mode ) {
		self::$map = self::map( (int) $ws['id'] );
		foreach ( self::rows( (int) $ws['id'], 'kit' ) as $r ) {
			self::$kit = (int) $r['clone_id'];
		}
		if ( self::$kit ) {
			add_filter( 'pre_option_elementor_active_kit', array( __CLASS__, 'active_kit' ) );
		}
		if ( 'session' === $mode ) {
			// whatever the demo creates (class / style / component posts, uploads,
			// revisions) is the workspace's and goes with it
			add_action( 'wp_insert_post', array( __CLASS__, 'adopt' ), 1, 3 );
			add_action( 'add_attachment', array( __CLASS__, 'adopt' ), 1 );
		}
		// Elementor's CSS files and cache flags of this request are the workspace's own
		self::scope_open( (int) $ws['id'] );
		add_filter( 'pre_option_elementor_element_cache_ttl', array( __CLASS__, 'disable' ) );
		// the front page and the pages of the site render as their copies
		add_action( 'pre_get_posts', array( __CLASS__, 'swap_query' ), 1 );
		// never write Elementor's caches onto a document that is not the workspace's own
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_cache_meta' ), 1, 5 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_cache_meta_add' ), 1, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'narrow_delete_all' ), 1, 5 );
		// deleting a global class rewrites every post using it: only the workspace's
		remove_all_actions( 'elementor/global_classes/cleanup' );
		add_action( 'elementor/global_classes/cleanup', array( __CLASS__, 'cleanup_classes' ), 10, 1 );
		// Site Identity in Site Settings writes the site name, tagline, logo and icon
		add_filter( 'elementor/document/save/data', array( __CLASS__, 'strip_site_wide_settings' ), 1, 2 );
	}

	/** @var int the workspace whose files and cache flags Elementor reads and writes now */
	private static $scope = 0;

	/**
	 * From here on Elementor's files (CSS) and its site-wide cache flags are those of
	 * workspace $ws_id: for its own requests, and while it is being deleted (removing
	 * its kit and classes makes Elementor invalidate "the" CSS cache and delete "the"
	 * CSS files — the site's, if not scoped; page-cached HTML would then link to
	 * files that are gone). Returns the scope that was active before.
	 */
	public static function scope_open( $ws_id ) {
		$prev = self::$scope;
		if ( ! $prev ) {
			add_filter( 'pre_option_elementor_experiment-e_optimized_css_files', array( __CLASS__, 'active_state' ) );
			add_filter( 'elementor/files/base_dir', array( __CLASS__, 'files_dir' ) );
			add_filter( 'elementor/files/base_url', array( __CLASS__, 'files_url' ) );
			add_filter( 'pre_option', array( __CLASS__, 'ws_option_read' ), 1, 3 );
			add_filter( 'pre_update_option', array( __CLASS__, 'ws_option_write' ), 1, 3 );
			add_action( 'delete_option', array( __CLASS__, 'ws_option_before_delete' ), 1 );
			add_action( 'deleted_option', array( __CLASS__, 'ws_option_after_delete' ), 1 );
		}
		self::$scope   = (int) $ws_id;
		self::$ws_opts = (array) get_option( self::opts_key( self::$scope ), array() );
		return $prev;
	}

	public static function scope_close( $prev ) {
		if ( $prev ) {
			self::scope_open( $prev );
			return;
		}
		remove_filter( 'pre_option_elementor_experiment-e_optimized_css_files', array( __CLASS__, 'active_state' ) );
		remove_filter( 'elementor/files/base_dir', array( __CLASS__, 'files_dir' ) );
		remove_filter( 'elementor/files/base_url', array( __CLASS__, 'files_url' ) );
		remove_filter( 'pre_option', array( __CLASS__, 'ws_option_read' ), 1 );
		remove_filter( 'pre_update_option', array( __CLASS__, 'ws_option_write' ), 1 );
		remove_action( 'delete_option', array( __CLASS__, 'ws_option_before_delete' ), 1 );
		remove_action( 'deleted_option', array( __CLASS__, 'ws_option_after_delete' ), 1 );
		self::$scope   = 0;
		self::$ws_opts = array();
	}

	/** @var array name => site row a workspace request is about to delete */
	private static $deleting = array();

	/** WordPress cannot refuse a delete_option(): the site's row is put back right after. */
	public static function ws_option_before_delete( $name ) {
		if ( ! self::is_ws_option( $name ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			self::$deleting[ $name ] = $row;
		}
		unset( self::$ws_opts[ $name ] );
		update_option( self::opts_key( self::$scope ), self::$ws_opts, false );
	}

	public static function ws_option_after_delete( $name ) {
		if ( ! isset( self::$deleting[ $name ] ) ) {
			return;
		}
		global $wpdb;
		$row = self::$deleting[ $name ];
		unset( self::$deleting[ $name ] );
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ), array( '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Options Elementor writes while editing or rendering that describe ITS files and
	 * caches (validity of the atomic CSS, font lists per document, the design-system
	 * stylesheet's meta, the element cache's id) or count editor use. Shared, they let
	 * a workspace mark the real site's CSS as fresh while it is stale.
	 */
	const WS_OPTIONS = array( 'elementor_atomic_cache_validity__', 'elementor_atomic_styles_fonts-', '_elementor_design_system_sync_css_meta', '_elementor_element_cache_unique_id', 'elementor-custom-breakpoints-files', '_elementor_global_css', 'e_editor_counter', 'elementor_angie_guide_auto_shown', 'elementor_onboarding' );

	/** @var array option name => value of this request's workspace */
	private static $ws_opts = array();

	private static function opts_key( $ws_id ) {
		return 'eds_ws_' . absint( $ws_id ) . '_opts';
	}

	private static function is_ws_option( $name ) {
		foreach ( self::WS_OPTIONS as $prefix ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ws_option_read( $pre, $name, $default = false ) {
		if ( false !== $pre || ! self::is_ws_option( $name ) ) {
			return $pre;
		}
		if ( array_key_exists( $name, self::$ws_opts ) ) {
			return self::$ws_opts[ $name ];
		}
		// not yet in the workspace: its caches start invalid, never as the site's
		return 0 === strpos( $name, 'elementor_atomic_cache_validity__' ) ? array( 'state' => false ) : $pre;
	}

	public static function ws_option_write( $value, $name, $old ) {
		if ( ! self::is_ws_option( $name ) ) {
			return $value;
		}
		self::$ws_opts[ $name ] = $value;
		update_option( self::opts_key( self::$scope ), self::$ws_opts, false );
		return $old; // nothing to write to the site's option
	}

	public static function active_kit() {
		return self::$kit;
	}

	public static function active_state() {
		return 'active';
	}

	public static function disable() {
		return 'disable';
	}

	public static function files_dir( $dir ) {
		$d = trailingslashit( self::css_dir( self::$scope ) ) . 'elementor/';
		wp_mkdir_p( $d );
		return $d;
	}

	public static function files_url( $url ) {
		return trailingslashit( self::css_url( self::$scope ) ) . 'elementor/';
	}

	/** /about/ (page 12) → the workspace's copy of page 12; the front page → the copy of the front page. */
	public static function swap_query( $q ) {
		if ( ! $q->is_main_query() || is_admin() ) {
			return;
		}
		$id = 0;
		if ( $q->get( 'page_id' ) ) {
			$id = (int) $q->get( 'page_id' );
		} elseif ( $q->get( 'pagename' ) ) {
			$page = get_page_by_path( (string) $q->get( 'pagename' ) );
			$id   = $page ? (int) $page->ID : 0;
		} elseif ( $q->is_home() && 'page' === get_option( 'show_on_front' ) && ! $q->get( 'p' ) && ! $q->get( 'name' ) && ! $q->get( 's' ) ) {
			$id = (int) get_option( 'page_on_front' );
		}
		$clone = $id ? self::clone_of( $id ) : 0;
		if ( ! $clone || self::PT !== get_post_type( $clone ) ) {
			return;
		}
		$q->set( 'page_id', '' );
		$q->set( 'pagename', '' );
		$q->set( 'post_type', self::PT );
		$q->set( 'p', $clone );
		$q->is_page     = false;
		$q->is_home     = false;
		$q->is_single   = true;
		$q->is_singular = true;
	}

	private static function cache_key( $key ) {
		return in_array( $key, self::CACHE_META, true );
	}

	public static function guard_cache_meta( $check, $object_id, $meta_key, $meta_value, $prev ) {
		if ( self::cache_key( $meta_key ) && ! self::owned_by_current( $object_id ) ) {
			return false; // not written
		}
		return $check;
	}

	public static function guard_cache_meta_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		if ( self::cache_key( $meta_key ) && ! self::owned_by_current( $object_id ) ) {
			return false;
		}
		return $check;
	}

	/** Elementor's "clear cache" deletes its cache metas of every post: here only of the workspace's own. */
	public static function narrow_delete_all( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		if ( ! self::cache_key( $meta_key ) && 0 !== strpos( (string) $meta_key, '_elementor_global_class' ) ) {
			return $check;
		}
		if ( $delete_all ) {
			foreach ( self::$map as $clone ) {
				delete_post_meta( $clone, $meta_key );
			}
			return true; // handled
		}
		return self::owned_by_current( $object_id ) ? $check : true;
	}

	/** A deleted global class is removed from the workspace's own documents only. */
	public static function cleanup_classes( $class_ids ) {
		foreach ( self::$map as $clone ) {
			$doc = \Elementor\Plugin::$instance->documents->get( $clone );
			if ( ! $doc || ! self::owned_by_current( $clone ) ) {
				continue;
			}
			$data = $doc->get_json_meta( '_elementor_data' );
			if ( ! $data ) {
				continue;
			}
			$changed = false;
			$walk    = function ( &$els ) use ( &$walk, $class_ids, &$changed ) {
				foreach ( $els as &$e ) {
					if ( isset( $e['settings']['classes']['value'] ) && is_array( $e['settings']['classes']['value'] ) ) {
						$keep = array_values( array_diff( $e['settings']['classes']['value'], (array) $class_ids ) );
						if ( $keep !== $e['settings']['classes']['value'] ) {
							$e['settings']['classes']['value'] = $keep;
							$changed                           = true;
						}
					}
					if ( ! empty( $e['elements'] ) ) {
						$walk( $e['elements'] );
					}
				}
			};
			$walk( $data );
			if ( $changed ) {
				$doc->update_json_meta( '_elementor_data', $data );
			}
		}
	}

	public static function strip_site_wide_settings( $data, $document ) {
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			foreach ( array_keys( $data['settings'] ) as $k ) {
				if ( in_array( $k, array( 'site_name', 'site_description', 'site_logo', 'site_favicon' ), true ) || 0 === strpos( $k, 'viewport_' ) || 'active_breakpoints' === $k ) {
					unset( $data['settings'][ $k ] );
				}
			}
		}
		return $data;
	}

	public static function exclude_type( $types ) {
		$types   = (array) $types;
		$types[] = self::PT;
		return $types;
	}

	public static function exclude_type_bool( $excluded, $type ) {
		return self::PT === $type ? true : $excluded;
	}
}
