<?php
/**
 * Plugin Name: Elementor Demo Sandbox
 * Plugin URI:  https://github.com/iOSDevSK/elementor-sandbox
 * Description: Isolated, expiring Elementor demo workspaces: every demo login edits its own copy of the site (pages, header and footer, Site Settings, Design details, posts, menus) in the real Elementor editor, with a public preview link. The real site is never changed.
 * Version:     0.1.0
 * Author:      DesignReady
 * License:     GPL-2.0-or-later
 * Text Domain: elementor-sandbox
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: elementor
 */

defined( 'ABSPATH' ) || exit;

define( 'EDS_VERSION', '0.1.0' );
define( 'EDS_FILE', __FILE__ );
define( 'EDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDS_URL', plugin_dir_url( __FILE__ ) );
define( 'EDS_ROLE', 'elementor_sandbox_demo' );
define( 'EDS_CAP', 'elementor_sandbox_demo' );
define( 'EDS_TTL', HOUR_IN_SECONDS );
/** The Elementor release the isolation was verified against (a newer one is refused until verified). */
define( 'EDS_ELEMENTOR_TESTED', '4.3.3' );

require_once EDS_DIR . 'includes/class-eds-store.php';
require_once EDS_DIR . 'includes/class-eds-context.php';
require_once EDS_DIR . 'includes/class-eds-elementor.php';
require_once EDS_DIR . 'includes/class-eds-bridge.php';
require_once EDS_DIR . 'includes/class-eds-guard.php';
require_once EDS_DIR . 'includes/class-eds-preview.php';
require_once EDS_DIR . 'includes/class-eds-admin.php';
require_once EDS_DIR . 'includes/class-eds-rest.php';

final class EDS_Plugin {
	const USER_META_CREATED = '_eds_created_user';
	const USER_OPTION        = 'eds_demo_user_id';
	const SETUP_ERROR_OPTION = 'eds_setup_error';
	const ROLE_OPTION        = 'eds_role_created';
	const CLEANUP_HOOK       = 'eds_cleanup_workspaces';

	public static function init() {
		EDS_Store::register_module( 'EDS_Elementor' );
		EDS_Store::register_module( 'EDS_Bridge' );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CLEANUP_HOOK, array( 'EDS_Store', 'cleanup' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'no_app_passwords' ), 10, 2 );
		add_action( 'xmlrpc_call', array( __CLASS__, 'block_xmlrpc' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 0 );
		// the workspace of this request is decided before Elementor (init 0) and the
		// theme's design library (after_setup_theme) read anything
		EDS_Context::init();
		EDS_Elementor::init();
		EDS_Bridge::init();
		EDS_Guard::init();
		EDS_Preview::init();
		EDS_Admin::init();
		EDS_REST::init();
	}

