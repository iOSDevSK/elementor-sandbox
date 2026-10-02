<?php
/**
 * Workspaces: one per login session of the demo account, opaque public id for
 * its preview, a sliding one-hour expiry, tombstones for a day (HTTP 410).
 *
 * Modules register themselves (EDS_Store::register_module) and get three calls:
 * snapshot( $workspace_id ) when a workspace starts, delete_workspace_data( $ids )
 * when it ends, delete_all_owned() on deactivation / uninstall.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Store {
	const MAX_ACTIVE = 20;

	/** @var string[] module class names, in snapshot order */
	private static $modules = array();

	public static function register_module( $class ) {
		if ( ! in_array( $class, self::$modules, true ) ) {
			self::$modules[] = $class;
		}
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eds_workspaces';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				session_hash char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				theme varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				last_activity datetime NOT NULL,
				expires_at datetime NOT NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				ready tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY session_hash (session_hash),
				KEY expires_at (expires_at),
				KEY status (status)
			) {$charset};"
		);
		foreach ( self::$modules as $m ) {
			if ( method_exists( $m, 'install' ) ) {
				$m::install();
			}
		}
	}

	public static function session_hash() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$token = wp_get_session_token();
		return $token ? hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . '|elementor-sandbox' ) : '';
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function expires() {
		return gmdate( 'Y-m-d H:i:s', time() + EDS_TTL );
	}

	private static function opaque_id() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * The visitor's address. Behind a proxy on the same host or network (Traefik,
	 * nginx, Docker) every request comes from the proxy: then the address the proxy
	 * or Cloudflare passes on is used. A public REMOTE_ADDR is never overridden by a
	 * header, so a client reaching the server directly cannot pick its own address.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$behind_proxy = $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( $behind_proxy ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
				$v = isset( $_SERVER[ $h ] ) ? trim( explode( ',', (string) $_SERVER[ $h ] )[0] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( filter_var( $v, FILTER_VALIDATE_IP ) ) {
					$ip = $v;
					break;
				}
			}
		}
		/** the address the start limit counts (e.g. for another proxy setup) */
		return (string) apply_filters( 'eds_client_ip', $ip );
	}

	private static function ip_hash() {
		$ip = self::client_ip();
		return '' === $ip ? '' : hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) . '|elementor-sandbox-ip' );
	}

	/**
	 * The current session's workspace (demo account only).
	 *
	 * @return array|WP_Error|null
	 */
	public static function current( $create = true, $touch = false ) {
		global $wpdb;
		if ( ! EDS_Plugin::is_demo_user() ) {
			return new WP_Error( 'eds_forbidden', __( 'Available only to the demo account.', 'elementor-sandbox' ), array( 'status' => 403 ) );
		}
		$hash = self::session_hash();
		if ( ! $hash ) {
			return new WP_Error( 'eds_no_session', __( 'Your login session is unavailable. Please sign in again.', 'elementor-sandbox' ), array( 'status' => 401 ) );
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_hash = %s LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row && ( 'active' !== $row['status'] || strtotime( $row['expires_at'] . ' UTC' ) <= time() || $row['theme'] !== get_stylesheet() ) ) {
			self::retire( (int) $row['id'] );
			$row = null;
		}
		if ( ! $row && $create ) {
			$row = self::create( $hash );
		}
		if ( is_wp_error( $row ) || ! $row ) {
			return $row;
		}
		if ( $touch ) {
			self::touch( (int) $row['id'] );
			$row['last_activity'] = self::now();
			$row['expires_at']    = self::expires();
		}
		return $row;
	}

	/** The session's workspace without creating one (null when none). */
	public static function existing() {
		$row = self::current( false, false );
		return is_array( $row ) ? $row : null;
	}

	private static function create( $hash ) {
		global $wpdb;
		$ready = EDS_Plugin::dependencies_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		self::cleanup();
		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND expires_at > UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB
		if ( $count >= self::MAX_ACTIVE ) {
			return new WP_Error( 'eds_capacity', __( 'All demo places are in use. Please try again in a few minutes.', 'elementor-sandbox' ), array( 'status' => 429 ) );
		}
		$rate = self::consume_ip_start_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		$now = self::now();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'public_id'     => self::opaque_id(),
				'session_hash'  => $hash,
				'user_id'       => get_current_user_id(),
				'theme'         => get_stylesheet(),
				'status'        => 'active',
				'created_at'    => $now,
				'last_activity' => $now,
				'expires_at'    => self::expires(),
				'ip_hash'       => self::ip_hash(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'eds_create_failed', __( 'The demo workspace could not be created.', 'elementor-sandbox' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		foreach ( self::$modules as $m ) {
			if ( ! method_exists( $m, 'snapshot' ) ) {
				continue;
			}
			$result = $m::snapshot( $id );
			if ( is_wp_error( $result ) ) {
				self::revoke( $id );
				return $result;
			}
		}
		$wpdb->update( $table, array( 'ready' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		return self::by_id( $id );
	}

	public static function by_public_id( $public_id ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) $public_id ) ) {
			return null;
		}
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function by_id( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function is_live( $ws ) {
		return is_array( $ws ) && 'active' === $ws['status'] && strtotime( $ws['expires_at'] . ' UTC' ) > time();
	}

	public static function owns_current_session( $ws ) {
		return self::is_live( $ws )
			&& (int) $ws['user_id'] === get_current_user_id()
			&& hash_equals( (string) $ws['session_hash'], self::session_hash() );
	}

	public static function touch( $id ) {
		global $wpdb;
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array( 'last_activity' => self::now(), 'expires_at' => self::expires() ),
			array( 'id' => (int) $id, 'status' => 'active' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	private static function purge( array $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( array_reverse( self::$modules ) as $m ) {
			if ( method_exists( $m, 'delete_workspace_data' ) ) {
				$m::delete_workspace_data( $ids );
			}
		}
	}

	public static function cleanup() {
		global $wpdb;
		$table   = self::table();
		$expired = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB
		if ( $expired ) {
			$wpdb->query( "UPDATE {$table} SET status = 'expired' WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB
			self::purge( $expired );
		}
		$old = $wpdb->get_col( "SELECT id FROM {$table} WHERE status <> 'active' AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)" ); // phpcs:ignore WordPress.DB
		if ( $old ) {
			self::purge( $old ); // idempotent: anything a crash left behind
			$in = implode( ',', array_map( 'absint', $old ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
	}

	public static function revoke( $id ) {
		global $wpdb;
		$ok = false !== $wpdb->update( self::table(), array( 'status' => 'revoked' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		self::purge( array( $id ) );
		return $ok;
	}

	private static function retire( $id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array( 'status' => 'expired', 'session_hash' => hash( 'sha256', wp_generate_uuid4() . '|' . random_int( 1, PHP_INT_MAX ) ) ),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::purge( array( $id ) );
	}

	public static function revoke_all() {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "UPDATE {$table} SET status = 'revoked' WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
		self::purge( $ids );
	}

	public static function delete_all_owned() {
		foreach ( array_reverse( self::$modules ) as $m ) {
			if ( method_exists( $m, 'delete_all_owned' ) ) {
				$m::delete_all_owned();
			}
		}
	}

	public static function drop_tables() {
		global $wpdb;
		foreach ( self::$modules as $m ) {
			if ( method_exists( $m, 'drop_tables' ) ) {
				$m::drop_tables();
			}
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	private static function consume_ip_start_limit() {
		$key   = 'eds_start_' . substr( self::ip_hash(), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			return new WP_Error( 'eds_rate_limit', __( 'Too many demo sessions were started from this address. Try again later.', 'elementor-sandbox' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	public static function consume_save_limit( $id ) {
		$key   = 'eds_save_' . absint( $id );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return new WP_Error( 'eds_save_limit', __( 'Too many saves. Wait a minute and try again.', 'elementor-sandbox' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
