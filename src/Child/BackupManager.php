<?php
namespace RawatWP\Child;

use RawatWP\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupManager {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Create one backup snapshot (latest only).
	 *
	 * @param string $target_path Target path.
	 * @param string $slug Item slug.
	 * @return string|\WP_Error
	 */
	public function create_backup( $target_path, $slug ) {
		$target_path = wp_normalize_path( $target_path );
		$slug        = sanitize_title( $slug );

		if ( '' === $target_path || '' === $slug || ! file_exists( $target_path ) ) {
			return new \WP_Error( 'rawatwp_backup_target_missing', __( 'Backup target not found.', 'rawatwp' ) );
		}

		$backup_root = $this->get_backup_root() . '/' . $slug;
		$backup_path = $backup_root . '/latest';

		if ( file_exists( $backup_path ) ) {
			$this->recursive_delete( $backup_path );
		}

		wp_mkdir_p( $backup_root );

		$copied = $this->recursive_copy( $target_path, $backup_path );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		return $backup_path;
	}

	/**
	 * Restore backup to target path.
	 *
	 * @param string $backup_path Backup path.
	 * @param string $target_path Target path.
	 * @return true|\WP_Error
	 */
	public function restore_backup( $backup_path, $target_path ) {
		$backup_path = wp_normalize_path( $backup_path );
		$target_path = wp_normalize_path( $target_path );

		if ( ! file_exists( $backup_path ) ) {
			return new \WP_Error( 'rawatwp_backup_not_found', __( 'Backup not found.', 'rawatwp' ) );
		}

		if ( file_exists( $target_path ) ) {
			$this->recursive_delete( $target_path );
		}

		$copied = $this->recursive_copy( $backup_path, $target_path );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		return true;
	}

	/**
	 * Recursive copy folder contents.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @return true|\WP_Error
	 */
	public function recursive_copy( $source, $destination ) {
		$source      = wp_normalize_path( $source );
		$destination = wp_normalize_path( $destination );

		if ( is_link( $source ) ) {
			return new \WP_Error( 'rawatwp_symlink_source', __( 'Backup source symlink is not allowed.', 'rawatwp' ) );
		}

		if ( is_file( $source ) ) {
			$parent = dirname( $destination );
			if ( ! file_exists( $parent ) ) {
				wp_mkdir_p( $parent );
			}

			if ( ! @copy( $source, $destination ) ) {
				return new \WP_Error( 'rawatwp_copy_file_failed', sprintf( 'Failed to copy file %s', $source ) );
			}

			return true;
		}

		if ( ! file_exists( $destination ) ) {
			wp_mkdir_p( $destination );
		}

		$entries = scandir( $source );
		if ( false === $entries ) {
			return new \WP_Error( 'rawatwp_read_dir_failed', sprintf( 'Failed to read directory %s', $source ) );
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$src = $source . '/' . $entry;
			$dst = $destination . '/' . $entry;

			if ( is_link( $src ) ) {
				continue;
			}

			$result = $this->recursive_copy( $src, $dst );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Remove file/folder recursively.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public function recursive_delete( $path ) {
		$path = wp_normalize_path( $path );
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}

		$entries = scandir( $path );
		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->recursive_delete( $path . '/' . $entry );
			}
		}

		@rmdir( $path );
	}

	/**
	 * Get backup root path.
	 *
	 * @return string
	 */
	private function get_backup_root() {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'rawatwp/backups';

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		return wp_normalize_path( $base );
	}
}
