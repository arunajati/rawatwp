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

		$source_file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$target_temp_path = trailingslashit( $packages_dir ) . 'incoming-' . wp_generate_uuid4() . '.zip.part';

		if ( ! @move_uploaded_file( $file['tmp_name'], $target_temp_path ) ) {
			if ( ! @copy( $file['tmp_name'], $target_temp_path ) ) {
				return new \WP_Error( 'rawatwp_move_failed', __( 'Failed to save uploaded file.', 'rawatwp' ) );
			}
		}

		$prepared = $this->prepare_zip_for_registration( $target_temp_path, $source_file_name, true );
		if ( is_wp_error( $prepared ) ) {
			@unlink( $target_temp_path );
			return $prepared;
		}

		$working_zip_path   = isset( $prepared['zip_path'] ) ? wp_normalize_path( (string) $prepared['zip_path'] ) : $target_temp_path;
		$detected           = isset( $prepared['meta'] ) && is_array( $prepared['meta'] ) ? $prepared['meta'] : array();
		$from_bundle        = ! empty( $prepared['from_bundle'] );
		$resolved_file_name = isset( $prepared['source_name'] ) ? (string) $prepared['source_name'] : $source_file_name;

		$type        = $detected['type'];
		$target_slug = $detected['target_slug'];
		$label       = $detected['label'];
		$source_type = $this->resolve_source_type( $detected, $from_bundle );
		$source_name = sanitize_file_name( wp_basename( (string) $source_file_name ) );

		$hash       = hash_file( 'sha256', $working_zip_path );
		if ( ! is_string( $hash ) || '' === $hash ) {
			if ( $working_zip_path !== $target_temp_path ) {
				@unlink( $working_zip_path );
			}
			@unlink( $target_temp_path );
			return new \WP_Error( 'rawatwp_hash_failed', __( 'Failed to calculate package hash.', 'rawatwp' ) );
		}

		$existing   = $this->database->get_package_by_hash( $hash );
		if ( $existing ) {
			if ( $working_zip_path !== $target_temp_path ) {
				@unlink( $working_zip_path );
			}
			@unlink( $target_temp_path );
			return new \WP_Error( 'rawatwp_package_duplicate', __( 'This zip file is already registered.', 'rawatwp' ) );
		}

		$target_file_name = sprintf(
			'%s-%s-%s.zip',
			gmdate( 'Ymd-His' ),
			sanitize_file_name( wp_basename( $resolved_file_name, '.zip' ) ),
			wp_generate_password( 6, false, false )
		);
		$target_path      = trailingslashit( $packages_dir ) . $target_file_name;

		if ( ! @rename( $working_zip_path, $target_path ) ) {
			if ( ! @copy( $working_zip_path, $target_path ) ) {
				if ( $working_zip_path !== $target_temp_path ) {
					@unlink( $working_zip_path );
				}
				@unlink( $target_temp_path );
				return new \WP_Error( 'rawatwp_move_failed', __( 'Failed to finalize uploaded file.', 'rawatwp' ) );
			}
			if ( $working_zip_path !== $target_temp_path ) {
				@unlink( $working_zip_path );
			}
		}

		if ( $working_zip_path !== $target_temp_path ) {
			@unlink( $target_temp_path );
		}

		$package_id = $this->database->insert_package(
			array(
				'label'       => $label,
				'type'        => $type,
				'target_slug' => $target_slug,
				'source_type' => $source_type,
				'source_name' => $source_name,
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
					'from_bundle' => $from_bundle,
					'source_type' => $source_type,
					'source_name' => $source_name,
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

			$prepared = $this->prepare_zip_for_registration( $file_path, basename( $file_path ), true );
			if ( is_wp_error( $prepared ) ) {
				$error_code = $prepared->get_error_code();
				if ( in_array( $error_code, array( 'rawatwp_unsupported_zip', 'rawatwp_bad_slug' ), true ) ) {
					$result['skipped']++;
					$result['details'][] = array(
						'file'   => basename( $file_path ),
						'status' => 'skipped',
						'reason' => $prepared->get_error_message(),
					);
				} else {
					$result['failed']++;
					$result['details'][] = array(
						'file'   => basename( $file_path ),
						'status' => 'failed',
						'reason' => $prepared->get_error_message(),
					);
				}
				continue;
			}

			$detected         = isset( $prepared['meta'] ) && is_array( $prepared['meta'] ) ? $prepared['meta'] : array();
			$working_zip_path = isset( $prepared['zip_path'] ) ? wp_normalize_path( (string) $prepared['zip_path'] ) : $file_path;
			$from_bundle      = ! empty( $prepared['from_bundle'] );
			$source_name      = isset( $prepared['source_name'] ) ? (string) $prepared['source_name'] : basename( $file_path );
			$source_type      = $this->resolve_source_type( $detected, $from_bundle );
			$source_file_name = sanitize_file_name( basename( $file_path ) );

			$file_hash = hash_file( 'sha256', $working_zip_path );
			if ( ! is_string( $file_hash ) || '' === $file_hash ) {
				if ( $working_zip_path !== $file_path ) {
					@unlink( $working_zip_path );
				}
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
				if ( $working_zip_path !== $file_path ) {
					@unlink( $working_zip_path );
				}
				$result['skipped']++;
				$result['details'][] = array(
					'file'   => basename( $file_path ),
					'status' => 'skipped',
					'reason' => 'Already registered.',
				);
				continue;
			}

			$store_path = $file_path;
			$store_name = basename( $file_path );
			if ( $working_zip_path !== $file_path ) {
				$store_name = $this->build_scan_import_file_name( $source_name, $file_hash );
				$store_path = trailingslashit( $packages_dir ) . $store_name;

				if ( ! @rename( $working_zip_path, $store_path ) ) {
					if ( ! @copy( $working_zip_path, $store_path ) ) {
						@unlink( $working_zip_path );
						$result['failed']++;
						$result['details'][] = array(
							'file'   => basename( $file_path ),
							'status' => 'failed',
							'reason' => 'Failed to save installable package extracted from bundle.',
						);
						continue;
					}
					@unlink( $working_zip_path );
				}
			}

			$inserted_id = $this->database->insert_package(
				array(
					'label'       => $detected['label'],
					'type'        => $detected['type'],
					'target_slug' => $detected['target_slug'],
					'source_type' => $source_type,
					'source_name' => $source_file_name,
					'file_name'   => $store_name,
					'file_path'   => $store_path,
					'file_hash'   => $file_hash,
				)
			);

			if ( false === $inserted_id ) {
				if ( $working_zip_path !== $file_path ) {
					@unlink( $store_path );
				}
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
				'from_bundle' => $from_bundle,
				'source_type' => $source_type,
				'source_name' => $source_file_name,
			);

			$this->logger->log(
				array(
					'mode'      => 'master',
					'action'    => 'package_scanned',
					'status'    => 'success',
					'item_type' => $detected['type'],
					'item_slug' => $detected['target_slug'],
					'message'   => sprintf( 'Package imported from updates folder: %s', $store_name ),
					'context'   => array(
						'file_path'   => $store_path,
						'from_bundle' => $from_bundle,
						'source_type' => $source_type,
						'source_name' => $source_file_name,
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
	 * Ensure current Master RawatWP plugin is available as package.
	 *
	 * @return array|\WP_Error
	 */
	public function ensure_rawatwp_self_package() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'rawatwp_zip_missing', __( 'ZipArchive is not available on this server.', 'rawatwp' ) );
		}

		$plugin_root = wp_normalize_path( untrailingslashit( (string) RAWATWP_DIR ) );
		if ( '' === $plugin_root || ! is_dir( $plugin_root ) ) {
			return new \WP_Error( 'rawatwp_self_package_root_missing', __( 'RawatWP plugin folder is not available for packaging.', 'rawatwp' ) );
		}

		$temp_zip = wp_tempnam( 'rawatwp-self-package.zip' );
		if ( ! is_string( $temp_zip ) || '' === $temp_zip ) {
			return new \WP_Error( 'rawatwp_self_package_temp_failed', __( 'Failed to create temporary zip for RawatWP package.', 'rawatwp' ) );
		}
		$temp_zip = wp_normalize_path( $temp_zip );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $temp_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			@unlink( $temp_zip );
			return new \WP_Error( 'rawatwp_self_package_zip_failed', __( 'Failed to create RawatWP package zip.', 'rawatwp' ) );
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $plugin_root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $entry ) {
				if ( ! $entry instanceof \SplFileInfo ) {
					continue;
				}

				$absolute_path = wp_normalize_path( $entry->getPathname() );
				$relative_path = ltrim( str_replace( $plugin_root, '', $absolute_path ), '/' );
				if ( '' === $relative_path ) {
					continue;
				}

				if ( $this->should_skip_rawatwp_self_package_path( $relative_path ) ) {
					continue;
				}

				$zip_path = 'rawatwp/' . $relative_path;
				if ( $entry->isDir() ) {
					$zip->addEmptyDir( $zip_path );
				} elseif ( $entry->isFile() ) {
					$zip->addFile( $absolute_path, $zip_path );
				}
			}
		} finally {
			$zip->close();
		}

		$file_hash = hash_file( 'sha256', $temp_zip );
		if ( ! is_string( $file_hash ) || '' === $file_hash ) {
			@unlink( $temp_zip );
			return new \WP_Error( 'rawatwp_self_package_hash_failed', __( 'Failed to hash RawatWP package zip.', 'rawatwp' ) );
		}

		$existing = $this->database->get_package_by_hash( $file_hash );
		if ( is_array( $existing ) ) {
			@unlink( $temp_zip );
			return $existing;
		}

		$file = array(
			'name'     => 'rawatwp-' . ( defined( 'RAWATWP_VERSION' ) ? sanitize_text_field( (string) RAWATWP_VERSION ) : gmdate( 'YmdHis' ) ) . '.zip',
			'type'     => 'application/zip',
			'tmp_name' => $temp_zip,
			'error'    => 0,
			'size'     => (int) @filesize( $temp_zip ),
		);

		$uploaded = $this->upload_package( $file );
		@unlink( $temp_zip );

		if ( is_wp_error( $uploaded ) ) {
			if ( 'rawatwp_package_duplicate' === $uploaded->get_error_code() ) {
				$duplicate = $this->database->get_package_by_hash( $file_hash );
				if ( is_array( $duplicate ) ) {
					return $duplicate;
				}
			}

			return $uploaded;
		}

		if ( ! is_array( $uploaded ) || ! isset( $uploaded['type'], $uploaded['target_slug'] ) || 'plugin' !== (string) $uploaded['type'] || 'rawatwp' !== sanitize_key( (string) $uploaded['target_slug'] ) ) {
			return new \WP_Error( 'rawatwp_self_package_invalid', __( 'Generated RawatWP package is invalid.', 'rawatwp' ) );
		}

		return $uploaded;
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
	 * Skip non-runtime files when creating self package.
	 *
	 * @param string $relative_path Relative path from plugin root.
	 * @return bool
	 */
	private function should_skip_rawatwp_self_package_path( $relative_path ) {
		$relative_path = ltrim( wp_normalize_path( (string) $relative_path ), '/' );
		if ( '' === $relative_path ) {
			return true;
		}

		$segments = explode( '/', $relative_path );
		$first    = isset( $segments[0] ) ? (string) $segments[0] : '';

		if ( in_array( $first, array( '.git', '.github', '.vscode', 'node_modules' ), true ) ) {
			return true;
		}

		if ( preg_match( '/^rawatwp-[0-9][A-Za-z0-9.\-]*\.zip$/i', $first ) ) {
			return true;
		}

		foreach ( $segments as $segment ) {
			if ( '.DS_Store' === $segment ) {
				return true;
			}
		}

		return false;
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
	 * Prepare uploaded/scanned zip into an installable package zip + metadata.
	 *
	 * @param string $zip_path Zip path.
	 * @param string $source_name Source file name.
	 * @param bool   $allow_nested Whether nested zip fallback is allowed.
	 * @return array|\WP_Error
	 */
	private function prepare_zip_for_registration( $zip_path, $source_name, $allow_nested = true ) {
		$zip_path    = wp_normalize_path( (string) $zip_path );
		$source_name = (string) $source_name;
		$direct      = $this->detect_package_meta_from_zip( $zip_path, $source_name );
		if ( ! is_wp_error( $direct ) ) {
			return array(
				'zip_path'    => $zip_path,
				'meta'        => $direct,
				'source_name' => $source_name,
				'from_bundle' => false,
			);
		}

		if ( ! $allow_nested || ! $this->is_nested_detection_fallback_allowed( $direct->get_error_code() ) ) {
			return $direct;
		}

		$nested = $this->extract_installable_nested_zip_from_bundle( $zip_path, $source_name );
		if ( is_wp_error( $nested ) ) {
			return $nested;
		}

		return array(
			'zip_path'    => $nested['zip_path'],
			'meta'        => $nested['meta'],
			'source_name' => $nested['source_name'],
			'from_bundle' => true,
		);
	}

	/**
	 * Determine whether error code can fallback to nested zip detection.
	 *
	 * @param string $error_code Error code.
	 * @return bool
	 */
	private function is_nested_detection_fallback_allowed( $error_code ) {
		return in_array(
			sanitize_key( (string) $error_code ),
			array(
				'rawatwp_unsupported_zip',
				'rawatwp_bad_slug',
				'rawatwp_zip_not_installable',
			),
			true
		);
	}

	/**
	 * Extract one installable nested zip from a bundle package.
	 *
	 * @param string $outer_zip_path Bundle zip path.
	 * @param string $source_name Original source file name.
	 * @return array|\WP_Error
	 */
	private function extract_installable_nested_zip_from_bundle( $outer_zip_path, $source_name ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'rawatwp_zip_missing', __( 'ZipArchive is not available on this server.', 'rawatwp' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $outer_zip_path ) ) {
			return new \WP_Error( 'rawatwp_zip_open_failed', __( 'Zip cannot be opened.', 'rawatwp' ) );
		}

		$candidates = array();
		$temp_files = array();

		try {
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( ! $stat || empty( $stat['name'] ) ) {
					continue;
				}

				$entry_name = str_replace( '\\', '/', (string) $stat['name'] );
				if ( '/' === substr( $entry_name, -1 ) ) {
					continue;
				}

				if ( '.zip' !== strtolower( pathinfo( $entry_name, PATHINFO_EXTENSION ) ) ) {
					continue;
				}

				if ( ! $this->is_safe_zip_entry( trim( $entry_name, '/' ) ) ) {
					continue;
				}

				if ( 0 === strpos( strtolower( $entry_name ), '__macosx/' ) ) {
					continue;
				}

				$temp_zip = $this->extract_zip_entry_to_temp_file( $zip, $entry_name );
				if ( is_wp_error( $temp_zip ) ) {
					continue;
				}

				$temp_files[] = $temp_zip;
				$meta         = $this->detect_package_meta_from_zip( $temp_zip, basename( $entry_name ) );
				if ( is_wp_error( $meta ) ) {
					continue;
				}

				$candidates[] = array(
					'zip_path'    => $temp_zip,
					'meta'        => $meta,
					'source_name' => basename( $entry_name ),
					'score'       => $this->score_nested_candidate( basename( $source_name ), basename( $entry_name ), $meta ),
				);
			}
		} finally {
			$zip->close();
		}

		if ( empty( $candidates ) ) {
			foreach ( $temp_files as $temp_file ) {
				@unlink( $temp_file );
			}
			return new \WP_Error(
				'rawatwp_nested_installable_not_found',
				__( 'No installable plugin/theme/core zip was found inside this file. If this is a marketplace bundle, upload the installable zip file only.', 'rawatwp' )
			);
		}

		usort(
			$candidates,
			static function( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		$chosen = $candidates[0];
		if ( count( $candidates ) > 1 ) {
			$top_score    = (int) $candidates[0]['score'];
			$second_score = (int) $candidates[1]['score'];
			if ( $top_score === $second_score ) {
				foreach ( $temp_files as $temp_file ) {
					@unlink( $temp_file );
				}

				return new \WP_Error(
					'rawatwp_nested_multiple_installable',
					__( 'This zip contains multiple installable packages. Upload the specific installable zip file you want to deploy.', 'rawatwp' )
				);
			}
		}

		foreach ( $temp_files as $temp_file ) {
			if ( $temp_file !== $chosen['zip_path'] ) {
				@unlink( $temp_file );
			}
		}

		return $chosen;
	}

	/**
	 * Score nested zip candidate selection.
	 *
	 * @param string $outer_name Outer zip name.
	 * @param string $entry_name Nested entry name.
	 * @param array  $meta Detected metadata.
	 * @return int
	 */
	private function score_nested_candidate( $outer_name, $entry_name, array $meta ) {
		$outer_slug  = sanitize_key( wp_basename( (string) $outer_name, '.zip' ) );
		$entry_slug  = sanitize_key( wp_basename( (string) $entry_name, '.zip' ) );
		$target_slug = isset( $meta['target_slug'] ) ? sanitize_key( $meta['target_slug'] ) : '';
		$type        = isset( $meta['type'] ) ? sanitize_key( $meta['type'] ) : '';
		$score       = 0;

		if ( '' !== $target_slug && false !== strpos( $outer_slug, $target_slug ) ) {
			$score += 50;
		}

		if ( '' !== $target_slug && false !== strpos( $entry_slug, $target_slug ) ) {
			$score += 45;
		}

		if ( 'theme' === $type ) {
			$score += 10;
		}

		if ( 'plugin' === $type ) {
			$score += 5;
		}

		if ( 'avada' === $target_slug ) {
			$score += 20;
		}

		if ( 'avada' === $entry_slug ) {
			$score += 25;
		}

		return $score;
	}

	/**
	 * Extract zip entry stream to a temp file.
	 *
	 * @param \ZipArchive $zip Zip archive.
	 * @param string      $entry_name Entry name.
	 * @return string|\WP_Error
	 */
	private function extract_zip_entry_to_temp_file( \ZipArchive $zip, $entry_name ) {
		$stream = $zip->getStream( $entry_name );
		if ( ! is_resource( $stream ) ) {
			return new \WP_Error( 'rawatwp_nested_extract_failed', __( 'Failed to read nested zip from bundle.', 'rawatwp' ) );
		}

		$temp_zip = wp_tempnam( 'rawatwp-nested-package.zip' );
		if ( ! is_string( $temp_zip ) || '' === $temp_zip ) {
			fclose( $stream );
			return new \WP_Error( 'rawatwp_temp_file_failed', __( 'Failed to create temp file for nested package.', 'rawatwp' ) );
		}

		$out = fopen( $temp_zip, 'wb' );
		if ( false === $out ) {
			fclose( $stream );
			@unlink( $temp_zip );
			return new \WP_Error( 'rawatwp_temp_file_failed', __( 'Failed to write nested package temp file.', 'rawatwp' ) );
		}

		$copied = stream_copy_to_stream( $stream, $out );
		fclose( $stream );
		fclose( $out );

		if ( false === $copied ) {
			@unlink( $temp_zip );
			return new \WP_Error( 'rawatwp_nested_extract_failed', __( 'Failed to extract nested package zip.', 'rawatwp' ) );
		}

		return wp_normalize_path( $temp_zip );
	}

	/**
	 * Build file name for zip imported from folder scan.
	 *
	 * @param string $source_name Source file name.
	 * @param string $hash File hash.
	 * @return string
	 */
	private function build_scan_import_file_name( $source_name, $hash ) {
		$source_name = sanitize_file_name( wp_basename( (string) $source_name, '.zip' ) );
		$hash_short  = substr( sanitize_key( (string) $hash ), 0, 12 );
		if ( '' === $source_name ) {
			$source_name = 'package';
		}
		if ( '' === $hash_short ) {
			$hash_short = wp_generate_password( 8, false, false );
		}

		return sprintf( 'detected-%s-%s.zip', $hash_short, $source_name );
	}

	/**
	 * Detect and validate package metadata by extracting zip to temp folder.
	 *
	 * @param string $zip_path zip path.
	 * @param string $source_name Optional original source file name for better label generation.
	 * @return array|\WP_Error
	 */
	private function detect_package_meta_from_zip( $zip_path, $source_name = '' ) {
		$meta = $this->inspect_zip_by_extraction( $zip_path, $source_name );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$type        = isset( $meta['type'] ) ? sanitize_key( $meta['type'] ) : '';
		$target_slug = isset( $meta['target_slug'] ) ? sanitize_key( $meta['target_slug'] ) : '';
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
			$fallback_source = '' !== $source_name ? $source_name : wp_basename( $zip_path );
			$label           = $this->build_human_label_from_source( $fallback_source );
		}

		$installable_check = $this->validate_installable_structure_from_zip( $zip_path, $type, $target_slug );
		if ( is_wp_error( $installable_check ) ) {
			return $installable_check;
		}

		return array(
			'type'        => $type,
			'target_slug' => $target_slug,
			'label'       => $label,
			'source_type' => isset( $meta['source_type'] ) ? sanitize_key( $meta['source_type'] ) : 'direct',
		);
	}

	/**
	 * Extract zip into temp folder and infer package metadata from extracted content.
	 *
	 * @param string $zip_path zip path.
	 * @param string $source_name Optional source file name for label generation.
	 * @return array|\WP_Error
	 */
	private function inspect_zip_by_extraction( $zip_path, $source_name = '' ) {
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

			return $this->infer_package_meta_from_extracted_dir( $temp_dir, $zip_path, $source_name );
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
	 * @param string $source_name Optional source file name.
	 * @return array
	 */
	private function infer_package_meta_from_extracted_dir( $extracted_dir, $zip_path, $source_name = '' ) {
		$filename_slug = sanitize_title( wp_basename( $zip_path, '.zip' ) );
		$label         = $this->build_human_label_from_source( '' !== $source_name ? $source_name : wp_basename( $zip_path ) );
		if ( '' === $label ) {
			$label = $filename_slug;
		}

		$core_root = $this->detect_core_root( $extracted_dir );
		if ( '' !== $core_root ) {
			return array(
				'type'        => 'core',
				'target_slug' => 'wordpress-core',
				'label'       => $label,
				'source_type' => 'direct',
			);
		}

		// If payload is stored under wp-content paths, treat it as patch-source
		// so child updater will run controlled fallback replace on target slug.
		$theme_patch_slug = $this->detect_content_path_slug( $extracted_dir, 'theme' );
		if ( '' !== $theme_patch_slug ) {
			return array(
				'type'        => 'theme',
				'target_slug' => $theme_patch_slug,
				'label'       => $label,
				'source_type' => 'patch_source',
			);
		}

		$plugin_patch_slug = $this->detect_content_path_slug( $extracted_dir, 'plugin' );
		if ( '' !== $plugin_patch_slug ) {
			return array(
				'type'        => 'plugin',
				'target_slug' => $plugin_patch_slug,
				'label'       => $label,
				'source_type' => 'patch_source',
			);
		}

		$theme_slug = $this->detect_theme_slug( $extracted_dir );
		if ( '' !== $theme_slug ) {
			return array(
				'type'        => 'theme',
				'target_slug' => $theme_slug,
				'label'       => $label,
				'source_type' => 'direct',
			);
		}

		$plugin_slug = $this->detect_plugin_slug( $extracted_dir );
		if ( '' !== $plugin_slug ) {
			return array(
				'type'        => 'plugin',
				'target_slug' => $plugin_slug,
				'label'       => $label,
				'source_type' => 'direct',
			);
		}

		if ( preg_match( '/(^|[^a-z0-9])wordpress-[0-9]/i', wp_basename( $zip_path, '.zip' ) ) ) {
			return array(
				'type'        => 'core',
				'target_slug' => 'wordpress-core',
				'label'       => $label,
				'source_type' => 'direct',
			);
		}

		return array(
			'type'        => '',
			'target_slug' => '',
			'label'       => $label,
			'source_type' => 'direct',
		);
	}

	/**
	 * Resolve package source type for storage.
	 *
	 * @param array $detected Detected package meta.
	 * @param bool  $from_bundle Whether package comes from nested bundle.
	 * @return string
	 */
	private function resolve_source_type( array $detected, $from_bundle ) {
		if ( $from_bundle ) {
			return 'patch_bundle';
		}

		$source_type = isset( $detected['source_type'] ) ? sanitize_key( (string) $detected['source_type'] ) : 'direct';
		if ( ! in_array( $source_type, array( 'direct', 'patch_source', 'patch_bundle' ), true ) ) {
			return 'direct';
		}

		return $source_type;
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
	 * Detect target slug from wp-content based patch structure.
	 *
	 * @param string $extracted_dir Extracted directory.
	 * @param string $type Target type (plugin/theme).
	 * @return string
	 */
	private function detect_content_path_slug( $extracted_dir, $type ) {
		$type        = sanitize_key( $type );
		$content_dir = 'theme' === $type ? 'wp-content/themes' : 'wp-content/plugins';
		$base_dirs   = array_merge(
			array( wp_normalize_path( $extracted_dir ) ),
			$this->get_immediate_directories( $extracted_dir )
		);
		$found_slugs = array();

		foreach ( $base_dirs as $base_dir ) {
			$scan_dir = wp_normalize_path( trailingslashit( $base_dir ) . $content_dir );
			if ( ! is_dir( $scan_dir ) ) {
				continue;
			}

			$children = $this->get_immediate_directories( $scan_dir );
			foreach ( $children as $child_dir ) {
				$slug = sanitize_key( basename( $child_dir ) );
				if ( '' !== $slug ) {
					$found_slugs[ $slug ] = true;
				}
			}
		}

		if ( 1 === count( $found_slugs ) ) {
			$keys = array_keys( $found_slugs );
			return (string) $keys[0];
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
				return sanitize_key( basename( $dir ) );
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
					return sanitize_key( basename( $dir ) );
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
		$target_slug = sanitize_key( $target_slug );

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
		$slug_pattern       = preg_quote( $target_slug, '#' );

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name       = str_replace( '\\', '/', (string) $stat['name'] );
			$name_lower = strtolower( $name );

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
					$prefix = strtolower( $prefix );
					if ( 0 === strpos( $name_lower, $prefix ) ) {
						$has_expected_entry = true;
						break 2;
					}
				}

				if ( 'theme' === $type && preg_match( '#(^|/)wp-content/themes/' . $slug_pattern . '/#i', $name ) ) {
					$has_expected_entry = true;
					break;
				}

				if ( 'plugin' === $type && preg_match( '#(^|/)wp-content/plugins/' . $slug_pattern . '/#i', $name ) ) {
					$has_expected_entry = true;
					break;
				}

				if ( 'theme' === $type && preg_match( '#(^|/)' . $slug_pattern . '/style\.css$#i', $name ) ) {
					$has_expected_entry = true;
					break;
				}

				if ( 'plugin' === $type && preg_match( '#(^|/)' . $slug_pattern . '/[^/]+\.php$#i', $name ) ) {
					$has_expected_entry = true;
					break;
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

	/**
	 * Build a readable package label from source zip filename.
	 *
	 * @param string $source_name Source file name.
	 * @return string
	 */
	private function build_human_label_from_source( $source_name ) {
		$source_name = wp_basename( wp_normalize_path( (string) $source_name ) );
		$source_name = preg_replace( '/\.part$/i', '', $source_name );
		$source_name = preg_replace( '/\.zip$/i', '', $source_name );
		$source_name = preg_replace( '/^\d{8}-\d{6}-/', '', $source_name );
		$source_name = preg_replace( '/-[A-Za-z0-9]{6}$/', '', $source_name );
		$source_name = str_replace( array( '-', '_' ), ' ', $source_name );
		$source_name = preg_replace( '/\s+/', ' ', (string) $source_name );
		$source_name = trim( (string) $source_name );

		if ( '' === $source_name ) {
			return '';
		}

		return sanitize_text_field( ucwords( $source_name ) );
	}
}
