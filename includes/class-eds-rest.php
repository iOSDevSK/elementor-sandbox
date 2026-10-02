<?php
/**
 * The sandbox's own REST: the workspace status (time left, preview link) and an
 * activity ping that keeps the workspace alive while the editor is open.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_REST {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$demo = function () {
			return EDS_Plugin::is_demo_user();
		};
		register_rest_route( 'eds/v1', '/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $demo ) );
		register_rest_route( 'eds/v1', '/activity', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'activity' ), 'permission_callback' => $demo ) );
	}

	private static function payload( $ws ) {
		return array(
			'expiresAt' => strtotime( $ws['expires_at'] . ' UTC' ),
			'preview'   => EDS_Context::preview_url( $ws ),
			'start'     => admin_url( 'admin.php?page=' . EDS_Admin::PAGE ),
		);
	}

	public static function status() {
		$ws = EDS_Context::ws();
		return $ws && EDS_Context::is_session() ? rest_ensure_response( self::payload( $ws ) ) : new WP_Error( 'eds_expired', __( 'The demo workspace has expired.', 'elementor-sandbox' ), array( 'status' => 410 ) );
	}

	public static function activity() {
		$ws = EDS_Context::ws();
		if ( ! $ws || ! EDS_Context::is_session() ) {
			return new WP_Error( 'eds_expired', __( 'The demo workspace has expired.', 'elementor-sandbox' ), array( 'status' => 410 ) );
		}
		EDS_Store::touch( (int) $ws['id'] );
		$ws['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + EDS_TTL );
		return rest_ensure_response( self::payload( $ws ) );
	}
}
