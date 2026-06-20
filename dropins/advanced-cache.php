<?php
/**
 * Advanced cache drop-in for PageSpeedPlus.
 *
 * Serves cached pages before WordPress loads. Standalone: must not use
 * any WordPress functions. Installed to wp-content/advanced-cache.php.
 *
 * @package PageSpeedPlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

psp_serve_cache();

function psp_serve_cache() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	$cache_root = WP_CONTENT_DIR . '/cache/pagespeedplus/';
	$config_file = $cache_root . 'config.php';
	if ( ! file_exists( $config_file ) ) {
		return;
	}
	$config = include $config_file;
	if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
		return;
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	$path = (string) parse_url( $uri, PHP_URL_PATH );
	$query = (string) parse_url( $uri, PHP_URL_QUERY );

	// Query strings: serve the base page only when every param is an ignorable tracking param.
	if ( '' !== $query ) {
		parse_str( $query, $params );
		$ignorable = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid', 'ref', 'mc_cid', 'mc_eid' );
		if ( array_diff( array_keys( $params ), $ignorable ) ) {
			return;
		}
	}

	// Excluded URLs (substring, or glob when the pattern contains *).
	if ( ! empty( $config['exclude_urls'] ) ) {
		foreach ( (array) $config['exclude_urls'] as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				if ( preg_match( '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i', $path ) ) {
					return;
				}
			} elseif ( false !== strpos( $path, $pattern ) ) {
				return;
			}
		}
	}

	// Cookie-based exclusions (cart contents, commenters, password-protected posts).
	foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
		if ( empty( $config['cache_logged_in'] ) && 0 === strpos( $cookie_name, 'wordpress_logged_in' ) ) {
			return;
		}
		if ( ! empty( $config['exclude_cookies'] ) ) {
			foreach ( (array) $config['exclude_cookies'] as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $cookie_name, $pattern ) ) {
					return;
				}
			}
		}
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/[^a-z0-9._\-]/i', '', $_SERVER['HTTP_HOST'] ) ) : 'unknown';
	$path = preg_replace( '/\.{2,}/', '', $path );

	$is_mobile = ! empty( $config['mobile_separate'] ) && psp_is_mobile();
	$filename  = $is_mobile ? 'index-mobile.html' : 'index.html';
	$file      = rtrim( $cache_root . $host . $path, '/' ) . '/' . $filename;

	if ( ! file_exists( $file ) ) {
		return;
	}

	// Expired?
	$lifetime = isset( $config['lifetime'] ) ? (int) $config['lifetime'] : 0;
	$mtime    = (int) filemtime( $file );
	if ( $lifetime > 0 && ( time() - $mtime ) > $lifetime ) {
		return;
	}

	// 304 support. Use a WEAK etag (W/"...") — nginx's gzip filter strips
	// strong etags from upstream responses, which would kill conditional
	// revalidation on nginx. Weak etags survive, and are semantically correct
	// here anyway (the gzipped and plain copies are the same resource).
	$etag = 'W/"psp-' . md5( $file . $mtime ) . '"';
	$inm  = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( stripslashes( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
	if ( '' !== $inm && ( $inm === $etag || $inm === 'W/' . $etag || ltrim( $inm, 'W/' ) === ltrim( $etag, 'W/' ) ) ) {
		header( 'HTTP/1.1 304 Not Modified' );
		exit;
	}

	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Vary: Accept-Encoding' . ( ! empty( $config['mobile_separate'] ) ? ', User-Agent' : '' ) );
	header( 'ETag: ' . $etag );
	header( 'X-PageSpeedPlus-Cache: HIT' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );

	// Serve a pre-compressed copy when the client accepts it. Prefer Brotli
	// (smaller) over gzip; fall back to the plain file.
	$accept       = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
	$accepts_br   = false !== strpos( $accept, 'br' );
	$accepts_gzip = false !== strpos( $accept, 'gzip' );
	$no_zlib      = ! ini_get( 'zlib.output_compression' );
	if ( $accepts_br && $no_zlib && file_exists( $file . '.br' ) ) {
		header( 'Content-Encoding: br' );
		readfile( $file . '.br' );
	} elseif ( $accepts_gzip && $no_zlib && file_exists( $file . '.gz' ) ) {
		header( 'Content-Encoding: gzip' );
		readfile( $file . '.gz' );
	} else {
		readfile( $file );
	}
	exit;
}

function psp_is_mobile() {
	if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return false;
	}
	$ua = $_SERVER['HTTP_USER_AGENT'];
	return (bool) preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/i', $ua )
		&& ! preg_match( '/iPad/i', $ua );
}
