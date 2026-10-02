<?php
/**
 * The converted theme's design library and Bridge for Elementor in a workspace:
 *
 *  - the design library's page map (`h2e_site_map`: converter id → site id) points
 *    at the workspace's copies, so routes, header / footer / 404 templates and
 *    their CSS follow the copies with no change in the theme;
 *  - the bridge's settings the demo may change (Design details, decorations,
 *    element attributes, Site templates) are the workspace's own: read from and
 *    written to the workspace, never to the site's options;
 *  - what would reach the real site is switched off: emptying the page cache on
 *    every save, rewriting WordPress menus from a document save, creating menus.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Bridge {
	/** bridge options a workspace keeps its own copy of */
	const OPTIONS = array( 'h2e_details', 'h2e_decorations', 'h2e_attrs_override', 'h2e_site_templates', 'h2e_menu_bindings', 'h2e_menu_ids', 'h2e_menu_lists', 'h2e_menu_seen' );

	/** @var array name => value of this request's workspace */
	private static $opts = array();
	/** @var int */
	private static $ws = 0;

	public static function init() {
		EDS_Context::on_start( array( __CLASS__, 'start' ) );
		// the demo account never runs the bridge's own site setup (menus, page cache),
		// in a workspace or not: the real site is set up by its administrators
		add_action( 'plugins_loaded', array( __CLASS__, 'unhook_for_demo' ), 99 );
	}

	public static function unhook_for_demo() {
		if ( EDS_Plugin::is_demo_user() ) {
			self::unhook();
		}
	}

	private static function key( $ws_id ) {
		return 'eds_ws_' . absint( $ws_id ) . '_bridge';
	}

	public static function snapshot( $ws_id ) {
		$map   = EDS_Elementor::map( $ws_id );
		$store = array();
		foreach ( self::OPTIONS as $name ) {
			$store[ $name ] = get_option( $name, null );
		}
		// Site templates name templates by id: the workspace's name its copies
		if ( is_array( $store['h2e_site_templates'] ) ) {
			$store['h2e_site_templates'] = self::remap_ids( $store['h2e_site_templates'], $map );
		}
		// the design library's map: converter id → this workspace's copy of the site's post
		$site = get_option( 'h2e_site_map', array() );
		if ( is_array( $site ) && ! empty( $site['posts'] ) ) {
			foreach ( $site['posts'] as $conv => $id ) {
				if ( isset( $map[ (int) $id ] ) ) {
					$site['posts'][ $conv ] = $map[ (int) $id ];
				}
			}
		}
		$store['h2e_site_map'] = $site;
		add_option( self::key( $ws_id ), $store, '', false );
		return true;
	}

	private static function remap_ids( $value, $map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::remap_ids( $v, $map );
			}
			return $value;
		}
		return is_numeric( $value ) && isset( $map[ (int) $value ] ) ? $map[ (int) $value ] : $value;
	}

	public static function delete_workspace_data( array $ids ) {
		foreach ( $ids as $id ) {
			delete_option( self::key( $id ) );
		}
	}

	public static function delete_all_owned() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eds\\_ws\\_%\\_bridge'" ); // phpcs:ignore WordPress.DB
	}

	public static function start( $ws, $mode ) {
		self::$ws   = (int) $ws['id'];
		self::$opts = (array) get_option( self::key( self::$ws ), array() );
		add_filter( 'pre_option_h2e_site_map', array( __CLASS__, 'site_map' ) );
		foreach ( self::OPTIONS as $name ) {
			add_filter( "pre_option_{$name}", array( __CLASS__, 'read' ), 1, 2 );
			add_filter( "pre_update_option_{$name}", array( __CLASS__, 'write' ), 1, 3 );
		}
		add_action( 'plugins_loaded', array( __CLASS__, 'unhook' ), 99 );
		self::unhook();
	}

	public static function site_map( $value ) {
		return self::$opts['h2e_site_map'] ?? $value;
	}

	public static function read( $pre, $name ) {
		if ( array_key_exists( $name, self::$opts ) ) {
			// an option the site does not have reads as empty, not false: add_option()
			// would otherwise write it to the site's options table
			return null === self::$opts[ $name ] ? array() : self::$opts[ $name ];
		}
		return $pre;
	}

	/** Saved into the workspace; the site's option stays as it was (update_option sees no change). */
	public static function write( $value, $old, $name ) {
		self::$opts[ $name ] = $value;
		update_option( self::key( self::$ws ), self::$opts, false );
		return $old;
	}

	/** What would write the real site from a workspace request. */
	public static function unhook() {
		if ( class_exists( 'H2E_Bridge' ) ) {
			// the page cache of the real site
			remove_action( 'elementor/document/after_save', array( 'H2E_Bridge', 'purge_page_cache' ) );
			remove_action( 'elementor/core/files/clear_cache', array( 'H2E_Bridge', 'purge_page_cache' ) );
			remove_action( 'h2e_css_saved', array( 'H2E_Bridge', 'purge_page_cache' ) );
		}
		if ( class_exists( 'H2E_Menus' ) ) {
			// menus ↔ documents: menu editing in the demo has its own module
			remove_action( 'wp_update_nav_menu', array( 'H2E_Menus', 'on_menu_saved' ) );
			remove_action( 'wp_update_nav_menu_item', array( 'H2E_Menus', 'on_menu_saved' ) );
			remove_action( 'before_delete_post', array( 'H2E_Menus', 'on_item_deleted' ) );
			remove_action( 'shutdown', array( 'H2E_Menus', 'flush' ) );
			remove_action( 'elementor/document/after_save', array( 'H2E_Menus', 'on_doc_saved' ), 20 );
			remove_action( 'admin_init', array( 'H2E_Menus', 'ensure' ), 60 );
		}
	}
}
