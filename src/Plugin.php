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
use RawatWP\Core\Activator;
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
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
			add_action( 'admin_enqueue_scripts', array( $this->admin_pages, 'enqueue_assets' ) );
			add_action( 'admin_notices', array( $this->admin_pages, 'render_plugins_page_notices' ) );
			add_filter( 'plugin_row_meta', array( $this->admin_pages, 'add_plugin_row_meta_check_update' ), 10, 4 );
			add_action( 'wp_ajax_rawatwp_child_check_updates', array( $child_manager, 'ajax_check_updates' ) );
			add_action( 'wp_ajax_nopriv_rawatwp_child_check_updates', array( $child_manager, 'ajax_check_updates' ) );

		add_action(
			'rawatwp_daily_github_update_check',
			static function() use ( $github_updater ) {
				$github_updater->force_check_now();
			}
		);
		if ( ! wp_next_scheduled( 'rawatwp_daily_github_update_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rawatwp_daily_github_update_check' );
		}

		$logger->maybe_run_maintenance( 30, 10000, 3600 );

		$this->initialized = true;
	}

	/**
	 * Redirect admin to RawatWP General page after first-time activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation() {
		$should_redirect = get_option( Activator::OPTION_ACTIVATION_REDIRECT, '0' );
		if ( '1' !== (string) $should_redirect ) {
			return;
		}

		delete_option( Activator::OPTION_ACTIVATION_REDIRECT );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'rawatwp-general',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
