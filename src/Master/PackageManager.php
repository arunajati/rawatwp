<?php
namespace RawatWP\Master;

use RawatWP\Core\Database;
use RawatWP\Core\Logger;
use RawatWP\Core\SecurityManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PackageManager {
	/**
	 * Database.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Security manager.
	 *
	 * @var SecurityManager
	 */
	private $security;

	/**
	 * Constructor.
	 *
	 * @param Database        $database Database.
	 * @param Logger          $logger Logger.
	 * @param SecurityManager $security Security.
	 */
	public function __construct( Database $database, Logger $logger, SecurityManager $security ) {
		$this->database = $database;
		$this->logger   = $logger;
		$this->security = $security;
	}

	/**
	 * Upload package zip and store metadata.
	 *
	 * @param array $file Uploaded file.
	 * @param array $meta Optional package metadata.
	 * @return array|\WP_Error
	 */
	public function upload_package( array $file, array $meta = array() ) {

		if ( empty( $file['name'] ) || empty( $file['tmp_name'] ) ) {
			return new \WP_Error( 'rawatwp_no_file', __( 'Zip file is required.', 'rawatwp' ) );
		}

		$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( 0 !== $error_code ) {
			return new \WP_Error( 'rawatwp_upload_error', $this->get_upload_error_message( $error_code ) );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'zip' !== $extension ) {
			return new \WP_Error( 'rawatwp_not_zip', __( 'Only zip files are allowed.', 'rawatwp' ) );
		}

		$packages_dir = $this->get_packages_directory();
		if ( ! file_exists( $packages_dir ) ) {
			if ( ! wp_mkdir_p( $packages_dir ) ) {
				return new \WP_Error( 'rawatwp_updates_dir_missing', __( 'Updates folder cannot be created.', 'rawatwp' ) );
			}
		}

		$target_file_name = sprintf(
			'%s-%s-%s.zip',
			gmdate( 'Ymd-His' ),
			sanitize_file_name( wp_basename( $file['name'], '.zip' ) ),
			wp_generate_password( 6, false, false )
		);

		$target_path      = trailingslashit( $packages_dir ) . $target_file_name;
		$target_temp_path = $target_path . '.part';

		if ( ! @move_uploaded_file( $file['tmp_name'], $target_temp_path ) ) {
			if ( ! @copy( $file['tmp_name'], $target_temp_path ) ) {
				return new \WP_Error( 'rawatwp_move_failed', __( 'Failed to save uploaded file.', 'rawatwp' ) );
			}
		}

		$detected = $this->detect_package_meta_from_zip( $target_temp_path );
		if ( is_wp_error( $detected ) ) {
			@unlink( $target_temp_path );
			return $detected;
		}

		$type        = $detected['type'];
		$target_slug = $detected['target_slug'];
		$label       = $detected['label'];

		$hash       = hash_file( 'sha256', $target_temp_path );
		$existing   = $this->database->get_package_by_hash( $hash );
		if ( $existing ) {
			@unlink( $target_temp_path );
			return new \WP_Error( 'rawatwp_package_duplicate', __( 'This zip file is already registered.', 'rawatwp' ) );
		}

		if ( ! @rename( $target_temp_path, $target_path ) ) {
			@unlink( $target_temp_path );
			return new \WP_Error( 'rawatwp_move_failed', __( 'Failed to finalize uploaded file.', 'rawatwp' ) );
		}

		$package_id = $this->database->insert_package(
			array(
				'label'       => $label,
				'type'        => $type,
				'target_slug' => $target_slug,
				'file_name'   => $target_file_name,
				'file_path'   => $target_path,
				'file_hash'   => $hash,
			)
		);

		if ( false === $package_id ) {
			@unlink( $target_path );
			return new \WP_Error( 'rawatwp_package_insert_failed', __( 'Failed to save package metadata.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'      => 'master',
				'action'    => 'package_uploaded',
				'status'    => 'success',
				'item_type' => $type,
				'item_slug' => $target_slug,
				'message'   => sprintf( 'Package %s uploaded successfully.', $label ),
				'context'   => array(
					'package_id' => $package_id,
					'file_name'  => $target_file_name,
				),
			)
		);

		$package = $this->database->get_package_by_id( $package_id );

		return is_array( $package ) ? $package : array();
	}

	/**
	 * Scan /public_html/updates for zip packages and import to DB.
	 *
	 * @return array|\WP_Error
	 */
	public function scan_updates_directory() {
		$packages_dir = $this->get_packages_directory();
		if ( ! file_exists( $packages_dir ) ) {
			if ( ! wp_mkdir_p( $packages_dir ) ) {
				return new \WP_Error( 'rawatwp_updates_dir_missing', __( 'Updates folder cannot be created.', 'rawatwp' ) );
			}
		}

		$files = glob( trailingslashit( $packages_dir ) . '*.zip' );
		if ( false === $files ) {
			return new \WP_Error( 'rawatwp_scan_failed', __( 'Failed to read updates folder.', 'rawatwp' ) );
		}

		sort( $files );

		$result = array(
			'folder'   => $packages_dir,
			'total'    => count( $files ),
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'details'  => array(),
		);

		foreach ( $files as $file_path ) {
			$file_path = wp_normalize_path( $file_path );
			if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
				$result['failed']++;
				$result['details'][] = array(
					'file'   => basename( $file_path ),
					'status' => 'failed',
					'reason' => 'File cannot be read.',
				);
				continue;
			}

			$file_hash = hash_file( 'sha256', $file_path );
			if ( ! is_string( $file_hash ) || '' === $file_hash ) {
				$result['failed']++;
				$result['details'][] = array(
					'file'   => basename( $file_path ),
					'status' => 'failed',
					'reason' => 'Failed to calculate file hash.',
				);
				continue;
			}

			$existing = $this->database->get_package_by_hash( $file_hash );
			if ( $existing ) {
				$result['skipped']++;
				$result['details'][] = array(
					'file'   => basename( $file_path ),
					'status' => 'skipped',
					'reason' => 'Already registered.',
				);
				continue;
			}

			$detected = $this->detect_package_meta_from_zip( $file_path );
			if ( is_wp_error( $detected ) ) {
				$error_code = $detected->get_error_code();
				if ( in_array( $error_code, array( 'rawatwp_unsupported_zip', 'rawatwp_bad_slug' ), true ) ) {
					$result['skipped']++;
					$result['details'][] = array(
						'file'   => basename( $file_path ),
						'status' => 'skipped',
						'reason' => $detected->get_error_message(),
					);
				} else {
					$result['failed']++;
					$result['details'][] = array(
						'file'   => basename( $file_path ),
						'status' => 'failed',
						'reason' => $detected->get_error_message(),
					);
				}
				continue;
			}

			$inserted_id = $this->database->insert_package(
				array(
					'label'       => $detected['label'],
					'type'        => $detected['type'],
					'target_slug' => $detected['target_slug'],
					'file_name'   => basename( $file_path ),
					'file_path'   => $file_path,
					'file_hash'   => $file_hash,
				)
			);

			if ( false === $inserted_id ) {
				$result['failed']++;
				$result['details'][] = array(
					'file'   => basename( $file_path ),
					'status' => 'failed',
					'reason' => 'Failed to save package metadata.',
				);
				continue;
			}

			$result['imported']++;
			$result['details'][] = array(
				'file'        => basename( $file_path ),
				'status'      => 'imported',
				'type'        => $detected['type'],
				'target_slug' => $detected['target_slug'],
			);

			$this->logger->log(
				array(
					'mode'      => 'master',
					'action'    => 'package_scanned',
					'status'    => 'success',
					'item_type' => $detected['type'],
					'item_slug' => $detected['target_slug'],
					'message'   => sprintf( 'Package imported from updates folder: %s', basename( $file_path ) ),
					'context'   => array(
						'file_path' => $file_path,
					),
				)
			);
		}

		return $result;
	}

	/**
	 * Get package by ID.
	 *
	 * @param int $package_id Package ID.
	 * @return array|null
	 */
	public function get_package( $package_id ) {
		return $this->database->get_package_by_id( $package_id );
	}

	/**
	 * Get package list.
	 *
	 * @return array
	 */
	public function get_packages() {
		return $this->database->get_packages();
	}

	/**
	 * Delete one package (file + DB metadata).
	 *
	 * @param int $package_id Package ID.
	 * @return array|\WP_Error
	 */
	public function delete_package( $package_id ) {
		$package_id = (int) $package_id;
		if ( $package_id <= 0 ) {
			return new \WP_Error( 'rawatwp_bad_package_id', __( 'Invalid package.', 'rawatwp' ) );
		}

		$package = $this->database->get_package_by_id( $package_id );
		if ( ! $package ) {
			return new \WP_Error( 'rawatwp_package_not_found', __( 'Package not found.', 'rawatwp' ) );
		}

		$file_path      = isset( $package['file_path'] ) ? wp_normalize_path( (string) $package['file_path'] ) : '';
		$file_deleted   = false;
		$file_delete_ok = true;

		if ( '' !== $file_path && file_exists( $file_path ) && is_file( $file_path ) ) {
			if ( ! $this->is_path_in_updates_dir( $file_path ) ) {
				return new \WP_Error( 'rawatwp_package_path_invalid', __( 'Invalid package file location for auto delete.', 'rawatwp' ) );
			}

			$file_deleted   = @unlink( $file_path );
			$file_delete_ok = $file_deleted;
			if ( ! $file_delete_ok ) {
				return new \WP_Error( 'rawatwp_package_file_delete_failed', __( 'Failed to delete package zip file.', 'rawatwp' ) );
			}
		}

		$deleted = $this->database->delete_package( $package_id );
		if ( ! $deleted ) {
			return new \WP_Error( 'rawatwp_package_delete_failed', __( 'Failed to delete package data from database.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'      => 'master',
				'action'    => 'package_deleted',
					'status'    => 'success',
				'item_type' => isset( $package['type'] ) ? $package['type'] : '',
				'item_slug' => isset( $package['target_slug'] ) ? $package['target_slug'] : '',
				'message'   => sprintf( 'Package deleted: %s', isset( $package['file_name'] ) ? $package['file_name'] : (string) $package_id ),
				'context'   => array(
					'package_id'     => $package_id,
					'file_path'      => $file_path,
					'file_deleted'   => $file_deleted,
					'file_delete_ok' => $file_delete_ok,
				),
			)
		);

		return array(
			'package_id'     => $package_id,
			'file_deleted'   => $file_deleted,
			'file_delete_ok' => $file_delete_ok,
		);
	}

	/**
	 * Delete multiple packages.
	 *
	 * @param array $package_ids Package IDs.
	 * @return array
	 */
	public function delete_packages( array $package_ids ) {
		$package_ids = array_values( array_unique( array_filter( array_map( 'intval', $package_ids ) ) ) );

		$result = array(
			'requested' => count( $package_ids ),
			'deleted'   => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		foreach ( $package_ids as $package_id ) {
			$deleted = $this->delete_package( $package_id );
			if ( is_wp_error( $deleted ) ) {
				$result['failed']++;
				$result['errors'][] = $deleted->get_error_message();
				continue;
			}

			$result['deleted']++;
		}

		return $result;
	}

	/**
	 * Get absolute updates folder path.
	 *
	 * @return string
	 */
	public function get_updates_directory_path() {
		return $this->get_packages_directory();
	}

	/**
	 * Build secure package download URL for child.
	 *
	 * @param array $package Package row.
	 * @param array $site Child site row.
	 * @return string
	 */
	public function build_download_url_for_site( array $package, array $site ) {
		$timestamp = time();
		$nonce     = wp_generate_uuid4();
		$signature = $this->security->sign_download_tuple( $package['id'], $site['id'], $timestamp, $nonce, $site['security_key'] );

		$base_url = rest_url( 'rawatwp/v1/master/package-download/' . (int) $package['id'] );

		return add_query_arg(
			array(
				'child_id'  => (int) $site['id'],
				'timestamp' => $timestamp,
				'nonce'     => $nonce,
				'signature' => $signature,
			),
			$base_url
		);
	}

	/**
	 * Get package directory.
	 *
	 * @return string
	 */
	private function get_packages_directory() {
		return wp_normalize_path( trailingslashit( ABSPATH ) . 'updates' );
	}

	/**
	 * Check whether path is inside updates directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_path_in_updates_dir( $path ) {
		$base = trailingslashit( wp_normalize_path( $this->get_packages_directory() ) );
		$path = wp_normalize_path( (string) $path );

		return 0 === strpos( $path, $base );
	}

	/**
	 * Resolve upload error message from PHP upload error code.
	 *
	 * @param int $error_code Upload error code.
	 * @return string
	 */
	private function get_upload_error_message( $error_code ) {
		$error_code = (int) $error_code;

		if ( UPLOAD_ERR_INI_SIZE === $error_code || UPLOAD_ERR_FORM_SIZE === $error_code ) {
			return __( 'Upload failed: file exceeds server upload limit.', 'rawatwp' );
		}

		if ( UPLOAD_ERR_PARTIAL === $error_code ) {
			return __( 'Upload interrupted: file was only partially uploaded.', 'rawatwp' );
		}

		if ( UPLOAD_ERR_NO_FILE === $error_code ) {
			return __( 'Upload failed: no file was received.', 'rawatwp' );
		}

		if ( UPLOAD_ERR_NO_TMP_DIR === $error_code ) {
			return __( 'Upload failed: temporary directory is missing on server.', 'rawatwp' );
		}

		if ( UPLOAD_ERR_CANT_WRITE === $error_code ) {
			return __( 'Upload failed: server cannot write uploaded file.', 'rawatwp' );
		}

		if ( UPLOAD_ERR_EXTENSION === $error_code ) {
			return __( 'Upload blocked by a server extension.', 'rawatwp' );
		}

		return __( 'File upload failed.', 'rawatwp' );
	}

	/**
	 * Detect and validate package metadata by extracting zip to temp folder.
	 *
	 * @param string $zip_path zip path.
	 * @return array|\WP_Error
	 */
	private function detect_package_meta_from_zip( $zip_path ) {
		$meta = $this->inspect_zip_by_extraction( $zip_path );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$type        = isset( $meta['type'] ) ? sanitize_key( $meta['type'] ) : '';
		$target_slug = isset( $meta['target_slug'] ) ? sanitize_title( $meta['target_slug'] ) : '';
		$label       = isset( $meta['label'] ) ? sanitize_text_field( $meta['label'] ) : '';

		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return new \WP_Error( 'rawatwp_unsupported_zip', __( 'Zip is not recognized as a plugin, theme, or WordPress core package.', 'rawatwp' ) );
		}

		if ( 'core' === $type && '' === $target_slug ) {
			$target_slug = 'wordpress-core';
		}

		if ( '' === $target_slug ) {
			return new \WP_Error( 'rawatwp_bad_slug', __( 'Target slug cannot be auto-detected.', 'rawatwp' ) );
		}

		if ( '' === $label ) {
			$label = sanitize_text_field( wp_basename( $zip_path, '.zip' ) );
		}

		$installable_check = $this->validate_installable_structure_from_zip( $zip_path, $type, $target_slug );
		if ( is_wp_error( $installable_check ) ) {
			return $installable_check;
		}

		return array(
			'type'        => $type,
			'target_slug' => $target_slug,
			'label'       => $label,
		);
	}

	/**
	 * Extract zip into temp folder and infer package metadata from extracted content.
	 *
	 * @param string $zip_path zip path.
	 * @return array|\WP_Error
	 */
	private function inspect_zip_by_extraction( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'rawatwp_zip_missing', __( 'ZipArchive is not available on this server.', 'rawatwp' ) );
		}

		$temp_dir = $this->create_temp_inspect_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$this->remove_directory_recursive( $temp_dir );
			return new \WP_Error( 'rawatwp_zip_open_failed', __( 'Zip cannot be opened.', 'rawatwp' ) );
		}

		try {
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( ! $stat || empty( $stat['name'] ) ) {
					return new \WP_Error( 'rawatwp_zip_invalid_entry', __( 'Invalid zip entry.', 'rawatwp' ) );
				}

				$raw_name       = (string) $stat['name'];
				$normalized_raw = str_replace( '\\', '/', $raw_name );
				$is_directory   = '/' === substr( $normalized_raw, -1 );
				$entry_name     = trim( $normalized_raw, '/' );

				if ( '' === $entry_name ) {
					continue;
				}

				if ( ! $this->is_safe_zip_entry( $entry_name ) ) {
					return new \WP_Error( 'rawatwp_zip_traversal', __( 'Zip contains unsafe path.', 'rawatwp' ) );
				}

				if ( isset( $stat['external_attributes'] ) ) {
					$mode = ( $stat['external_attributes'] >> 16 ) & 0170000;
					if ( 0120000 === $mode ) {
						return new \WP_Error( 'rawatwp_zip_symlink', __( 'Zip contains disallowed symlink.', 'rawatwp' ) );
					}
				}

				$destination = wp_normalize_path( $temp_dir . '/' . $entry_name );
				if ( ! $this->path_is_inside( $temp_dir, $destination ) ) {
					return new \WP_Error( 'rawatwp_zip_traversal', __( 'Zip contains unsafe path.', 'rawatwp' ) );
				}

				if ( $is_directory ) {
					if ( ! file_exists( $destination ) && ! wp_mkdir_p( $destination ) ) {
						return new \WP_Error( 'rawatwp_zip_extract_failed', __( 'Failed to create directory while extracting zip.', 'rawatwp' ) );
					}
					continue;
				}

				$parent = dirname( $destination );
				if ( ! file_exists( $parent ) && ! wp_mkdir_p( $parent ) ) {
					return new \WP_Error( 'rawatwp_zip_extract_failed', __( 'Failed to create extraction target directory.', 'rawatwp' ) );
				}

				$stream = $zip->getStream( $raw_name );
				if ( ! is_resource( $stream ) ) {
					return new \WP_Error( 'rawatwp_zip_extract_failed', __( 'Failed to read zip content.', 'rawatwp' ) );
				}

				$out = fopen( $destination, 'wb' );
				if ( false === $out ) {
					fclose( $stream );
					return new \WP_Error( 'rawatwp_zip_extract_failed', __( 'Failed to write extracted file.', 'rawatwp' ) );
				}

				$copied = stream_copy_to_stream( $stream, $out );
				fclose( $stream );
				fclose( $out );

				if ( false === $copied ) {
					return new \WP_Error( 'rawatwp_zip_extract_failed', __( 'Failed to copy zip content.', 'rawatwp' ) );
				}
			}

			return $this->infer_package_meta_from_extracted_dir( $temp_dir, $zip_path );
		} finally {
			$zip->close();
			$this->remove_directory_recursive( $temp_dir );
		}
	}

	/**
	 * Infer package metadata from extracted directory.
	 *
	 * @param string $extracted_dir Extracted directory.
	 * @param string $zip_path Source zip path.
	 * @return array
	 */
	private function infer_package_meta_from_extracted_dir( $extracted_dir, $zip_path ) {
		$filename_slug = sanitize_title( wp_basename( $zip_path, '.zip' ) );
		$label         = sanitize_text_field( str_replace( array( '-', '_' ), ' ', wp_basename( $zip_path, '.zip' ) ) );
		if ( '' === $label ) {
			$label = $filename_slug;
		}

		$core_root = $this->detect_core_root( $extracted_dir );
		if ( '' !== $core_root ) {
			return array(
				'type'        => 'core',
				'target_slug' => 'wordpress-core',
				'label'       => $label,
			);
		}

		$theme_slug = $this->detect_theme_slug( $extracted_dir );
		if ( '' !== $theme_slug ) {
			return array(
				'type'        => 'theme',
				'target_slug' => $theme_slug,
				'label'       => $label,
			);
		}

		$plugin_slug = $this->detect_plugin_slug( $extracted_dir );
		if ( '' !== $plugin_slug ) {
			return array(
				'type'        => 'plugin',
				'target_slug' => $plugin_slug,
				'label'       => $label,
			);
		}

		if ( preg_match( '/(^|[^a-z0-9])wordpress-[0-9]/i', wp_basename( $zip_path, '.zip' ) ) ) {
			return array(
				'type'        => 'core',
				'target_slug' => 'wordpress-core',
				'label'       => $label,
			);
		}

		return array(
			'type'        => '',
			'target_slug' => '',
			'label'       => $label,
		);
	}

	/**
	 * Detect WordPress core root directory.
	 *
	 * @param string $extracted_dir Extracted directory.
	 * @return string
	 */
	private function detect_core_root( $extracted_dir ) {
		$candidates = array_merge(
			array( wp_normalize_path( $extracted_dir ) ),
			$this->get_immediate_directories( $extracted_dir )
		);

		foreach ( $candidates as $candidate ) {
			if ( is_dir( $candidate . '/wp-admin' )
				&& is_dir( $candidate . '/wp-includes' )
				&& is_file( $candidate . '/wp-includes/version.php' )
				&& is_file( $candidate . '/wp-admin/includes/update-core.php' ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Detect theme slug from extracted content.
	 *
	 * @param string $extracted_dir Extracted directory.
	 * @return string
	 */
	private function detect_theme_slug( $extracted_dir ) {
		$directories = array_merge(
			$this->get_immediate_directories( $extracted_dir ),
			$this->get_immediate_directories( $extracted_dir . '/wp-content/themes' )
		);

		foreach ( $this->get_immediate_directories( $extracted_dir ) as $dir ) {
			$directories = array_merge( $directories, $this->get_immediate_directories( $dir . '/wp-content/themes' ) );
		}

		$directories = array_values( array_unique( array_map( 'wp_normalize_path', $directories ) ) );
		foreach ( $directories as $dir ) {
			$style = $dir . '/style.css';
			if ( $this->has_header_marker( $style, 'Theme Name:' ) ) {
				return sanitize_title( basename( $dir ) );
			}
		}

		return '';
	}

	/**
	 * Detect plugin slug from extracted content.
	 *
	 * @param string $extracted_dir Extracted directory.
	 * @return string
	 */
	private function detect_plugin_slug( $extracted_dir ) {
		$directories = $this->get_immediate_directories( $extracted_dir );
		$directories = array_merge( $directories, $this->get_immediate_directories( $extracted_dir . '/wp-content/plugins' ) );

		foreach ( $this->get_immediate_directories( $extracted_dir ) as $dir ) {
			$directories = array_merge( $directories, $this->get_immediate_directories( $dir . '/wp-content/plugins' ) );
		}

		$directories = array_values( array_unique( array_map( 'wp_normalize_path', $directories ) ) );
		foreach ( $directories as $dir ) {
			$php_files = glob( trailingslashit( $dir ) . '*.php' );
			if ( false === $php_files ) {
				continue;
			}

			foreach ( $php_files as $php_file ) {
				if ( $this->has_header_marker( $php_file, 'Plugin Name:' ) ) {
					return sanitize_title( basename( $dir ) );
				}
			}
		}

		return '';
	}

	/**
	 * Check whether file header contains marker.
	 *
	 * @param string $file_path File path.
	 * @param string $marker Header marker.
	 * @return bool
	 */
	private function has_header_marker( $file_path, $marker ) {
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$contents = file_get_contents( $file_path, false, null, 0, 8192 );
		if ( false === $contents ) {
			return false;
		}

		return false !== stripos( $contents, $marker );
	}

	/**
	 * Get immediate child directories from a base path.
	 *
	 * @param string $base_dir Base directory.
	 * @return array
	 */
	private function get_immediate_directories( $base_dir ) {
		$base_dir = wp_normalize_path( $base_dir );
		if ( ! is_dir( $base_dir ) ) {
			return array();
		}

		$dirs = glob( trailingslashit( $base_dir ) . '*', GLOB_ONLYDIR );
		if ( false === $dirs ) {
			return array();
		}

		return array_map( 'wp_normalize_path', $dirs );
	}

	/**
	 * Create temporary directory for zip inspection.
	 *
	 * @return string|\WP_Error
	 */
	private function create_temp_inspect_dir() {
		$upload_dir = wp_upload_dir();
		$temp_base  = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'rawatwp/temp' );

		if ( ! file_exists( $temp_base ) && ! wp_mkdir_p( $temp_base ) ) {
			return new \WP_Error( 'rawatwp_temp_dir_missing', __( 'Temp directory cannot be created.', 'rawatwp' ) );
		}

		$temp_dir = wp_normalize_path( trailingslashit( $temp_base ) . 'inspect-' . wp_generate_uuid4() );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new \WP_Error( 'rawatwp_temp_dir_missing', __( 'Temp work directory cannot be created.', 'rawatwp' ) );
		}

		return $temp_dir;
	}

	/**
	 * Check whether zip entry path is safe.
	 *
	 * @param string $entry_name zip entry path.
	 * @return bool
	 */
	private function is_safe_zip_entry( $entry_name ) {
		$entry_name = str_replace( '\\', '/', (string) $entry_name );

		if ( '' === $entry_name ) {
			return false;
		}

		if ( false !== strpos( $entry_name, "\0" ) ) {
			return false;
		}

		if ( preg_match( '#(^|/)\.\.(/|$)#', $entry_name ) ) {
			return false;
		}

		if ( preg_match( '#^([a-zA-Z]:|/)#', $entry_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if target path is inside base path.
	 *
	 * @param string $base_dir Base directory.
	 * @param string $target_path Target path.
	 * @return bool
	 */
	private function path_is_inside( $base_dir, $target_path ) {
		$base_dir    = trailingslashit( wp_normalize_path( $base_dir ) );
		$target_path = wp_normalize_path( $target_path );

		return 0 === strpos( $target_path, $base_dir );
	}

	/**
	 * Remove directory recursively.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function remove_directory_recursive( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );
			return;
		}

		$items = scandir( $path );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$this->remove_directory_recursive( $path . '/' . $item );
			}
		}

		@rmdir( $path );
	}

	/**
	 * Validate installable structure using zip entries as second check.
	 *
	 * @param string $zip_path zip path.
	 * @param string $type Package type.
	 * @param string $target_slug Target slug.
	 * @return true|\WP_Error
	 */
	private function validate_installable_structure_from_zip( $zip_path, $type, $target_slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'rawatwp_zip_missing', __( 'ZipArchive is not available on this server.', 'rawatwp' ) );
		}

		$type        = sanitize_key( $type );
		$target_slug = sanitize_title( $target_slug );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'rawatwp_zip_open_failed', __( 'Zip cannot be opened.', 'rawatwp' ) );
		}

		$has_expected_entry = false;
		$core_markers       = array(
			'wp-admin/',
			'wp-includes/',
			'wp-includes/version.php',
		);
		$core_hits          = 0;

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name = str_replace( '\\', '/', (string) $stat['name'] );

			if ( 'plugin' === $type || 'theme' === $type ) {
				$prefixes = array(
					$target_slug . '/',
					'wordpress/' . $target_slug . '/',
					'wp-content/plugins/' . $target_slug . '/',
					'wp-content/themes/' . $target_slug . '/',
					'wordpress/wp-content/plugins/' . $target_slug . '/',
					'wordpress/wp-content/themes/' . $target_slug . '/',
				);
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $name, $prefix ) ) {
						$has_expected_entry = true;
						break 2;
					}
				}
			}

			if ( 'core' === $type ) {
				foreach ( $core_markers as $marker ) {
					if ( false !== strpos( $name, $marker ) ) {
						$core_hits++;
						break;
					}
				}
			}
		}

		$zip->close();

		if ( 'core' === $type && $core_hits >= 2 ) {
			return true;
		}

		if ( 'plugin' === $type || 'theme' === $type ) {
			if ( ! $has_expected_entry ) {
				return new \WP_Error(
					'rawatwp_zip_not_installable',
					__( 'Zip is not suitable for WordPress install/update. Use an official installable zip for plugin/theme/core.', 'rawatwp' )
				);
			}
			return true;
		}

		if ( 'core' === $type ) {
			return new \WP_Error(
				'rawatwp_zip_not_installable',
				__( 'WordPress core zip is invalid for updater installer.', 'rawatwp' )
			);
		}

		return new \WP_Error( 'rawatwp_zip_not_installable', __( 'Zip is not installable by WordPress.', 'rawatwp' ) );
	}
}
