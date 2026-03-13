<?php
namespace RawatWP\Master;

use RawatWP\Core\Database;
use RawatWP\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueueManager {
	/**
	 * Queue lock transient key.
	 */
	const LOCK_KEY = 'rawatwp_queue_lock';

	/**
	 * Queue paused option key.
	 */
	const OPTION_QUEUE_PAUSED = 'rawatwp_queue_paused';

	/**
	 * Max retry count for transient errors.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Processing heartbeat timeout in seconds.
	 */
	const STALE_PROCESSING_TIMEOUT = 300;

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
	 * Master manager.
	 *
	 * @var MasterManager
	 */
	private $master_manager;

	/**
	 * Package manager.
	 *
	 * @var PackageManager
	 */
	private $package_manager;

	/**
	 * Constructor.
	 *
	 * @param Database       $database Database.
	 * @param Logger         $logger Logger.
	 * @param MasterManager  $master_manager Master manager.
	 * @param PackageManager $package_manager Package manager.
	 */
	public function __construct( Database $database, Logger $logger, MasterManager $master_manager, PackageManager $package_manager ) {
		$this->database        = $database;
		$this->logger          = $logger;
		$this->master_manager  = $master_manager;
		$this->package_manager = $package_manager;
	}

	/**
	 * Enqueue push update for multiple child sites.
	 *
	 * @param int   $package_id Package ID.
	 * @param array $site_ids Site IDs.
	 * @return array|\WP_Error
	 */
	public function enqueue_batch( $package_id, array $site_ids ) {
		$package = $this->package_manager->get_package( $package_id );
		if ( ! $package ) {
			return new \WP_Error( 'rawatwp_package_not_found', __( 'Package not found.', 'rawatwp' ) );
		}

		if ( ! in_array( $package['type'], array( 'plugin', 'theme', 'core' ), true ) ) {
			return new \WP_Error( 'rawatwp_package_type_unsupported', __( 'This package type is not supported.', 'rawatwp' ) );
		}

		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		if ( empty( $site_ids ) ) {
			return new \WP_Error( 'rawatwp_empty_sites', __( 'Select at least one child site.', 'rawatwp' ) );
		}

		if ( 'core' === $package['type'] && count( $site_ids ) > 1 ) {
			return new \WP_Error( 'rawatwp_core_single_child_only', __( 'WordPress core update can target only one child per batch.', 'rawatwp' ) );
		}

		$batch_id = 'batch-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$queued   = 0;
		$skipped  = 0;

		foreach ( $site_ids as $site_id ) {
			$site = $this->database->get_site_by_id( $site_id );
			if ( ! $site ) {
				$skipped++;
				continue;
			}

			$queue_id = $this->database->insert_queue_item(
				array(
					'batch_id'     => $batch_id,
					'site_id'      => $site_id,
					'package_id'   => (int) $package['id'],
					'status'       => 'on_queue',
					'progress'     => 0,
					'message'      => 'Added to update queue.',
					'reason_code'  => 'queued',
					'attempts'     => 0,
					'max_attempts' => self::MAX_ATTEMPTS,
					'next_run_at'  => current_time( 'mysql' ),
				)
			);

			if ( false === $queue_id ) {
				$skipped++;
				continue;
			}

			$queued++;
		}

		if ( $queued <= 0 ) {
			return new \WP_Error( 'rawatwp_queue_failed', __( 'Failed to enqueue task.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'      => 'master',
				'action'    => 'queue_created',
				'status'    => 'on_queue',
				'item_type' => $package['type'],
				'item_slug' => $package['target_slug'],
				'message'   => sprintf( 'Batch %s created. %d site(s) queued, %d skipped.', $batch_id, $queued, $skipped ),
				'context'   => array(
					'batch_id' => $batch_id,
					'queued'   => $queued,
					'skipped'  => $skipped,
				),
			)
		);

		return array(
			'batch_id' => $batch_id,
			'queued'   => $queued,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Run queue worker for N items.
	 *
	 * @param string $source Worker source.
	 * @param int    $limit Max item processed in one run.
	 * @return array
	 */
	public function run_worker( $source = 'system_cron', $limit = 1 ) {
		$limit = max( 1, (int) $limit );

		$this->logger->maybe_run_maintenance( 30, 10000, 3600 );

		if ( $this->is_paused() ) {
			return array(
				'processed' => 0,
				'paused'    => true,
				'counts'    => $this->database->get_queue_counts(),
			);
		}

		if ( $this->is_locked() ) {
			return array(
				'processed' => 0,
				'locked'    => true,
				'counts'    => $this->database->get_queue_counts(),
			);
		}

		$this->lock();
		$worker_id = sanitize_key( $source ) . '-' . wp_generate_password( 10, false, false );
		$processed = 0;
		$recovered = 0;

		try {
			$recovered = $this->database->requeue_stale_processing( self::STALE_PROCESSING_TIMEOUT );

			for ( $i = 0; $i < $limit; $i++ ) {
				$item = $this->database->claim_next_queue_item( $worker_id );
				if ( ! $item ) {
					break;
				}

				$this->process_claimed_item( $item, $worker_id );
				$processed++;
			}
		} finally {
			$this->unlock();
		}

		return array(
			'processed' => $processed,
			'recovered' => $recovered,
			'counts'    => $this->database->get_queue_counts(),
		);
	}

	/**
	 * Keep backward compatibility with old queue tick call.
	 *
	 * @return void
	 */
	public function process_queue_tick() {
		$this->run_worker( 'legacy', 1 );
	}

	/**
	 * Pause queue runner.
	 *
	 * @return void
	 */
	public function pause_queue() {
		update_option( self::OPTION_QUEUE_PAUSED, '1', false );
	}

	/**
	 * Resume queue runner.
	 *
	 * @return void
	 */
	public function resume_queue() {
		update_option( self::OPTION_QUEUE_PAUSED, '0', false );
	}

	/**
	 * Check queue paused state.
	 *
	 * @return bool
	 */
	public function is_paused() {
		return '1' === (string) get_option( self::OPTION_QUEUE_PAUSED, '0' );
	}

	/**
	 * Get queue rows with site/package labels.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_queue_rows( $limit = 200 ) {
		$rows = $this->database->get_queue_items( $limit );

		foreach ( $rows as &$row ) {
			$site    = $this->database->get_site_by_id( (int) $row['site_id'] );
			$package = $this->package_manager->get_package( (int) $row['package_id'] );

			$row['site_name']  = $site ? $site['site_name'] : 'Unknown site';
			$row['site_url']   = $site ? $site['site_url'] : '';
			$row['item_label'] = $package ? $package['label'] : 'Unknown package';
			$row['item_type']  = $package ? $package['type'] : '';
			$row['item_slug']  = $package ? $package['target_slug'] : '';
		}

		return $rows;
	}

	/**
	 * Get queue status counts.
	 *
	 * @return array
	 */
	public function get_queue_counts() {
		return $this->database->get_queue_counts();
	}

	/**
	 * Process one claimed queue item.
	 *
	 * @param array  $item Claimed queue item.
	 * @param string $worker_id Worker ID.
	 * @return void
	 */
	private function process_claimed_item( array $item, $worker_id ) {
		$queue_id = (int) $item['id'];
		$site     = $this->database->get_site_by_id( (int) $item['site_id'] );
		$package  = $this->package_manager->get_package( (int) $item['package_id'] );

		if ( ! $site || ! $package ) {
			$this->database->update_queue_item(
				$queue_id,
				array(
					'status'      => 'failed',
					'progress'    => 100,
					'reason_code' => 'invalid_target',
					'message'     => 'Failed: site or package data was not found.',
					'last_error'  => 'Site/package not found while processing.',
					'finished_at' => current_time( 'mysql' ),
					'worker_id'   => null,
				)
			);
			return;
		}

		$this->database->touch_queue_heartbeat( $queue_id, $worker_id );
		$this->database->update_queue_item(
			$queue_id,
			array(
				'progress' => 30,
				'message'  => sprintf( 'Sending update command to %s.', $site['site_name'] ),
			)
		);

		$dispatch = $this->master_manager->dispatch_package_to_site( $package, $site );
		$status   = isset( $dispatch['status'] ) ? sanitize_key( $dispatch['status'] ) : 'failed';
		$message  = isset( $dispatch['message'] ) ? sanitize_text_field( $dispatch['message'] ) : 'No response details.';

		if ( 'update_success' === $status || 'success' === $status ) {
			$this->database->update_queue_item(
				$queue_id,
				array(
					'status'      => 'success',
					'progress'    => 100,
					'reason_code' => 'ok',
					'message'     => $message,
					'last_error'  => null,
					'finished_at' => current_time( 'mysql' ),
					'worker_id'   => null,
				)
			);
			return;
		}

		$attempts      = (int) $item['attempts'] + 1;
		$max_attempts  = max( 1, (int) $item['max_attempts'] );
		$reason_code   = isset( $dispatch['reason_code'] ) ? sanitize_key( $dispatch['reason_code'] ) : 'update_failed';
		$is_transient  = ! empty( $dispatch['is_transient'] );
		$should_retry  = $is_transient && $attempts < $max_attempts;

		if ( $should_retry ) {
			$delay_seconds = $this->get_retry_delay_seconds( $attempts );
			$next_ts       = time() + $delay_seconds;
			$next_run_at   = wp_date( 'Y-m-d H:i:s', $next_ts, wp_timezone() );

			$this->database->update_queue_item(
				$queue_id,
				array(
					'status'      => 'on_queue',
					'progress'    => 0,
					'attempts'    => $attempts,
					'reason_code' => $reason_code,
					'message'     => sprintf( 'Temporary failure: %s. Retry %d/%d.', $message, $attempts + 1, $max_attempts ),
					'last_error'  => $message,
					'next_run_at' => $next_run_at,
					'worker_id'   => null,
				)
			);
			return;
		}

		$this->database->update_queue_item(
			$queue_id,
			array(
				'status'      => 'failed',
				'progress'    => 100,
				'attempts'    => $attempts,
				'reason_code' => $reason_code,
				'message'     => sprintf( 'Update failed: %s', $message ),
				'last_error'  => $message,
				'finished_at' => current_time( 'mysql' ),
				'worker_id'   => null,
			)
		);
	}

	/**
	 * Get exponential retry delay in seconds.
	 *
	 * @param int $attempt Current attempt.
	 * @return int
	 */
	private function get_retry_delay_seconds( $attempt ) {
		$attempt = max( 1, (int) $attempt );

		if ( 1 === $attempt ) {
			return 60;
		}

		if ( 2 === $attempt ) {
			return 180;
		}

		return 300;
	}

	/**
	 * Check queue lock status.
	 *
	 * @return bool
	 */
	private function is_locked() {
		return (bool) get_transient( self::LOCK_KEY );
	}

	/**
	 * Set queue lock.
	 *
	 * @return void
	 */
	private function lock() {
		set_transient( self::LOCK_KEY, 1, 240 );
	}

	/**
	 * Release queue lock.
	 *
	 * @return void
	 */
	private function unlock() {
		delete_transient( self::LOCK_KEY );
	}
}

