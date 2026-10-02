<?php
/**
 * Which workspace this request runs in, decided once at plugins_loaded (before
 * Elementor reads its kit on init and the theme's design library reads its page
 * map on after_setup_theme):
 *
 *   public   /preview<public-id>/… — anyone, rendered anonymously, read only
 *   session  the demo account with a live workspace for its login session
 *            (front end, wp-admin, the Elementor editor, its AJAX and REST)
 *   ''       the real site
 *
 * Every isolation filter in the plugin is registered only when a mode is set,
 * so the real site runs exactly as without the plugin.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Context {
	/** @var array|null */
	private static $ws = null;
	/** @var string public|session|invalid|expired|revoked|'' */
	private static $mode = '';
	/** @var string the path below /preview<id>/ */
	private static $target = '';
	/** @var callable[] run when a workspace context starts */
	private static $listeners = array();

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'detect' ), 1 );
	}

	/** Modules call this to install their filters for workspace requests only. */
	public static function on_start( callable $cb ) {
		self::$listeners[] = $cb;
	}

	public static function detect() {
		if ( self::detect_preview() ) {
			if ( 'public' === self::$mode ) {
				self::start();
			}
			return;
		}
		if ( ! EDS_Plugin::is_demo_user() ) {
			return;
		}
		$ws = EDS_Store::existing();
		if ( $ws && EDS_Store::owns_current_session( $ws ) ) {
			self::$ws   = $ws;
			self::$mode = 'session';
			self::start();
		}
	}

	private static function start() {
		foreach ( self::$listeners as $cb ) {
			call_user_func( $cb, self::$ws, self::$mode );
		}
	}

	/** /preview<id>/<path> → the workspace, and WordPress resolves <path> as usual. */
	private static function detect_preview() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( $home && 0 !== strpos( $path, $home . '/' ) ) {
			return false;
		}
		if ( ! preg_match( '#^/preview([A-Za-z0-9_-]{43})(?:/(.*))?$#', substr( $path, strlen( $home ) ), $m ) ) {
			return false;
		}
		$ws = EDS_Store::by_public_id( $m[1] );
		if ( ! $ws ) {
			self::$mode = 'invalid';
		} elseif ( 'active' !== $ws['status'] ) {
			self::$ws   = $ws;
			self::$mode = 'revoked' === $ws['status'] ? 'revoked' : 'expired';
		} elseif ( strtotime( $ws['expires_at'] . ' UTC' ) <= time() ) {
			self::$ws   = $ws;
			self::$mode = 'expired';
		} else {
			self::$ws   = $ws;
			self::$mode = 'public';
		}
		self::$target = isset( $m[2] ) ? ltrim( $m[2], '/' ) : '';
		$query        = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		// WordPress resolves the page below the prefix; links get the prefix back
		$_SERVER['REQUEST_URI'] = trailingslashit( $home . '/' . self::$target ) . ( '' !== $query ? '?' . $query : '' );
		$_SERVER['PATH_INFO']   = '/' . self::$target;
		// a public preview never runs with the visitor's login (another tab)
		wp_set_current_user( 0 );
		return true;
	}

	/** The workspace of this request (null on the real site). */
	public static function ws() {
		return in_array( self::$mode, array( 'public', 'session' ), true ) ? self::$ws : null;
	}

	public static function id() {
		$ws = self::ws();
		return $ws ? (int) $ws['id'] : 0;
	}

	public static function mode() {
		return self::$mode;
	}

	public static function is_public() {
		return 'public' === self::$mode && self::$ws;
	}

	public static function is_session() {
		return 'session' === self::$mode && self::$ws;
	}

	public static function target() {
		return self::$target;
	}

	/** /preview<id>/ of a workspace. */
	public static function preview_url( $ws, $path = '' ) {
		return home_url( '/preview' . $ws['public_id'] . '/' . ltrim( (string) $path, '/' ) );
	}
}
