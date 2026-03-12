<?php
/**
 * Uninstall RawatWP.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'rawatwp_delete_all_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'rawatwp_sites',
	$wpdb->prefix . 'rawatwp_packages',
	$wpdb->prefix . 'rawatwp_logs',
	$wpdb->prefix . 'rawatwp_queue',
);

foreach ( $tables as $table_name ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}

$options = array(
	'rawatwp_mode',
	'rawatwp_master_settings',
	'rawatwp_child_settings',
	'rawatwp_monitored_items',
	'rawatwp_delete_all_on_uninstall',
	'rawatwp_schema_version',
	'rawatwp_queue_paused',
	'rawatwp_queue_runner_token',
	'rawatwp_logs_last_maintenance',
	'rawatwp_github_updater',
	'rawatwp_github_last_error',
);

foreach ( $options as $option_name ) {
	delete_option( $option_name );
}

$transient_like_patterns = array(
	'_transient_rawatwp_%',
	'_transient_timeout_rawatwp_%',
	'_site_transient_rawatwp_%',
	'_site_transient_timeout_rawatwp_%',
);

foreach ( $transient_like_patterns as $pattern ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$pattern
		)
	);
}

if ( ! function_exists( 'rawatwp_recursive_delete' ) ) {
	/**
	 * Recursively delete path.
	 *
	 * @param string $path Target path.
	 * @return void
	 */
	function rawatwp_recursive_delete( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		$entries = scandir( $path );
		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				rawatwp_recursive_delete( $path . '/' . $entry );
			}
		}

		@rmdir( $path );
	}
}

$upload_dir   = wp_upload_dir();
$rawatwp_dir  = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'rawatwp' );
$updates_dir  = wp_normalize_path( trailingslashit( ABSPATH ) . 'updates' );
$plugin_root  = wp_normalize_path( WP_PLUGIN_DIR );

rawatwp_recursive_delete( $rawatwp_dir );
rawatwp_recursive_delete( $updates_dir );

$archives = glob( trailingslashit( $plugin_root ) . 'rawatwp-*.zip' );
if ( false !== $archives ) {
	foreach ( $archives as $archive_file ) {
		$archive_file = wp_normalize_path( $archive_file );
		if ( is_file( $archive_file ) ) {
			@unlink( $archive_file );
		}
	}
}
