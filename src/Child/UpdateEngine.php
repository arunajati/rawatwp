<?php
namespace RawatWP\Child;

use RawatWP\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateEngine {
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Backup manager.
	 *
	 * @var BackupManager
	 */
	private $backup_manager;

	/**
	 * Rollback manager.
	 *
	 * @var RollbackManager
	 */
	private $rollback_manager;

	/**
	 * Health checker.
	 *
	 * @var HealthChecker
	 */
	private $health_checker;

	/**
	 * Constructor.
	 *
	 * @param Logger          $logger Logger.
	 * @param BackupManager   $backup_manager Backup manager.
	 * @param RollbackManager $rollback_manager Rollback manager.
	 * @param HealthChecker   $health_checker Health checker.
	 */
	public function __construct( Logger $logger, BackupManager $backup_manager, RollbackManager $rollback_manager, HealthChecker $health_checker ) {
		$this->logger           = $logger;
		$this->backup_manager   = $backup_manager;
		$this->rollback_manager = $rollback_manager;
		$this->health_checker   = $health_checker;
	}

	/**
	 * Execute update job on child site.
	 *
	 * @param array $payload Job payload.
	 * @param array $item Monitored target item.
	 * @return array
	 */
	public function apply_update( array $payload, array $item ) {
		$download_url = isset( $payload['download_url'] ) ? esc_url_raw( $payload['download_url'] ) : '';
		if ( '' === $download_url ) {
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => 'URL download package kosong.',
				)
			);

			return array(
				'status'  => 'update_failed',
				'message' => 'URL download package kosong.',
				'events'  => array(),
			);
		}

		if ( 'core' === sanitize_key( $item['type'] ) ) {
			return $this->apply_core_update( $item, $download_url );
		}

		$target_path = $this->resolve_target_path( $item );
		if ( is_wp_error( $target_path ) ) {
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $target_path->get_error_message(),
				)
			);

			return array(
				'status'  => 'update_failed',
				'message' => $target_path->get_error_message(),
				'events'  => array(),
			);
		}

		$events = array();
		$events[] = $this->build_event( $item, 'update_started', 'update_started', 'Update dimulai.' );

		$this->logger->log(
			array(
				'mode'      => 'child',
				'item_type' => $item['type'],
				'item_slug' => $item['slug'],
				'action'    => 'update_started',
				'status'    => 'update_started',
				'message'   => 'Update dimulai.',
			)
		);

		$tmp_zip = $this->download_package( $download_url );
		if ( is_wp_error( $tmp_zip ) ) {
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $tmp_zip->get_error_message(),
				)
			);

			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $tmp_zip->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $tmp_zip->get_error_message(),
				'events'  => $events,
			);
		}

		$validation = $this->validate_zip( $tmp_zip );
		if ( is_wp_error( $validation ) ) {
			@unlink( $tmp_zip );
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $validation->get_error_message(),
				)
			);

			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $validation->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $validation->get_error_message(),
				'events'  => $events,
			);
		}

		$backup_path = $this->backup_manager->create_backup( $target_path, $item['slug'] );
		if ( is_wp_error( $backup_path ) ) {
			@unlink( $tmp_zip );
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $backup_path->get_error_message(),
				)
			);

			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $backup_path->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $backup_path->get_error_message(),
				'events'  => $events,
			);
		}

		$wp_native_result = new \WP_Error( 'rawatwp_native_skipped', 'WP-native dilewati, fallback dijalankan.' );
		if ( in_array( $item['type'], array( 'plugin', 'theme' ), true ) ) {
			if ( $this->can_use_wp_native( $tmp_zip, $item ) ) {
				try {
					$wp_native_result = $this->apply_wp_native( $item['type'], $tmp_zip );
				} catch ( \Throwable $throwable ) {
					$wp_native_result = new \WP_Error(
						'rawatwp_wp_native_exception',
						sprintf( 'WP-native exception: %s', $throwable->getMessage() )
					);
				}
			} else {
				$wp_native_result = new \WP_Error(
					'rawatwp_native_mismatch',
					'Struktur ZIP tidak cocok untuk update native, fallback dijalankan agar update tetap ke slug target.'
				);
			}
		}

		$update_success = true;
		$fallback_used  = false;
		$failure_reason = '';

		if ( is_wp_error( $wp_native_result ) ) {
			$fallback_result = $this->apply_fallback_replace( $tmp_zip, $target_path, $item['slug'], $item['type'] );
			$fallback_used   = true;

			if ( is_wp_error( $fallback_result ) ) {
				$update_success = false;
				$failure_reason = $fallback_result->get_error_message();
			}
		}

		if ( $update_success ) {
			$health = $this->health_checker->run( $target_path );
			if ( empty( $health['ok'] ) ) {
				$update_success = false;
				$failure_reason = isset( $health['message'] ) ? $health['message'] : 'Health check gagal.';
			}
		}

		@unlink( $tmp_zip );

		if ( ! $update_success ) {
			$events[] = $this->build_event( $item, 'rollback_started', 'rollback_started', 'Rollback dimulai.' );
			$rollback = $this->rollback_manager->rollback( $target_path, $backup_path, $item );
			if ( is_wp_error( $rollback ) ) {
				$this->logger->log(
					array(
						'mode'      => 'child',
						'item_type' => $item['type'],
						'item_slug' => $item['slug'],
						'action'    => 'update_failed',
						'status'    => 'update_failed',
						'message'   => $failure_reason . ' | Rollback gagal.',
					)
				);

				$events[] = $this->build_event( $item, 'rollback_failed', 'rollback_failed', $rollback->get_error_message() );
				return array(
					'status'  => 'rollback_failed',
					'message' => $failure_reason . ' | Rollback gagal: ' . $rollback->get_error_message(),
					'events'  => $events,
				);
			}

			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $failure_reason );
			$events[] = $this->build_event( $item, 'rollback_success', 'rollback_success', 'Rollback berhasil.' );
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $failure_reason . ' | Rollback berhasil.',
				)
			);

			return array(
				'status'  => 'rolled_back',
				'message' => $failure_reason,
				'events'  => $events,
			);
		}

		$this->logger->log(
			array(
				'mode'      => 'child',
				'item_type' => $item['type'],
				'item_slug' => $item['slug'],
				'action'    => 'update_success',
				'status'    => 'update_success',
				'message'   => $fallback_used ? 'Update sukses melalui fallback replace.' : 'Update sukses melalui WP-native.',
			)
		);

		$events[] = $this->build_event(
			$item,
			'update_success',
			'update_success',
			$fallback_used ? 'Update sukses via fallback replace.' : 'Update sukses via WP-native.'
		);

		return array(
			'status'  => 'update_success',
			'message' => $fallback_used ? 'Update selesai (fallback).' : 'Update selesai (WP-native).',
			'events'  => $events,
		);
	}

	/**
	 * Download package ZIP to temporary file.
	 *
	 * @param string $download_url Package URL.
	 * @return string|\WP_Error
	 */
	private function download_package( $download_url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $download_url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		return $tmp;
	}

	/**
	 * Apply WordPress core update from ZIP using native core upgrader.
	 *
	 * @param array  $item Item info.
	 * @param string $download_url Signed package URL.
	 * @return array
	 */
	private function apply_core_update( array $item, $download_url ) {
		$events = array();
		$events[] = $this->build_event( $item, 'update_started', 'update_started', 'Update core dimulai.' );

		$tmp_zip = $this->download_package( $download_url );
		if ( is_wp_error( $tmp_zip ) ) {
			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $tmp_zip->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $tmp_zip->get_error_message(),
				'events'  => $events,
			);
		}

		$validation = $this->validate_zip( $tmp_zip );
		if ( is_wp_error( $validation ) ) {
			@unlink( $tmp_zip );
			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $validation->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $validation->get_error_message(),
				'events'  => $events,
			);
		}

		try {
			$core_result = $this->apply_wp_native( 'core', $tmp_zip );
		} catch ( \Throwable $throwable ) {
			$core_result = new \WP_Error(
				'rawatwp_wp_native_core_exception',
				sprintf( 'WP-native core exception: %s', $throwable->getMessage() )
			);
		}
		@unlink( $tmp_zip );

		if ( is_wp_error( $core_result ) ) {
			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $core_result->get_error_message() );
			return array(
				'status'  => 'update_failed',
				'message' => $core_result->get_error_message(),
				'events'  => $events,
			);
		}

		$health = $this->health_checker->run( wp_normalize_path( ABSPATH . 'wp-includes/version.php' ) );
		if ( empty( $health['ok'] ) ) {
			$message = isset( $health['message'] ) ? $health['message'] : 'Health check gagal setelah update core.';
			$events[] = $this->build_event( $item, 'update_failed', 'update_failed', $message );
			return array(
				'status'  => 'update_failed',
				'message' => $message,
				'events'  => $events,
			);
		}

		$events[] = $this->build_event( $item, 'update_success', 'update_success', 'Update core berhasil.' );

		return array(
			'status'  => 'update_success',
			'message' => 'Update core berhasil.',
			'events'  => $events,
		);
	}

	/**
	 * Try update using native WordPress upgrader.
	 *
	 * @param string $type Item type.
	 * @param string $zip_path Local ZIP path.
	 * @return true|\WP_Error
	 */
	private function apply_wp_native( $type, $zip_path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'plugin' === $type ) {
			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install(
				$zip_path,
				array(
					'overwrite_package' => true,
				)
			);

			if ( is_wp_error( $result ) || false === $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'rawatwp_wp_native_plugin_fail', __( 'WP-native update plugin gagal.', 'rawatwp' ) );
			}

			return true;
		}

		if ( 'theme' === $type ) {
			$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install(
				$zip_path,
				array(
					'overwrite_package' => true,
				)
			);

			if ( is_wp_error( $result ) || false === $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'rawatwp_wp_native_theme_fail', __( 'WP-native update theme gagal.', 'rawatwp' ) );
			}

			return true;
		}

		if ( 'core' === $type ) {
			$upgrader = new \Core_Upgrader( new \Automatic_Upgrader_Skin() );
			$current_version = get_bloginfo( 'version' );
			$offer = (object) array(
				'response' => 'upgrade',
				'current'  => $current_version,
				'version'  => $current_version,
				'locale'   => get_locale(),
				'packages' => (object) array(
					'full' => $zip_path,
				),
			);

			$result = $upgrader->upgrade( $offer );
			if ( is_wp_error( $result ) || false === $result ) {
				return is_wp_error( $result ) ? $result : new \WP_Error( 'rawatwp_wp_native_core_fail', __( 'WP-native update core gagal.', 'rawatwp' ) );
			}

			return true;
		}

		return new \WP_Error( 'rawatwp_wp_native_unsupported', __( 'Type tidak didukung WP-native.', 'rawatwp' ) );
	}

	/**
	 * Fallback update by copy/replace files into target folder.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $target_path Destination path.
	 * @param string $target_slug Target slug.
	 * @param string $target_type Target type.
	 * @return true|\WP_Error
	 */
	private function apply_fallback_replace( $zip_path, $target_path, $target_slug, $target_type ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_root = $this->get_temp_root();
		$work_dir  = trailingslashit( $temp_root ) . 'extract-' . wp_generate_uuid4();
		wp_mkdir_p( $work_dir );

		$unzipped = unzip_file( $zip_path, $work_dir );
		if ( is_wp_error( $unzipped ) ) {
			$this->backup_manager->recursive_delete( $work_dir );
			return new \WP_Error( 'rawatwp_unzip_failed', $unzipped->get_error_message() );
		}

		$source_root = $this->resolve_source_root( $work_dir, $target_slug, $target_type );
		if ( is_wp_error( $source_root ) ) {
			$this->backup_manager->recursive_delete( $work_dir );
			return $source_root;
		}

		if ( ! file_exists( $target_path ) ) {
			wp_mkdir_p( $target_path );
		}

		$copied = $this->copy_replace_contents( $source_root, $target_path );
		$this->backup_manager->recursive_delete( $work_dir );

		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		return true;
	}

	/**
	 * Resolve target path from monitored item.
	 *
	 * @param array $item Monitored item.
	 * @return string|\WP_Error
	 */
	private function resolve_target_path( array $item ) {
		$type = sanitize_key( $item['type'] );
		$slug = sanitize_title( $item['slug'] );

		if ( '' === $slug ) {
			return new \WP_Error( 'rawatwp_bad_target_slug', __( 'Slug target tidak valid.', 'rawatwp' ) );
		}

		if ( 'plugin' === $type ) {
			return wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug );
		}

		if ( 'theme' === $type ) {
			return wp_normalize_path( get_theme_root() . '/' . $slug );
		}

		return new \WP_Error( 'rawatwp_target_type_unsupported', __( 'Type target tidak didukung.', 'rawatwp' ) );
	}

	/**
	 * Validate ZIP for traversal and symlink.
	 *
	 * @param string $zip_path ZIP path.
	 * @return true|\WP_Error
	 */
	private function validate_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'rawatwp_ziparchive_missing', __( 'ZipArchive tidak tersedia.', 'rawatwp' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'rawatwp_bad_zip', __( 'ZIP tidak valid.', 'rawatwp' ) );
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! $stat || empty( $stat['name'] ) ) {
				$zip->close();
				return new \WP_Error( 'rawatwp_bad_zip_entry', __( 'Entry ZIP tidak valid.', 'rawatwp' ) );
			}

			$name = (string) $stat['name'];
			if ( false !== strpos( $name, '../' ) || false !== strpos( $name, '..\\' ) || preg_match( '#^([a-zA-Z]:|/)#', $name ) ) {
				$zip->close();
				return new \WP_Error( 'rawatwp_zip_path_attack', __( 'ZIP berbahaya (path traversal).', 'rawatwp' ) );
			}

			if ( isset( $stat['external_attributes'] ) ) {
				$mode = ( $stat['external_attributes'] >> 16 ) & 0170000;
				if ( 0120000 === $mode ) {
					$zip->close();
					return new \WP_Error( 'rawatwp_zip_symlink', __( 'ZIP berisi symlink dan ditolak.', 'rawatwp' ) );
				}
			}
		}

		$zip->close();

		return true;
	}

	/**
	 * Resolve best source root from extracted ZIP content.
	 *
	 * @param string $extract_dir Extraction directory.
	 * @param string $target_slug Target slug.
	 * @param string $target_type Target type.
	 * @return string|\WP_Error
	 */
	private function resolve_source_root( $extract_dir, $target_slug, $target_type ) {
		$extract_dir = wp_normalize_path( $extract_dir );
		$target_slug = sanitize_title( $target_slug );
		$target_type = sanitize_key( $target_type );

		$candidates = array(
			$extract_dir . '/' . $target_slug,
		);

		if ( 'plugin' === $target_type ) {
			$candidates[] = $extract_dir . '/wp-content/plugins/' . $target_slug;
		}

		if ( 'theme' === $target_type ) {
			$candidates[] = $extract_dir . '/wp-content/themes/' . $target_slug;
		}

		$top_dirs_glob = glob( trailingslashit( $extract_dir ) . '*', GLOB_ONLYDIR );
		$top_dirs      = array_values(
			array_filter(
				is_array( $top_dirs_glob ) ? $top_dirs_glob : array(),
				'strlen'
			)
		);

		foreach ( $top_dirs as $top_dir ) {
			$top_dir       = wp_normalize_path( $top_dir );
			$candidates[]  = $top_dir . '/' . $target_slug;
			$candidates[]  = $top_dir . '/wp-content/plugins/' . $target_slug;
			$candidates[]  = $top_dir . '/wp-content/themes/' . $target_slug;
		}

		$candidates = array_values( array_unique( array_map( 'wp_normalize_path', $candidates ) ) );
		foreach ( $candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				return $candidate;
			}
		}

		$entries     = array_values(
			array_filter(
				scandir( $extract_dir ),
				static function( $entry ) {
					return '.' !== $entry && '..' !== $entry;
				}
			)
		);

		if ( empty( $entries ) ) {
			return new \WP_Error( 'rawatwp_empty_extract', __( 'Isi ZIP kosong.', 'rawatwp' ) );
		}

		$slug_path = $extract_dir . '/' . $target_slug;
		if ( file_exists( $slug_path ) && is_dir( $slug_path ) ) {
			return $slug_path;
		}

		if ( 1 === count( $entries ) ) {
			$single_path = $extract_dir . '/' . $entries[0];
			if ( is_dir( $single_path ) ) {
				return $single_path;
			}
		}

		return $extract_dir;
	}

	/**
	 * Check whether WP-native installer is safe to use for this target.
	 *
	 * @param string $zip_path ZIP path.
	 * @param array  $item Target item.
	 * @return bool
	 */
	private function can_use_wp_native( $zip_path, array $item ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : '';
		$slug = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : '';
		if ( '' === $type || '' === $slug ) {
			return false;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}

		$prefixes = array(
			$slug . '/',
			'wp-content/plugins/' . $slug . '/',
			'wp-content/themes/' . $slug . '/',
			'wordpress/wp-content/plugins/' . $slug . '/',
			'wordpress/wp-content/themes/' . $slug . '/',
		);

		$allowed = false;

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name = str_replace( '\\', '/', (string) $stat['name'] );
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$allowed = true;
					break 2;
				}
			}
		}

		$zip->close();

		if ( ! $allowed ) {
			return false;
		}

		if ( 'plugin' === $type && ! is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
			return false;
		}

		if ( 'theme' === $type && ! is_dir( get_theme_root() . '/' . $slug ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Copy source content into target path and overwrite existing files.
	 *
	 * @param string $source Source directory.
	 * @param string $target Target directory.
	 * @return true|\WP_Error
	 */
	private function copy_replace_contents( $source, $target ) {
		$source = wp_normalize_path( $source );
		$target = wp_normalize_path( $target );

		if ( is_file( $source ) ) {
			$parent = dirname( $target );
			if ( ! file_exists( $parent ) ) {
				wp_mkdir_p( $parent );
			}

			if ( ! @copy( $source, $target ) ) {
				return new \WP_Error( 'rawatwp_copy_failed', sprintf( 'Gagal menyalin file %s', $source ) );
			}

			return true;
		}

		if ( ! file_exists( $target ) ) {
			wp_mkdir_p( $target );
		}

		$entries = scandir( $source );
		if ( false === $entries ) {
			return new \WP_Error( 'rawatwp_read_source_failed', __( 'Gagal membaca source update.', 'rawatwp' ) );
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$src = $source . '/' . $entry;
			$dst = $target . '/' . $entry;

			if ( is_link( $src ) ) {
				continue;
			}

			$result = $this->copy_replace_contents( $src, $dst );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Build event payload.
	 *
	 * @param array  $item Item data.
	 * @param string $action Action.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @return array
	 */
	private function build_event( array $item, $action, $status, $message ) {
		return array(
			'item_type' => $item['type'],
			'item_slug' => $item['slug'],
			'action'    => $action,
			'status'    => $status,
			'message'   => $message,
			'context'   => array(),
		);
	}

	/**
	 * Get temporary extraction root.
	 *
	 * @return string
	 */
	private function get_temp_root() {
		$upload_dir = wp_upload_dir();
		$temp_root  = trailingslashit( $upload_dir['basedir'] ) . 'rawatwp/temp';

		if ( ! file_exists( $temp_root ) ) {
			wp_mkdir_p( $temp_root );
		}

		return wp_normalize_path( $temp_root );
	}
}
