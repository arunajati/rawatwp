<?php
namespace RawatWP\Core;

use RawatWP\Child\ChildManager;
use RawatWP\Master\QueueManager;
use RawatWP\Master\MasterManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ApiManager {
	/**
	 * Mode manager.
	 *
	 * @var ModeManager
	 */
	private $mode_manager;

	/**
	 * Master manager.
	 *
	 * @var MasterManager
	 */
	private $master_manager;

	/**
	 * Child manager.
	 *
	 * @var ChildManager
	 */
	private $child_manager;

	/**
	 * Queue manager.
	 *
	 * @var QueueManager
	 */
	private $queue_manager;

	/**
	 * Security manager.
	 *
	 * @var SecurityManager
	 */
	private $security_manager;

	/**
	 * Constructor.
	 *
	 * @param ModeManager  $mode_manager Mode manager.
	 * @param MasterManager $master_manager Master manager.
	 * @param ChildManager    $child_manager Child manager.
	 * @param QueueManager    $queue_manager Queue manager.
	 * @param SecurityManager $security_manager Security manager.
	 */
	public function __construct( ModeManager $mode_manager, MasterManager $master_manager, ChildManager $child_manager, QueueManager $queue_manager, SecurityManager $security_manager ) {
		$this->mode_manager    = $mode_manager;
		$this->master_manager  = $master_manager;
		$this->child_manager   = $child_manager;
		$this->queue_manager   = $queue_manager;
		$this->security_manager = $security_manager;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'rawatwp/v1',
			'/master/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->master_manager, 'rest_register' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/master/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->master_manager, 'rest_report' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/master/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->master_manager, 'rest_log' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/master/package-download/(?P<package_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->master_manager, 'rest_package_download' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/child/apply-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->child_manager, 'rest_apply_update' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/child/check-updates',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->child_manager, 'rest_check_updates' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/master/queue-run',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_queue_run' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'rawatwp/v1',
			'/master/queue-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_queue_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST: run queue worker by secure token (for system cron).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_queue_run( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$token = (string) $request->get_header( 'x-rawatwp-token' );
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		$valid = $this->security_manager->verify_queue_runner_token( $token );
		if ( is_wp_error( $valid ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $valid->get_error_message() ), 403 );
		}

		$limit  = max( 1, min( 5, (int) $request->get_param( 'limit' ) ) );
		$result = $this->queue_manager->run_worker( 'system_cron', $limit );

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'result' => $result,
			),
			200
		);
	}

	/**
	 * REST: queue status by secure token (for external monitor).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_queue_status( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$token = (string) $request->get_header( 'x-rawatwp-token' );
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		$valid = $this->security_manager->verify_queue_runner_token( $token );
		if ( is_wp_error( $valid ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $valid->get_error_message() ), 403 );
		}

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'counts' => $this->queue_manager->get_queue_counts(),
			),
			200
		);
	}
}
