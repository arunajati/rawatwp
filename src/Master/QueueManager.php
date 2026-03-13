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
	 * Delay seconds when a queue item must wait for previous item in same site batch.
	 */
	const PREDECESSOR_WAIT_SECONDS = 30;

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

		$sequence_packages = array( $package );
		$patch_numbers     = array();
		$is_patch_sequence = false;

		if ( $this->is_sequential_patch_candidate( $package ) ) {
			$sequence_data = $this->build_patch_sequence_packages( $package );
			if ( is_wp_error( $sequence_data ) ) {
				return $sequence_data;
			}

			$sequence_packages = isset( $sequence_data['packages'] ) && is_array( $sequence_data['packages'] ) ? $sequence_data['packages'] : array( $package );
			$patch_numbers     = isset( $sequence_data['numbers'] ) && is_array( $sequence_data['numbers'] ) ? $sequence_data['numbers'] : array();
			$is_patch_sequence = count( $sequence_packages ) > 1;
		}

		$batch_id = 'batch-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$queued   = 0;
		$skipped  = 0;
		$site_count = 0;

		foreach ( $site_ids as $site_id ) {
			$site = $this->database->get_site_by_id( $site_id );
			if ( ! $site ) {
				$skipped++;
				continue;
			}

			$site_count++;
			foreach ( $sequence_packages as $sequence_package ) {
				$sequence_number = $this->extract_patch_number( $sequence_package );
				$queue_message   = $is_patch_sequence && $sequence_number > 0
					? sprintf( 'Added to patch sequence queue (#%d).', $sequence_number )
					: 'Added to update queue.';

				$queue_id = $this->database->insert_queue_item(
					array(
						'batch_id'     => $batch_id,
						'site_id'      => $site_id,
						'package_id'   => (int) $sequence_package['id'],
						'status'       => 'on_queue',
						'progress'     => 0.0,
						'message'      => $queue_message,
						'reason_code'  => $is_patch_sequence ? 'queued_patch_sequence' : 'queued',
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
		}

		if ( $queued <= 0 ) {
			return new \WP_Error( 'rawatwp_queue_failed', __( 'Failed to enqueue task.', 'rawatwp' ) );
		}

		$log_message = sprintf( 'Batch %s created. %d item(s) queued, %d skipped.', $batch_id, $queued, $skipped );
		if ( $is_patch_sequence ) {
			$numbers_text = implode( ' -> ', array_map( 'intval', $patch_numbers ) );
			$log_message  = sprintf( 'Patch sequence batch %s created (%s). %d item(s) queued, %d skipped.', $batch_id, $numbers_text, $queued, $skipped );
		}

		$this->logger->log(
			array(
				'mode'      => 'master',
				'action'    => 'queue_created',
				'status'    => 'on_queue',
				'item_type' => $package['type'],
				'item_slug' => $package['target_slug'],
				'message'   => $log_message,
				'context'   => array(
					'batch_id'          => $batch_id,
					'queued'            => $queued,
					'skipped'           => $skipped,
					'site_count'        => $site_count,
					'package_count'     => count( $sequence_packages ),
					'is_patch_sequence' => $is_patch_sequence,
					'patch_numbers'     => $patch_numbers,
				),
			)
		);

		return array(
			'batch_id'          => $batch_id,
			'queued'            => $queued,
			'skipped'           => $skipped,
			'site_count'        => $site_count,
			'package_count'     => count( $sequence_packages ),
			'is_patch_sequence' => $is_patch_sequence,
			'patch_numbers'     => $patch_numbers,
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
					'progress'    => 100.0,
					'reason_code' => 'invalid_target',
					'message'     => 'Failed: site or package data was not found.',
					'last_error'  => 'Site/package not found while processing.',
					'finished_at' => current_time( 'mysql' ),
					'worker_id'   => null,
				)
			);
			return;
		}

		$blocking_item = $this->database->get_blocking_predecessor_queue_item(
			isset( $item['batch_id'] ) ? (string) $item['batch_id'] : '',
			(int) $item['site_id'],
			$queue_id
		);
		if ( is_array( $blocking_item ) ) {
			$blocking_status = isset( $blocking_item['status'] ) ? sanitize_key( (string) $blocking_item['status'] ) : '';
			$blocking_id     = isset( $blocking_item['id'] ) ? (int) $blocking_item['id'] : 0;

			if ( 'failed' === $blocking_status ) {
				$this->database->update_queue_item(
					$queue_id,
					array(
						'status'      => 'failed',
						'progress'    => 100.0,
						'reason_code' => 'previous_patch_failed',
						'message'     => sprintf( 'Skipped: previous patch task #%d failed.', $blocking_id ),
						'last_error'  => sprintf( 'Previous task #%d failed in this patch batch.', $blocking_id ),
						'finished_at' => current_time( 'mysql' ),
						'worker_id'   => null,
					)
				);
				return;
			}

			$wait_next_at = wp_date( 'Y-m-d H:i:s', time() + self::PREDECESSOR_WAIT_SECONDS, wp_timezone() );
			$this->database->update_queue_item(
				$queue_id,
				array(
					'status'      => 'on_queue',
					'progress'    => 0.0,
					'reason_code' => 'waiting_previous_patch',
					'message'     => sprintf( 'Waiting for previous patch task #%d to finish first.', $blocking_id ),
					'next_run_at' => $wait_next_at,
					'worker_id'   => null,
				)
			);
			return;
		}

		$this->database->touch_queue_heartbeat( $queue_id, $worker_id );
		$this->database->update_queue_item(
			$queue_id,
			array(
				'progress' => 30.5,
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
					'progress'    => 100.0,
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
					'progress'    => 0.0,
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
				'progress'    => 100.0,
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

	/**
	 * Build patch sequence package list up to selected patch.
	 *
	 * @param array $selected_package Selected package row.
	 * @return array|\WP_Error
	 */
	private function build_patch_sequence_packages( array $selected_package ) {
		$selected_number = $this->extract_patch_number( $selected_package );
		if ( $selected_number <= 0 ) {
			return new \WP_Error( 'rawatwp_patch_number_not_found', __( 'Patch number cannot be detected from selected package.', 'rawatwp' ) );
		}

		$target_type = isset( $selected_package['type'] ) ? sanitize_key( (string) $selected_package['type'] ) : '';
		$target_slug = isset( $selected_package['target_slug'] ) ? sanitize_key( (string) $selected_package['target_slug'] ) : '';
		if ( '' === $target_type || '' === $target_slug ) {
			return new \WP_Error( 'rawatwp_patch_target_missing', __( 'Selected patch target is invalid.', 'rawatwp' ) );
		}

		$all_packages = $this->package_manager->get_packages();
		$grouped      = array();

		foreach ( $all_packages as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_type = isset( $candidate['type'] ) ? sanitize_key( (string) $candidate['type'] ) : '';
			$candidate_slug = isset( $candidate['target_slug'] ) ? sanitize_key( (string) $candidate['target_slug'] ) : '';
			if ( $candidate_type !== $target_type || $candidate_slug !== $target_slug ) {
				continue;
			}

			if ( ! $this->is_sequential_patch_candidate( $candidate ) ) {
				continue;
			}

			$number = $this->extract_patch_number( $candidate );
			if ( $number <= 0 ) {
				continue;
			}

			// get_packages() is sorted newest-first; keep first for each number.
			if ( ! isset( $grouped[ $number ] ) ) {
				$grouped[ $number ] = $candidate;
			}
		}

		$grouped[ $selected_number ] = $selected_package;
		ksort( $grouped, SORT_NUMERIC );

		$sequence = array();
		$numbers  = array();
		foreach ( $grouped as $number => $candidate ) {
			$number = (int) $number;
			if ( $number > $selected_number ) {
				continue;
			}
			$sequence[] = $candidate;
			$numbers[]  = $number;
		}

		if ( empty( $sequence ) ) {
			return new \WP_Error( 'rawatwp_patch_sequence_empty', __( 'No valid patch sequence was found for this target.', 'rawatwp' ) );
		}

		return array(
			'packages' => $sequence,
			'numbers'  => $numbers,
		);
	}

	/**
	 * Check whether package is a patch candidate for sequential apply.
	 *
	 * @param array $package Package row.
	 * @return bool
	 */
	private function is_sequential_patch_candidate( array $package ) {
		$source_type = isset( $package['source_type'] ) ? sanitize_key( (string) $package['source_type'] ) : '';
		if ( in_array( $source_type, array( 'patch_source', 'patch_bundle' ), true ) ) {
			return true;
		}

		// Backward compatibility for packages stored before source_type existed.
		$number = $this->extract_patch_number( $package );
		if ( $number <= 0 ) {
			return false;
		}

		$label       = isset( $package['label'] ) ? sanitize_text_field( (string) $package['label'] ) : '';
		$file_name   = isset( $package['file_name'] ) ? sanitize_file_name( (string) $package['file_name'] ) : '';
		$source_name = isset( $package['source_name'] ) ? sanitize_file_name( (string) $package['source_name'] ) : '';

		if ( false !== stripos( $label, 'patch' ) || false !== stripos( $file_name, 'patch' ) || false !== stripos( $source_name, 'patch' ) ) {
			return true;
		}

		$numeric_only_label  = (bool) preg_match( '/^\s*#?\d{5,8}\s*$/', $label );
		$numeric_only_file   = (bool) preg_match( '/^\d{5,8}\.zip$/i', $file_name );
		$numeric_only_source = (bool) preg_match( '/^\d{5,8}\.zip$/i', $source_name );

		return $numeric_only_label || $numeric_only_file || $numeric_only_source;
	}

	/**
	 * Extract patch number from package metadata.
	 *
	 * @param array $package Package row.
	 * @return int
	 */
	private function extract_patch_number( array $package ) {
		$fields = array(
			isset( $package['source_name'] ) ? (string) $package['source_name'] : '',
			isset( $package['label'] ) ? (string) $package['label'] : '',
			isset( $package['file_name'] ) ? (string) $package['file_name'] : '',
		);

		$best = 0;
		foreach ( $fields as $field ) {
			$field = sanitize_text_field( $field );
			if ( '' === $field ) {
				continue;
			}

			if ( preg_match_all( '/\d{5,8}/', $field, $matches ) ) {
				foreach ( $matches[0] as $found ) {
					$number = (int) $found;
					if ( $number > $best ) {
						$best = $number;
					}
				}
			}
		}

		return $best;
	}
}