	public static function maybe_upgrade() {
		if ( EDS_VERSION === (string) get_option( 'eds_db_version', '' ) ) {
			return;
		}
		EDS_Store::install();
		self::ensure_role_and_user();
		update_option( 'eds_db_version', EDS_VERSION, false );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'elementor-sandbox', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['eds_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Elementor Demo Sandbox)', 'elementor-sandbox' ),
		);
		return $schedules;
	}

	/** Elementor of the verified release and the converted theme's design library. */
	public static function dependencies_ready() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'eds_dependency', __( 'Elementor must be active.', 'elementor-sandbox' ), array( 'status' => 503 ) );
		}
		if ( version_compare( ELEMENTOR_VERSION, EDS_ELEMENTOR_TESTED, '<' ) || version_compare( (string) preg_replace( '/^(\d+\.\d+).*$/', '$1', ELEMENTOR_VERSION ), (string) preg_replace( '/^(\d+\.\d+).*$/', '$1', EDS_ELEMENTOR_TESTED ), '>' ) ) {
			/* translators: %s: Elementor version */
			return new WP_Error( 'eds_dependency', sprintf( __( 'The demo sandbox is verified with Elementor %s.x.', 'elementor-sandbox' ), preg_replace( '/^(\d+\.\d+).*$/', '$1', EDS_ELEMENTOR_TESTED ) ), array( 'status' => 503 ) );
		}
		return true;
	}

	public static function is_demo_user( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		return $user && $user->exists() && ! empty( $user->allcaps[ EDS_CAP ] );
	}

	public static function on_login( $login, $user ) {
		if ( $user instanceof WP_User && self::is_demo_user( $user ) ) {
			EDS_Store::cleanup(); // a login starts from the site as it is
		}
	}

	public static function no_app_passwords( $available, $user ) {
		return $user instanceof WP_User && self::is_demo_user( $user ) ? false : $available;
	}

	public static function block_xmlrpc() {
		if ( self::is_demo_user() ) {
			wp_die( esc_html__( 'XML-RPC is disabled for the demo account.', 'elementor-sandbox' ), '', array( 'response' => 403 ) );
		}
	}

	public static function activate() {
		EDS_Store::install();
		update_option( 'eds_db_version', EDS_VERSION, false );
		self::ensure_role_and_user();
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 60, 'eds_five_minutes', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() {
		EDS_Store::revoke_all();
		EDS_Store::delete_all_owned();
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		$user_id = (int) get_option( self::USER_OPTION, 0 );
		if ( $user_id && get_user_meta( $user_id, self::USER_META_CREATED, true ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				$user->remove_role( EDS_ROLE );
			}
		}
	}

	/** Capabilities of the demo role (its own clones only; see EDS_Guard). */
	public static function role_caps() {
		return array_merge(
			array( 'read' => true, EDS_CAP => true ),
			array_fill_keys( EDS_Elementor::role_caps(), true )
		);
	}

	private static function ensure_role_and_user() {
		delete_option( self::SETUP_ERROR_OPTION );
		$role = get_role( EDS_ROLE );
		if ( ! $role ) {
			$role = add_role( EDS_ROLE, __( 'Elementor Demo', 'elementor-sandbox' ), self::role_caps() );
			if ( $role ) {
				update_option( self::ROLE_OPTION, 1, false );
			}
		} elseif ( ! get_option( self::ROLE_OPTION ) ) {
			update_option( self::SETUP_ERROR_OPTION, __( 'The role “elementor_sandbox_demo” already exists and is not owned by this plugin. It was left unchanged.', 'elementor-sandbox' ), false );
			return;
		}
		if ( ! $role ) {
			update_option( self::SETUP_ERROR_OPTION, __( 'The demo role could not be created.', 'elementor-sandbox' ), false );
			return;
		}
		foreach ( self::role_caps() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}

		$stored_id = (int) get_option( self::USER_OPTION, 0 );
		$stored    = $stored_id ? get_user_by( 'id', $stored_id ) : false;
		if ( $stored && get_user_meta( $stored_id, self::USER_META_CREATED, true ) ) {
			$stored->set_role( EDS_ROLE );
			wp_set_password( 'demo', $stored_id );
			return;
		}
		$existing = get_user_by( 'login', 'demo' );
		if ( $existing ) {
			// the Visual Edit demo sandbox's own demo account may be taken over (same purpose);
			// any other "demo" user is never touched
			if ( get_user_meta( $existing->ID, '_ved_created_user', true ) ) {
				update_user_meta( $existing->ID, self::USER_META_CREATED, 1 );
				update_option( self::USER_OPTION, (int) $existing->ID, false );
				$existing->set_role( EDS_ROLE );
				wp_set_password( 'demo', $existing->ID );
				return;
			}
			update_option( self::SETUP_ERROR_OPTION, __( 'The username “demo” already exists and was not created by a demo sandbox. It was left unchanged.', 'elementor-sandbox' ), false );
			return;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'demo',
				'user_pass'    => 'demo',
				'user_email'   => 'demo@invalid.local',
				'display_name' => 'Demo',
				'role'         => EDS_ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			update_option( self::SETUP_ERROR_OPTION, $user_id->get_error_message(), false );
			return;
		}
		update_user_meta( $user_id, self::USER_META_CREATED, 1 );
		update_option( self::USER_OPTION, (int) $user_id, false );
	}
}

EDS_Plugin::init();
register_activation_hook( __FILE__, array( 'EDS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EDS_Plugin', 'deactivate' ) );
