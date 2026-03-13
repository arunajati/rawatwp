<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	/**
	 * Option key: first install timestamp.
	 */
	const OPTION_INSTALLED_AT = 'rawatwp_installed_at';

	/**
	 * Option key: post-activation redirect marker.
	 */
	const OPTION_ACTIVATION_REDIRECT = 'rawatwp_activation_redirect';

	/**
	 * Plugin activation callback.
	 *
	 * @param bool $network_wide True if network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		$database = new Database();
		$database->maybe_upgrade_schema();

		if ( false === get_option( ModeManager::OPTION_MODE, false ) ) {
			add_option( ModeManager::OPTION_MODE, '' );
		}

		if ( false === get_option( 'rawatwp_delete_all_on_uninstall', false ) ) {
			add_option( 'rawatwp_delete_all_on_uninstall', '1' );
		}

		$is_first_install = false === get_option( self::OPTION_INSTALLED_AT, false );
		if ( $is_first_install ) {
			add_option( self::OPTION_INSTALLED_AT, gmdate( 'Y-m-d H:i:s' ) );
		}

		self::ensure_storage_directories();

		if ( $is_first_install && is_admin() && ! ( is_multisite() && $network_wide ) ) {
			update_option( self::OPTION_ACTIVATION_REDIRECT, '1', false );
		}
	}

	/**
	 * Ensure required storage directories exist.
	 *
	 * @return void
	 */
	private static function ensure_storage_directories() {
		$upload_dir  = wp_upload_dir();
		$base_dir    = trailingslashit( $upload_dir['basedir'] ) . 'rawatwp';
		$updates_dir = wp_normalize_path( trailingslashit( ABSPATH ) . 'updates' );

		$dirs = array(
			$updates_dir,
			$base_dir,
			$base_dir . '/backups',
			$base_dir . '/temp',
		);

		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			if ( file_exists( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}

		self::ensure_private_storage_guards( $updates_dir );
		self::ensure_private_storage_guards( $base_dir );
	}

	/**
	 * Create web server guards to prevent direct public package access.
	 *
	 * @param string $base_dir RawatWP storage base directory.
	 * @return void
	 */
	private static function ensure_private_storage_guards( $base_dir ) {
		$htaccess_file = $base_dir . '/.htaccess';
		$web_config    = $base_dir . '/web.config';

		if ( ! file_exists( $htaccess_file ) ) {
			$content = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess_file, $content );
		}

		if ( ! file_exists( $web_config ) ) {
			$content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";
			file_put_contents( $web_config, $content );
		}
	}
}
