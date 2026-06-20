<?php
/**
 * Plugin Name:       PageSpeedPlus
 * Plugin URI:        https://pagespeedplus.com/wordpress-plugin
 * Description:       All-in-one performance suite: page caching, CSS/JS optimization, delayed JavaScript, lazy loading, Core Web Vitals improvements and more.
 * Version:           1.13.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PageSpeedPlus
 * Author URI:        https://pagespeedplus.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pagespeedplus
 */

defined( 'ABSPATH' ) || exit;

define( 'PSP_VERSION', '1.13.3' );
define( 'PSP_FILE', __FILE__ );
define( 'PSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSP_URL', plugin_dir_url( __FILE__ ) );
define( 'PSP_CACHE_DIR', WP_CONTENT_DIR . '/cache/pagespeedplus/' );
define( 'PSP_ASSET_CACHE_DIR', WP_CONTENT_DIR . '/cache/pagespeedplus-assets/' );
define( 'PSP_ASSET_CACHE_URL', content_url( '/cache/pagespeedplus-assets/' ) );
define( 'PSP_FONTS_CACHE_DIR', WP_CONTENT_DIR . '/cache/pagespeedplus-fonts/' );
define( 'PSP_FONTS_CACHE_URL', content_url( '/cache/pagespeedplus-fonts/' ) );

require_once PSP_DIR . 'includes/class-psp-options.php';
require_once PSP_DIR . 'includes/class-psp-plugin.php';

register_activation_hook( __FILE__, array( 'PSP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PSP_Plugin', 'deactivate' ) );

PSP_Plugin::instance();
