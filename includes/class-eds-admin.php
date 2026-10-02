<?php
/**
 * The demo account's start page (where the login lands): it makes the
 * workspace, lists the workspace's pages and templates with "Edit in Elementor"
 * and "View", the public preview link, the time left and "Start over".
 * Administrators get a short status page.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Admin {
	const PAGE = 'elementor-sandbox';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_menu', array( __CLASS__, 'strip_menu' ), 9999 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'quiet_notices' ), 999 );
		add_action( 'admin_post_eds_reset', array( __CLASS__, 'reset' ) );
		add_action( 'load-toplevel_page_' . self::PAGE, array( __CLASS__, 'prepare' ) );
	}

	public static function menu() {
		if ( current_user_can( 'manage_options' ) ) {
			add_menu_page( __( 'Elementor Demo', 'elementor-sandbox' ), __( 'Elementor Demo', 'elementor-sandbox' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_admin' ), 'dashicons-welcome-view-site', 59 );
			return;
		}
		add_menu_page( __( 'Elementor Demo', 'elementor-sandbox' ), __( 'Elementor Demo', 'elementor-sandbox' ), EDS_CAP, self::PAGE, array( __CLASS__, 'render_demo' ), 'dashicons-welcome-view-site', 2 );
	}

	public static function strip_menu() {
		if ( ! EDS_Plugin::is_demo_user() ) {
			return;
		}
		global $menu, $submenu;
		foreach ( (array) $menu as $i => $item ) {
			if ( ( $item[2] ?? '' ) !== self::PAGE ) {
				unset( $menu[ $i ] );
			}
		}
		foreach ( array_keys( (array) $submenu ) as $parent ) {
			$submenu[ $parent ] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/** Only the sandbox's own notices reach the demo account (no licence, update or plugin nags). */
	public static function quiet_notices() {
		if ( EDS_Plugin::is_demo_user() ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	public static function login_redirect( $to, $requested, $user ) {
		return $user instanceof WP_User && EDS_Plugin::is_demo_user( $user ) ? admin_url( 'admin.php?page=' . self::PAGE ) : $to;
	}

	public static function admin_bar( $show ) {
		return EDS_Plugin::is_demo_user() ? false : $show;
	}

	/** The workspace exists before the page renders; a new one is used from the next request on. */
	public static function prepare() {
		if ( ! EDS_Plugin::is_demo_user() || EDS_Context::is_session() ) {
			return;
		}
		$ws = EDS_Store::current( true, true );
		if ( is_wp_error( $ws ) ) {
			wp_die( esc_html( $ws->get_error_message() ), '', array( 'response' => (int) ( $ws->get_error_data()['status'] ?? 503 ) ) );
		}
		// this request started without the workspace's filters: start again inside it
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function reset() {
		check_admin_referer( 'eds_reset' );
		if ( EDS_Plugin::is_demo_user() && EDS_Context::is_session() ) {
			EDS_Store::revoke( EDS_Context::id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render_demo() {
		$ws = EDS_Context::ws();
		if ( ! $ws ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Preparing your demo…', 'elementor-sandbox' ) . '</p></div>';
			return;
		}
		$rows    = EDS_Elementor::rows( (int) $ws['id'] );
		$left    = max( 0, strtotime( $ws['expires_at'] . ' UTC' ) - time() );
		$preview = EDS_Context::preview_url( $ws );
		echo '<div class="wrap eds-wrap"><h1>' . esc_html__( 'Your Elementor demo', 'elementor-sandbox' ) . '</h1>';
		echo '<p>' . esc_html__( 'Everything you change here is your own copy of the site. It is kept for one hour after your last change, nobody else sees it, and the real site never changes.', 'elementor-sandbox' ) . '</p>';
		/* translators: %d: minutes */
		echo '<p><strong data-eds-expires="' . esc_attr( (string) strtotime( $ws['expires_at'] . ' UTC' ) ) . '">' . esc_html( sprintf( __( 'Time left: %d min', 'elementor-sandbox' ), (int) ceil( $left / 60 ) ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Share your result:', 'elementor-sandbox' ) . ' <input type="text" readonly class="regular-text code" value="' . esc_attr( $preview ) . '" onclick="this.select()"> <a class="button" target="_blank" rel="noopener" href="' . esc_url( $preview ) . '">' . esc_html__( 'Open preview', 'elementor-sandbox' ) . '</a></p>';
		echo '<h2>' . esc_html__( 'Pages', 'elementor-sandbox' ) . '</h2><table class="widefat striped" style="max-width:820px"><tbody>';
		$front = (int) get_option( 'page_on_front' );
		usort(
			$rows,
			function ( $a, $b ) use ( $front ) {
				return ( (int) $b['original_id'] === $front ) <=> ( (int) $a['original_id'] === $front ) ?: strcmp( get_the_title( (int) $a['clone_id'] ), get_the_title( (int) $b['clone_id'] ) );
			}
		);
		foreach ( array( 'page' => __( 'Page', 'elementor-sandbox' ), 'template' => __( 'Template', 'elementor-sandbox' ) ) as $kind => $label ) {
			foreach ( $rows as $r ) {
				if ( $r['kind'] !== $kind ) {
					continue;
				}
				$clone = (int) $r['clone_id'];
				$edit  = admin_url( 'post.php?post=' . $clone . '&action=elementor' );
				$view  = 'page' === $kind ? get_permalink( (int) $r['original_id'] ) : '';
				echo '<tr><td><strong>' . esc_html( get_the_title( $clone ) ) . '</strong> <span class="description">' . esc_html( (int) $r['original_id'] === $front ? __( 'Front page', 'elementor-sandbox' ) : $label ) . '</span></td><td style="text-align:right"><a class="button button-primary" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit in Elementor', 'elementor-sandbox' ) . '</a> ';
				if ( $view ) {
					echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'elementor-sandbox' ) . '</a>';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:24px"><a class="button" href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View your site', 'elementor-sandbox' ) . '</a> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Start over from the original site? Your changes will be gone.', 'elementor-sandbox' ) ) . '\')">';
		wp_nonce_field( 'eds_reset' );
		echo '<input type="hidden" name="action" value="eds_reset"><button class="button button-link-delete">' . esc_html__( 'Start over', 'elementor-sandbox' ) . '</button></form></p></div>';
	}

	public static function render_admin() {
		global $wpdb;
		$table  = $wpdb->prefix . 'eds_workspaces';
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND expires_at > UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB
		echo '<div class="wrap"><h1>' . esc_html__( 'Elementor Demo Sandbox', 'elementor-sandbox' ) . '</h1>';
		/* translators: %d: number */
		echo '<p>' . esc_html( sprintf( __( 'Active demo workspaces: %d (at most 20).', 'elementor-sandbox' ), $active ) ) . '</p>';
		echo '<p>' . esc_html__( 'Visitors sign in with demo / demo. Each login gets its own copy of the site for one hour after its last change; the real site is never changed.', 'elementor-sandbox' ) . '</p></div>';
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = get_option( EDS_Plugin::SETUP_ERROR_OPTION, '' );
		if ( $error ) {
			echo '<div class="notice notice-error"><p><strong>Elementor Demo Sandbox:</strong> ' . esc_html( $error ) . '</p></div>';
		}
		$ready = EDS_Plugin::dependencies_ready();
		if ( is_wp_error( $ready ) ) {
			echo '<div class="notice notice-warning"><p><strong>Elementor Demo Sandbox:</strong> ' . esc_html( $ready->get_error_message() ) . '</p></div>';
		}
	}
}
