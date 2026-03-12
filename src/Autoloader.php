<?php
namespace RawatWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	/**
	 * Register PSR-4 style autoload for RawatWP namespace.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load class file.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = RAWATWP_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
