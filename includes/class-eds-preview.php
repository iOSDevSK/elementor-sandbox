<?php
/**
 * The public preview of a workspace (/preview<id>/…): rendered anonymously,
 * read only (no forms, no requests out), never indexed, links kept below the
 * prefix. Expired or revoked links answer 410, unknown ones 404.
 */

defined( 'ABSPATH' ) || exit;

final class EDS_Preview {
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'prepare' ), -100 );
		add_action( 'wp_footer', array( __CLASS__, 'guard' ), 999 );
	}

	public static function prepare() {
		$mode = EDS_Context::mode();
		if ( in_array( $mode, array( 'invalid', 'expired', 'revoked' ), true ) ) {
			self::dead_link( 'invalid' === $mode ? 404 : 410 );
		}
		if ( ! EDS_Context::ws() ) {
			return;
		}
		// a page cache (Cache Enabler, WP Rocket, LiteSpeed, …) must never keep a
		// workspace page: edits would not show and an expired link would not end
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		add_filter( 'cache_enabler_bypass_cache', '__return_true' );
		add_filter( 'autoptimize_filter_noptimize', '__return_true' );
		add_filter( 'show_admin_bar', '__return_false' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		nocache_headers();
		if ( ! EDS_Context::is_public() ) {
			return;
		}
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true );
		// no `sandbox`: it gives the page an opaque origin, and the theme's own fonts
		// (same site) then fail CORS. The demo cannot add scripts (no unfiltered_html,
		// no SVG uploads); what is locked is forms and requests to other sites.
		header( "Content-Security-Policy: default-src 'self' https: data: blob:; script-src 'self' 'unsafe-inline' https: blob:; worker-src 'self' blob:; style-src 'self' 'unsafe-inline' https:; img-src 'self' https: http: data: blob:; font-src 'self' https: data:; media-src 'self' https: http: data: blob:; connect-src 'self'; form-action 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'", true );
		ob_start( array( __CLASS__, 'rewrite_links' ) );
	}

	public static function robots( $robots ) {
		return array( 'noindex' => true, 'nofollow' => true, 'noarchive' => true );
	}

	public static function guard() {
		if ( ! EDS_Context::is_public() ) {
			return;
		}
		echo '<style id="eds-public-guard">form button[type="submit"],form input[type="submit"]{pointer-events:none;opacity:.55}</style>';
		echo '<script>document.addEventListener("submit",function(e){e.preventDefault();e.stopImmediatePropagation();},true);</script>';
	}

	/** Same-site links stay inside the preview. */
	public static function rewrite_links( $html ) {
		$ws = EDS_Context::ws();
		if ( ! $ws ) {
			return $html;
		}
		$home      = home_url( '/' );
		$home_host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$home_path = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
		$prefix    = $home_path . '/preview' . $ws['public_id'];
		return preg_replace_callback(
			'/<a\b([^>]*?)\bhref=("|\')(.*?)\2([^>]*)>/is',
			function ( $m ) use ( $home_host, $home_path, $prefix, $ws ) {
				$url = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' === $url || '#' === $url[0] || preg_match( '#^(?:mailto|tel|javascript|data):#i', $url ) ) {
					return $m[0];
				}
				$abs  = preg_match( '#^https?://#i', $url ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
				$host = strtolower( (string) wp_parse_url( $abs, PHP_URL_HOST ) );
				$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
				if ( $host !== $home_host || 0 === strpos( $path, $prefix . '/' ) || preg_match( '#/wp-(admin|login|content|includes)/#', $path ) ) {
					return $m[0];
				}
				$rel   = ltrim( substr( $path, strlen( $home_path ) ), '/' );
				$out   = EDS_Context::preview_url( $ws, $rel );
				$query = (string) wp_parse_url( $abs, PHP_URL_QUERY );
				$frag  = (string) wp_parse_url( $abs, PHP_URL_FRAGMENT );
				$out  .= ( $query ? '?' . $query : '' ) . ( $frag ? '#' . $frag : '' );
				return '<a' . $m[1] . 'href=' . $m[2] . esc_attr( $out ) . $m[2] . $m[4] . '>';
			},
			(string) $html
		);
	}

	private static function dead_link( $status ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		add_filter( 'cache_enabler_bypass_cache', '__return_true' );
		status_header( $status );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		$title   = 404 === $status ? __( 'Preview not found', 'elementor-sandbox' ) : __( 'Demo preview expired', 'elementor-sandbox' );
		$message = 404 === $status
			? __( 'This preview link is invalid.', 'elementor-sandbox' )
			: __( 'This preview is no longer available. Sign in to the demo to start again from the original design.', 'elementor-sandbox' );
		wp_die(
			'<div style="max-width:560px;margin:12vh auto;font:16px/1.55 system-ui;text-align:center"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></div>',
			esc_html( $title ),
			array( 'response' => (int) $status )
		);
	}
}
