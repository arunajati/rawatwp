<?php
namespace RawatWP;

use RawatWP\Child\BackupManager;
use RawatWP\Child\ChildManager;
use RawatWP\Child\HealthChecker;
use RawatWP\Child\MonitoredItemsManager;
use RawatWP\Child\RollbackManager;
use RawatWP\Child\UpdateEngine;
use RawatWP\Core\AdminPages;
use RawatWP\Core\ApiManager;
use RawatWP\Core\Database;
use RawatWP\Core\GitHubUpdater;
use RawatWP\Core\Logger;
use RawatWP\Core\ModeManager;
use RawatWP\Core\SecurityManager;
use RawatWP\Master\MasterManager;
use RawatWP\Master\PackageManager;
use RawatWP\Master\QueueManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Plugin initialized flag.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Admin pages manager.
	 *
	 * @var AdminPages
	 */
	private $admin_pages;

	/**
	 * API manager.
	 *
	 * @var ApiManager
	 */
	private $api_manager;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin wiring.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$database       = new Database();
		$database->maybe_upgrade_schema();
		$logger         = new Logger( $database );
		$mode_manager   = new ModeManager( $logger );
		$security       = new SecurityManager();
		$github_updater = new GitHubUpdater();
		$github_updater->init();
		$package_manager = new PackageManager( $database, $logger, $security );
		$master_manager = new MasterManager( $database, $logger, $security, $package_manager, $mode_manager );
		$queue_manager  = new QueueManager( $database, $logger, $master_manager, $package_manager );

		$monitored_items = new MonitoredItemsManager();
		$backup_manager  = new BackupManager( $logger );
		$rollback_manager = new RollbackManager( $logger, $backup_manager );
		$health_checker  = new HealthChecker();
		$update_engine   = new UpdateEngine( $logger, $backup_manager, $rollback_manager, $health_checker );
		$child_manager   = new ChildManager( $database, $logger, $security, $mode_manager, $monitored_items, $update_engine );

		$this->api_manager  = new ApiManager( $mode_manager, $master_manager, $child_manager, $queue_manager, $security );
		$this->admin_pages  = new AdminPages( $mode_manager, $master_manager, $child_manager, $monitored_items, $package_manager, $queue_manager, $logger, $security, $github_updater );

		add_action( 'rest_api_init', array( $this->api_manager, 'register_routes' ) );
		add_action( 'admin_menu', array( $this->admin_pages, 'register_menus' ) );
		add_action( 'admin_init', array( $this->admin_pages, 'register_admin_actions' ) );
		$logger->maybe_run_maintenance( 30, 10000, 3600 );

		$this->initialized = true;
	}
}
