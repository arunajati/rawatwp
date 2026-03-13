<?php
namespace RawatWP\Child;

use RawatWP\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RollbackManager {
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
	 * Constructor.
	 *
	 * @param Logger        $logger Logger.
	 * @param BackupManager $backup_manager Backup manager.
	 */
	public function __construct( Logger $logger, BackupManager $backup_manager ) {
		$this->logger         = $logger;
		$this->backup_manager = $backup_manager;
	}

	/**
	 * Restore from backup.
	 *
	 * @param string $target_path Target path.
	 * @param string $backup_path Backup path.
	 * @param array  $item Item context.
	 * @return true|\WP_Error
	 */
	public function rollback( $target_path, $backup_path, array $item ) {
		$this->logger->log(
			array(
				'mode'      => 'child',
				'item_type' => $item['type'],
				'item_slug' => $item['slug'],
				'action'    => 'rollback_started',
				'status'    => 'rollback_started',
				'message'   => 'Rollback started.',
			)
		);

		$restored = $this->backup_manager->restore_backup( $backup_path, $target_path );
		if ( is_wp_error( $restored ) ) {
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $item['type'],
					'item_slug' => $item['slug'],
					'action'    => 'rollback_failed',
					'status'    => 'rollback_failed',
					'message'   => $restored->get_error_message(),
				)
			);
			return $restored;
		}

		$this->logger->log(
			array(
				'mode'      => 'child',
				'item_type' => $item['type'],
				'item_slug' => $item['slug'],
				'action'    => 'rollback_success',
				'status'    => 'rollback_success',
				'message'   => 'Rollback completed successfully.',
			)
		);

		return true;
	}
}
