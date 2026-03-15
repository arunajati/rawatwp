<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {
	/**
	 * Schema version for db migrations.
	 */
	const SCHEMA_VERSION = '1.5.0';

	/**
	 * Option key for schema version.
	 */
	const OPTION_SCHEMA_VERSION = 'rawatwp_schema_version';

	/**
	 * Get full table name.
	 *
	 * @param string $name Logical table name.
	 * @return string
	 */
	public function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'rawatwp_' . $name;
	}

	/**
	 * Create plugin tables.
	 *
	 * @return void
	 */
	public function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sites_table = $this->table( 'sites' );
		$packages_table = $this->table( 'packages' );
		$logs_table = $this->table( 'logs' );
		$queue_table = $this->table( 'queue' );

		$sites_sql = "CREATE TABLE {$sites_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_name VARCHAR(191) NOT NULL,
			site_url VARCHAR(191) NOT NULL,
			security_key VARCHAR(191) NOT NULL,
			connection_status VARCHAR(50) NOT NULL DEFAULT 'disconnected',
			last_seen DATETIME NULL,
			rawatwp_version VARCHAR(50) NULL,
			last_report LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_url (site_url),
			KEY connection_status (connection_status)
		) {$charset_collate};";

		$packages_sql = "CREATE TABLE {$packages_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(191) NOT NULL,
			type VARCHAR(20) NOT NULL,
			target_slug VARCHAR(191) NOT NULL,
			source_type VARCHAR(30) NOT NULL DEFAULT 'direct',
			source_name VARCHAR(255) NULL,
			file_name VARCHAR(255) NOT NULL,
			file_path TEXT NOT NULL,
			file_hash VARCHAR(128) NOT NULL,
			uploaded_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY target_slug (target_slug),
			KEY source_type (source_type)
		) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_url VARCHAR(255) NULL,
			mode VARCHAR(20) NOT NULL,
			item_type VARCHAR(20) NULL,
			item_slug VARCHAR(191) NULL,
			action VARCHAR(50) NOT NULL,
			status VARCHAR(50) NOT NULL,
			message TEXT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY action (action),
			KEY mode (mode)
		) {$charset_collate};";

		$queue_sql = "CREATE TABLE {$queue_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(64) NOT NULL,
			site_id BIGINT UNSIGNED NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'on_queue',
			progress DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			message TEXT NULL,
			reason_code VARCHAR(100) NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
			next_run_at DATETIME NULL,
			heartbeat_at DATETIME NULL,
			worker_id VARCHAR(64) NULL,
			last_error TEXT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY status (status),
			KEY next_run_at (next_run_at),
			KEY heartbeat_at (heartbeat_at),
			KEY site_id (site_id),
			KEY package_id (package_id)
		) {$charset_collate};";

		dbDelta( $sites_sql );
		dbDelta( $packages_sql );
		dbDelta( $logs_sql );
		dbDelta( $queue_sql );
	}

	/**
	 * Ensure schema exists and up-to-date.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		global $wpdb;

		$current_version = get_option( self::OPTION_SCHEMA_VERSION, '' );
		$sites_table     = $this->table( 'sites' );
		$table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sites_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( self::SCHEMA_VERSION !== $current_version || $sites_table !== $table_exists ) {
			$this->create_tables();
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Drop plugin tables.
	 *
	 * @return void
	 */
	public function drop_tables() {
		global $wpdb;

		$tables = array(
			$this->table( 'sites' ),
			$this->table( 'packages' ),
			$this->table( 'logs' ),
			$this->table( 'queue' ),
		);

		foreach ( $tables as $table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
	}

	/**
	 * Normalize URL for consistent storage/comparison.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '';
		$path   = '/' === $path ? '' : untrailingslashit( $path );

		return "{$scheme}://{$host}{$port}{$path}";
	}

	/**
	 * Insert a site.
	 *
	 * @param array $data Site data.
	 * @return int|false
	 */
	public function insert_site( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$this->table( 'sites' ),
			array(
				'site_name'          => sanitize_text_field( $data['site_name'] ),
				'site_url'           => $this->normalize_url( $data['site_url'] ),
				'security_key'       => sanitize_text_field( $data['security_key'] ),
				'connection_status'  => sanitize_text_field( $data['connection_status'] ),
				'last_seen'          => isset( $data['last_seen'] ) ? $data['last_seen'] : null,
				'rawatwp_version'    => isset( $data['rawatwp_version'] ) ? sanitize_text_field( $data['rawatwp_version'] ) : null,
				'last_report'        => isset( $data['last_report'] ) ? wp_json_encode( $data['last_report'] ) : null,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a site record by ID.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $data Update fields.
	 * @return bool
	 */
	public function update_site( $site_id, array $data ) {
		global $wpdb;

		$site_id = (int) $site_id;
		if ( $site_id <= 0 ) {
			return false;
		}

		$fields = array();
		$format = array();

		if ( isset( $data['site_name'] ) ) {
			$fields['site_name'] = sanitize_text_field( $data['site_name'] );
			$format[]            = '%s';
		}
		if ( isset( $data['site_url'] ) ) {
			$fields['site_url'] = $this->normalize_url( $data['site_url'] );
			$format[]           = '%s';
		}
		if ( isset( $data['security_key'] ) ) {
			$fields['security_key'] = sanitize_text_field( $data['security_key'] );
			$format[]               = '%s';
		}
		if ( isset( $data['connection_status'] ) ) {
			$fields['connection_status'] = sanitize_text_field( $data['connection_status'] );
			$format[]                    = '%s';
		}
		if ( array_key_exists( 'last_seen', $data ) ) {
			$fields['last_seen'] = $data['last_seen'];
			$format[]            = '%s';
		}
		if ( array_key_exists( 'rawatwp_version', $data ) ) {
			$fields['rawatwp_version'] = null === $data['rawatwp_version'] ? null : sanitize_text_field( (string) $data['rawatwp_version'] );
			$format[]                  = '%s';
		}
		if ( array_key_exists( 'last_report', $data ) ) {
			$fields['last_report'] = null === $data['last_report'] ? null : wp_json_encode( $data['last_report'] );
			$format[]              = '%s';
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		if ( empty( $fields ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$this->table( 'sites' ),
			$fields,
			array( 'id' => $site_id ),
			$format,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Get all child sites.
	 *
	 * @return array
	 */
	public function get_sites() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->table( 'sites' )} ORDER BY created_at DESC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_site_row' ), $rows );
	}

	/**
	 * Get site by ID.
	 *
	 * @param int $site_id Site ID.
	 * @return array|null
	 */
	public function get_site_by_id( $site_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'sites' )} WHERE id = %d", (int) $site_id ),
			ARRAY_A
		);

		return $row ? $this->hydrate_site_row( $row ) : null;
	}

	/**
	 * Get site by URL.
	 *
	 * @param string $site_url Site URL.
	 * @return array|null
	 */
	public function get_site_by_url( $site_url ) {
		global $wpdb;

		$normalized = $this->normalize_url( $site_url );
		if ( '' === $normalized ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'sites' )} WHERE site_url = %s", $normalized ),
			ARRAY_A
		);

		return $row ? $this->hydrate_site_row( $row ) : null;
	}

	/**
	 * Insert package metadata.
	 *
	 * @param array $data Package data.
	 * @return int|false
	 */
	public function insert_package( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table( 'packages' ),
			array(
				'label'       => sanitize_text_field( $data['label'] ),
				'type'        => sanitize_key( $data['type'] ),
				'target_slug' => sanitize_title( $data['target_slug'] ),
				'source_type' => isset( $data['source_type'] ) ? sanitize_key( $data['source_type'] ) : 'direct',
				'source_name' => isset( $data['source_name'] ) ? sanitize_file_name( $data['source_name'] ) : null,
				'file_name'   => sanitize_file_name( $data['file_name'] ),
				'file_path'   => sanitize_text_field( $data['file_path'] ),
				'file_hash'   => sanitize_text_field( $data['file_hash'] ),
				'uploaded_by' => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get all packages.
	 *
	 * @return array
	 */
	public function get_packages() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT * FROM {$this->table( 'packages' )} ORDER BY created_at DESC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_package_row' ), $rows );
	}

	/**
	 * Get package by ID.
	 *
	 * @param int $package_id Package ID.
	 * @return array|null
	 */
	public function get_package_by_id( $package_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'packages' )} WHERE id = %d", (int) $package_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_package_row( $row ) : null;
	}

	/**
	 * Get package by file hash.
	 *
	 * @param string $file_hash SHA-256 file hash.
	 * @return array|null
	 */
	public function get_package_by_hash( $file_hash ) {
		global $wpdb;

		$file_hash = sanitize_text_field( $file_hash );
		if ( '' === $file_hash ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'packages' )} WHERE file_hash = %s LIMIT 1", $file_hash ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_package_row( $row ) : null;
	}

	/**
	 * Delete package by ID.
	 *
	 * @param int $package_id Package ID.
	 * @return bool
	 */
	public function delete_package( $package_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->table( 'packages' ),
			array( 'id' => (int) $package_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * Insert log row.
	 *
	 * @param array $data Log data.
	 * @return int|false
	 */
	public function insert_log( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table( 'logs' ),
			array(
				'site_url'   => isset( $data['site_url'] ) ? sanitize_text_field( $data['site_url'] ) : null,
				'mode'       => sanitize_key( $data['mode'] ),
				'item_type'  => isset( $data['item_type'] ) ? sanitize_key( $data['item_type'] ) : null,
				'item_slug'  => isset( $data['item_slug'] ) ? sanitize_title( $data['item_slug'] ) : null,
				'action'     => sanitize_key( $data['action'] ),
				'status'     => sanitize_key( $data['status'] ),
				'message'    => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : null,
				'context'    => isset( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return latest DB error from wpdb.
	 *
	 * @return string
	 */
	public function get_last_error() {
		global $wpdb;

		return isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
	}

	/**
	 * Get logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_logs( $limit = 200 ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'logs' )} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['context'] = ! empty( $row['context'] ) ? json_decode( $row['context'], true ) : array();
		}

		return $rows;
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool
	 */
	public function clear_logs() {
		global $wpdb;

		$deleted = $wpdb->query( "TRUNCATE TABLE {$this->table( 'logs' )}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $deleted;
	}

	/**
	 * Prune logs older than N days.
	 *
	 * @param int $days Days.
	 * @return int Number of deleted rows.
	 */
	public function prune_logs_older_than_days( $days ) {
		global $wpdb;

		$days = max( 1, (int) $days );
		$rows = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table( 'logs' )} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$days
			)
		);

		return false === $rows ? 0 : (int) $rows;
	}

	/**
	 * Trim logs to max row count by deleting oldest rows.
	 *
	 * @param int $max_rows Max rows to keep.
	 * @return int Number of deleted rows.
	 */
	public function trim_logs_to_max_rows( $max_rows ) {
		global $wpdb;

		$max_rows = max( 100, (int) $max_rows );

		$total = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$this->table( 'logs' )}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( $total <= $max_rows ) {
			return 0;
		}

		$to_delete = $total - $max_rows;
		$deleted   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table( 'logs' )} ORDER BY id ASC LIMIT %d",
				$to_delete
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Insert queue item.
	 *
	 * @param array $data Queue data.
	 * @return int|false
	 */
	public function insert_queue_item( array $data ) {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$this->table( 'queue' ),
			array(
				'batch_id'    => sanitize_text_field( $data['batch_id'] ),
				'site_id'     => (int) $data['site_id'],
				'package_id'  => (int) $data['package_id'],
				'status'      => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'on_queue',
				'progress'    => isset( $data['progress'] ) ? (float) $data['progress'] : 0.0,
				'message'     => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : null,
				'reason_code' => isset( $data['reason_code'] ) ? sanitize_key( $data['reason_code'] ) : null,
				'attempts'    => isset( $data['attempts'] ) ? max( 0, (int) $data['attempts'] ) : 0,
				'max_attempts' => isset( $data['max_attempts'] ) ? max( 1, (int) $data['max_attempts'] ) : 3,
				'next_run_at' => isset( $data['next_run_at'] ) ? sanitize_text_field( $data['next_run_at'] ) : $now,
				'heartbeat_at' => isset( $data['heartbeat_at'] ) ? sanitize_text_field( $data['heartbeat_at'] ) : null,
				'worker_id'   => isset( $data['worker_id'] ) ? sanitize_text_field( $data['worker_id'] ) : null,
				'last_error'  => isset( $data['last_error'] ) ? sanitize_textarea_field( $data['last_error'] ) : null,
				'started_at'  => isset( $data['started_at'] ) ? sanitize_text_field( $data['started_at'] ) : null,
				'finished_at' => isset( $data['finished_at'] ) ? sanitize_text_field( $data['finished_at'] ) : null,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update queue item.
	 *
	 * @param int   $queue_id Queue ID.
	 * @param array $data Data.
	 * @return bool
	 */
	public function update_queue_item( $queue_id, array $data ) {
		global $wpdb;

		$queue_id = (int) $queue_id;
		if ( $queue_id <= 0 ) {
			return false;
		}

		$fields = array();
		$format = array();

		if ( isset( $data['status'] ) ) {
			$fields['status'] = sanitize_key( $data['status'] );
			$format[]         = '%s';
		}
		if ( isset( $data['progress'] ) ) {
			$fields['progress'] = max( 0, min( 100, (float) $data['progress'] ) );
			$format[]           = '%f';
		}
		if ( array_key_exists( 'message', $data ) ) {
			$fields['message'] = null === $data['message'] ? null : sanitize_textarea_field( $data['message'] );
			$format[]          = '%s';
		}
		if ( array_key_exists( 'reason_code', $data ) ) {
			$fields['reason_code'] = null === $data['reason_code'] ? null : sanitize_key( $data['reason_code'] );
			$format[]              = '%s';
		}
		if ( array_key_exists( 'attempts', $data ) ) {
			$fields['attempts'] = max( 0, (int) $data['attempts'] );
			$format[]           = '%d';
		}
		if ( array_key_exists( 'max_attempts', $data ) ) {
			$fields['max_attempts'] = max( 1, (int) $data['max_attempts'] );
			$format[]               = '%d';
		}
		if ( array_key_exists( 'next_run_at', $data ) ) {
			$fields['next_run_at'] = null === $data['next_run_at'] ? null : sanitize_text_field( $data['next_run_at'] );
			$format[]              = '%s';
		}
		if ( array_key_exists( 'heartbeat_at', $data ) ) {
			$fields['heartbeat_at'] = null === $data['heartbeat_at'] ? null : sanitize_text_field( $data['heartbeat_at'] );
			$format[]               = '%s';
		}
		if ( array_key_exists( 'worker_id', $data ) ) {
			$fields['worker_id'] = null === $data['worker_id'] ? null : sanitize_text_field( $data['worker_id'] );
			$format[]            = '%s';
		}
		if ( array_key_exists( 'last_error', $data ) ) {
			$fields['last_error'] = null === $data['last_error'] ? null : sanitize_textarea_field( $data['last_error'] );
			$format[]             = '%s';
		}
		if ( array_key_exists( 'started_at', $data ) ) {
			$fields['started_at'] = null === $data['started_at'] ? null : sanitize_text_field( $data['started_at'] );
			$format[]             = '%s';
		}
		if ( array_key_exists( 'finished_at', $data ) ) {
			$fields['finished_at'] = null === $data['finished_at'] ? null : sanitize_text_field( $data['finished_at'] );
			$format[]              = '%s';
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		$updated = $wpdb->update(
			$this->table( 'queue' ),
			$fields,
			array( 'id' => $queue_id ),
			$format,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Get next queue item waiting in queue.
	 *
	 * @return array|null
	 */
	public function get_next_queue_item() {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table( 'queue' )} WHERE status = %s AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY id ASC LIMIT 1",
				'on_queue',
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Claim next queue item atomically for one worker.
	 *
	 * @param string $worker_id Worker identifier.
	 * @return array|null
	 */
	public function claim_next_queue_item( $worker_id ) {
		global $wpdb;

		$worker_id = sanitize_text_field( $worker_id );
		if ( '' === $worker_id ) {
			return null;
		}

		$candidate = $this->get_next_queue_item();
		if ( ! $candidate ) {
			return null;
		}

		$queue_id = (int) $candidate['id'];
		$updated  = $wpdb->update(
			$this->table( 'queue' ),
			array(
				'status'       => 'processing',
				'progress'     => 10,
				'message'      => 'Being processed by worker.',
				'worker_id'    => $worker_id,
				'started_at'   => current_time( 'mysql' ),
				'heartbeat_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array(
				'id'     => $queue_id,
				'status' => 'on_queue',
			),
			array( '%s', '%f', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( 1 !== (int) $updated ) {
			return null;
		}

		return $this->get_queue_item_by_id( $queue_id );
	}

	/**
	 * Refresh worker heartbeat.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param string $worker_id Worker ID.
	 * @return bool
	 */
	public function touch_queue_heartbeat( $queue_id, $worker_id = '' ) {
		$queue_id = (int) $queue_id;
		if ( $queue_id <= 0 ) {
			return false;
		}

		$where = array(
			'id'     => $queue_id,
			'status' => 'processing',
		);

		$where_format = array( '%d', '%s' );

		if ( '' !== $worker_id ) {
			$where['worker_id'] = sanitize_text_field( $worker_id );
			$where_format[]     = '%s';
		}

		global $wpdb;
		$updated = $wpdb->update(
			$this->table( 'queue' ),
			array(
				'heartbeat_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			$where,
			array( '%s', '%s' ),
			$where_format
		);

		return false !== $updated;
	}

	/**
	 * Requeue stale processing rows.
	 *
	 * @param int $stale_seconds Stale threshold in seconds.
	 * @return int
	 */
	public function requeue_stale_processing( $stale_seconds = 300 ) {
		global $wpdb;

		$stale_seconds = max( 60, (int) $stale_seconds );
		$threshold_ts  = time() - $stale_seconds;
		$threshold     = wp_date( 'Y-m-d H:i:s', $threshold_ts, wp_timezone() );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table( 'queue' )}
				SET status = %s,
					progress = %f,
					message = %s,
					worker_id = NULL,
					next_run_at = %s,
					updated_at = %s
				WHERE status = %s
					AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
				'on_queue',
				0.0,
				'Worker connection was interrupted. Task has been requeued.',
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				'processing',
				$threshold
			)
		);

		return false === $rows ? 0 : (int) $rows;
	}

	/**
	 * Get queue item by ID.
	 *
	 * @param int $queue_id Queue ID.
	 * @return array|null
	 */
	public function get_queue_item_by_id( $queue_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table( 'queue' )} WHERE id = %d", (int) $queue_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get blocking predecessor queue item (same batch + site, lower ID, not success).
	 *
	 * @param string $batch_id Batch ID.
	 * @param int    $site_id Site ID.
	 * @param int    $queue_id Current queue ID.
	 * @return array|null
	 */
	public function get_blocking_predecessor_queue_item( $batch_id, $site_id, $queue_id ) {
		global $wpdb;

		$batch_id = sanitize_text_field( (string) $batch_id );
		$site_id  = (int) $site_id;
		$queue_id = (int) $queue_id;

		if ( '' === $batch_id || $site_id <= 0 || $queue_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table( 'queue' )}
				WHERE batch_id = %s
					AND site_id = %d
					AND id < %d
					AND status <> %s
				ORDER BY id ASC
				LIMIT 1",
				$batch_id,
				$site_id,
				$queue_id,
				'success'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get queue items.
	 *
	 * @param int    $limit Limit.
	 * @param string $batch_id Optional batch ID.
	 * @return array
	 */
	public function get_queue_items( $limit = 200, $batch_id = '' ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );

		if ( '' !== $batch_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table( 'queue' )} WHERE batch_id = %s ORDER BY id DESC LIMIT %d",
					sanitize_text_field( $batch_id ),
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table( 'queue' )} ORDER BY id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count queue items by status.
	 *
	 * @param string $status Status key.
	 * @return int
	 */
	public function count_queue_items_by_status( $status ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$this->table( 'queue' )} WHERE status = %s",
				sanitize_key( $status )
			)
		);

		return (int) $count;
	}

	/**
	 * Get queue counts by major statuses.
	 *
	 * @return array
	 */
	public function get_queue_counts() {
		return array(
			'on_queue'   => $this->count_queue_items_by_status( 'on_queue' ),
			'processing' => $this->count_queue_items_by_status( 'processing' ),
			'success'    => $this->count_queue_items_by_status( 'success' ),
			'failed'     => $this->count_queue_items_by_status( 'failed' ),
		);
	}

	/**
	 * Clear completed queue history (success/failed).
	 *
	 * @return int Deleted rows.
	 */
	public function clear_finished_queue_items() {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table( 'queue' )} WHERE status IN (%s, %s)",
				'success',
				'failed'
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Prune completed queue rows older than N days.
	 *
	 * @param int $days Retention days.
	 * @return int Deleted rows.
	 */
	public function prune_finished_queue_older_than_days( $days ) {
		global $wpdb;

		$days      = max( 1, (int) $days );
		$threshold = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table( 'queue' )}
				WHERE status IN (%s, %s)
					AND COALESCE(finished_at, updated_at) < %s",
				'success',
				'failed',
				$threshold
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Trim completed queue rows to max count (keeps newest).
	 *
	 * @param int $max_rows Max completed rows.
	 * @return int Deleted rows.
	 */
	public function trim_finished_queue_to_max_rows( $max_rows ) {
		global $wpdb;

		$max_rows = max( 100, (int) $max_rows );
		$total    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$this->table( 'queue' )} WHERE status IN (%s, %s)",
				'success',
				'failed'
			)
		);

		if ( $total <= $max_rows ) {
			return 0;
		}

		$delete_count = $total - $max_rows;
		$deleted      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table( 'queue' )}
				WHERE status IN (%s, %s)
				ORDER BY COALESCE(finished_at, updated_at) ASC, id ASC
				LIMIT %d",
				'success',
				'failed',
				$delete_count
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Prepare site row with decoded payload.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate_site_row( array $row ) {
		$row['last_report'] = ! empty( $row['last_report'] ) ? json_decode( $row['last_report'], true ) : array();
		$row['rawatwp_version'] = isset( $row['rawatwp_version'] ) ? sanitize_text_field( (string) $row['rawatwp_version'] ) : '';

		return $row;
	}

	/**
	 * Prepare package row defaults.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate_package_row( array $row ) {
		$row['source_type'] = isset( $row['source_type'] ) ? sanitize_key( (string) $row['source_type'] ) : 'direct';
		if ( '' === $row['source_type'] ) {
			$row['source_type'] = 'direct';
		}

		$row['source_name'] = isset( $row['source_name'] ) ? sanitize_file_name( (string) $row['source_name'] ) : '';

		return $row;
	}
}
