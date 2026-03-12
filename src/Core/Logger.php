<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {
	/**
	 * Option key for last maintenance timestamp.
	 */
	const OPTION_LAST_MAINTENANCE = 'rawatwp_logs_last_maintenance';

	/**
	 * Database handler.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Insert log entry.
	 *
	 * @param array $entry Entry payload.
	 * @return int|false
	 */
	public function log( array $entry ) {
		$defaults = array(
			'site_url'  => '',
			'mode'      => '',
			'item_type' => '',
			'item_slug' => '',
			'action'    => 'event',
			'status'    => 'info',
			'message'   => '',
			'context'   => array(),
		);

		$entry = wp_parse_args( $entry, $defaults );

		return $this->database->insert_log( $entry );
	}

	/**
	 * Get latest logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_logs( $limit = 200 ) {
		return $this->database->get_logs( $limit );
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool
	 */
	public function clear_logs() {
		return $this->database->clear_logs();
	}

	/**
	 * Remove logs older than N days.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function prune_logs_older_than_days( $days = 30 ) {
		return $this->database->prune_logs_older_than_days( $days );
	}

	/**
	 * Trim logs by max row count.
	 *
	 * @param int $max_rows Maximum rows.
	 * @return int
	 */
	public function trim_logs_to_max_rows( $max_rows = 10000 ) {
		return $this->database->trim_logs_to_max_rows( $max_rows );
	}

	/**
	 * Run log maintenance at most once per interval.
	 *
	 * @param int $max_age_days Max age in days.
	 * @param int $max_rows Max rows.
	 * @param int $interval_seconds Interval in seconds.
	 * @return array
	 */
	public function maybe_run_maintenance( $max_age_days = 30, $max_rows = 10000, $interval_seconds = 3600 ) {
		$last = (int) get_option( self::OPTION_LAST_MAINTENANCE, 0 );
		$now  = time();

		if ( $last > 0 && ( $now - $last ) < max( 300, (int) $interval_seconds ) ) {
			return array(
				'pruned'  => 0,
				'trimmed' => 0,
				'skipped' => true,
			);
		}

		$pruned  = $this->prune_logs_older_than_days( $max_age_days );
		$trimmed = $this->trim_logs_to_max_rows( $max_rows );

		update_option( self::OPTION_LAST_MAINTENANCE, $now, false );

		return array(
			'pruned'  => (int) $pruned,
			'trimmed' => (int) $trimmed,
			'skipped' => false,
		);
	}
}
