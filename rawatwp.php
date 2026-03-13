<?php
/**
 * Plugin Name: RawatWP
 * Plugin URI: https://arunajr.com
 * Description: Master-child updater orchestration plugin with secure push, backup, rollback, and logging.
 * Version: 0.1.61
 * Author: arunajr.com
 * Text Domain: rawatwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'rawatwp/rawatwp.php' !== plugin_basename( __FILE__ ) ) {
	if ( is_admin() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ), true );
		}
		add_action(
			'admin_notices',
			static function() {
				echo '<div class="notice notice-error"><p>RawatWP was auto-deactivated because the plugin folder is invalid. Use plugin folder: <code>rawatwp</code>.</p></div>';
			}
		);
	}
	return;
}

define( 'RAWATWP_VERSION', '0.1.61' );
define( 'RAWATWP_FILE', __FILE__ );
define( 'RAWATWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAWATWP_URL', plugin_dir_url( __FILE__ ) );
define( 'RAWATWP_SITE_URL', 'https://arunajr.com' );

// GitHub self-update source (internal preset).
define( 'RAWATWP_GH_DEFAULT_ENABLED', true );
define( 'RAWATWP_GH_REPO', 'rawatwp' );
define( 'RAWATWP_GH_TOKEN', '' );

require_once RAWATWP_DIR . 'src/Autoloader.php';

\RawatWP\Autoloader::register();

register_activation_hook( RAWATWP_FILE, array( '\\RawatWP\\Core\\Activator', 'activate' ) );

add_action(
	'plugins_loaded',
	static function() {
		\RawatWP\Plugin::instance()->init();
	}
);
