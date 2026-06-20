<?php
/**
 * Browser caching and compression rules for Apache/LiteSpeed via .htaccess.
 * Nginx users get the equivalent rules shown in the admin to copy manually.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Htaccess {

	const MARKER = 'PageSpeedPlus';

	/**
	 * Insert or refresh our rule block.
	 */
	public static function update_rules() {
		if ( ! self::is_apache() ) {
			return;
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$htaccess = get_home_path() . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $htaccess, self::MARKER, explode( "\n", self::build_rules() ) );
	}

	/**
	 * Remove our rule block.
	 */
	public static function remove_rules() {
		if ( ! self::is_apache() ) {
			return;
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$htaccess = get_home_path() . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $htaccess, self::MARKER, array() );
	}

	/**
	 * @return string Rules block.
	 */
	public static function build_rules() {
		$rules = '';

		if ( PSP_Options::get( 'webp_enabled' ) ) {
			$rules .= PSP_WebP::htaccess_rules();
		}

		if ( PSP_Options::get( 'browser_cache' ) ) {
			$rules .= <<<RULES
<IfModule mod_expires.c>
ExpiresActive On
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/png "access plus 1 year"
ExpiresByType image/gif "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
ExpiresByType image/avif "access plus 1 year"
ExpiresByType image/svg+xml "access plus 1 year"
ExpiresByType image/x-icon "access plus 1 year"
ExpiresByType video/mp4 "access plus 1 year"
ExpiresByType font/woff2 "access plus 1 year"
ExpiresByType font/woff "access plus 1 year"
ExpiresByType font/ttf "access plus 1 year"
ExpiresByType text/css "access plus 1 month"
ExpiresByType application/javascript "access plus 1 month"
ExpiresByType text/javascript "access plus 1 month"
ExpiresByType text/html "access plus 0 seconds"
</IfModule>
<IfModule mod_headers.c>
<FilesMatch "\\.(woff2?|ttf|otf|eot)$">
Header set Access-Control-Allow-Origin "*"
</FilesMatch>
<FilesMatch "\\.(jpg|jpeg|png|gif|webp|avif|svg|ico|woff2?|ttf|css|js|mp4)$">
Header set Cache-Control "public, immutable"
</FilesMatch>
</IfModule>

RULES;
		}

		if ( PSP_Options::get( 'gzip_compression' ) ) {
			$rules .= <<<RULES
<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml text/javascript
AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json
AddOutputFilterByType DEFLATE application/xml application/rss+xml application/atom+xml
AddOutputFilterByType DEFLATE image/svg+xml font/ttf font/otf application/vnd.ms-fontobject
</IfModule>
RULES;
		}

		return trim( $rules );
	}

	/**
	 * Equivalent Nginx config shown in the admin for manual installation.
	 *
	 * @return string
	 */
	public static function nginx_rules() {
		return <<<RULES
# PageSpeedPlus — add inside your server {} block
location ~* \\.(jpg|jpeg|png|gif|webp|avif|svg|ico|woff2?|ttf|css|js|mp4)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
gzip on;
gzip_comp_level 6;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/rss+xml image/svg+xml;
RULES;
	}

	/**
	 * @return bool
	 */
	private static function is_apache() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
	}
}
