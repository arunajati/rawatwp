<?php
namespace RawatWP\Master;

use RawatWP\Core\Database;
use RawatWP\Core\Logger;
use RawatWP\Core\ModeManager;
use RawatWP\Core\SecurityManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MasterManager {
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
	 * Package manager.
	 *
	 * @var PackageManager
	 */
	private $package_manager;

	/**
	 * Mode manager.
	 *
	 * @var ModeManager
	 */
	private $mode_manager;

	/**
	 * Constructor.
	 *
	 * @param Database        $database Database.
	 * @param Logger          $logger Logger.
	 * @param SecurityManager $security Security manager.
	 * @param PackageManager  $package_manager Package manager.
	 * @param ModeManager     $mode_manager Mode manager.
	 */
	public function __construct( Database $database, Logger $logger, SecurityManager $security, PackageManager $package_manager, ModeManager $mode_manager ) {
		$this->database        = $database;
		$this->logger          = $logger;
		$this->security        = $security;
		$this->package_manager = $package_manager;
		$this->mode_manager    = $mode_manager;
	}

	/**
	 * Add child site (pre-register).
	 *
	 * @param string $site_name Child site name.
	 * @param string $site_url Child site URL.
	 * @param string $security_key Security key, empty to auto-generate.
	 * @return int|\WP_Error
	 */
	public function add_site( $site_name, $site_url, $security_key = '' ) {
		$site_name = sanitize_text_field( $site_name );
		$site_url  = $this->database->normalize_url( $site_url );

		if ( '' === $site_name || '' === $site_url ) {
			return new \WP_Error( 'rawatwp_bad_site_data', __( 'Site name and URL are required.', 'rawatwp' ) );
		}

		if ( $this->database->get_site_by_url( $site_url ) ) {
			return new \WP_Error( 'rawatwp_site_exists', __( 'Site is already registered.', 'rawatwp' ) );
		}

		$security_key = '' !== $security_key ? sanitize_text_field( $security_key ) : $this->security->generate_security_key();

		$site_id = $this->database->insert_site(
			array(
				'site_name'         => $site_name,
				'site_url'          => $site_url,
				'security_key'      => $security_key,
				'connection_status' => 'pending',
			)
		);

		if ( false === $site_id ) {
			$db_error = $this->database->get_last_error();
			$this->logger->log(
				array(
					'mode'     => 'master',
					'site_url' => $site_url,
					'action'   => 'site_preregistered',
					'status'   => 'failed',
					'message'  => 'Failed to insert child site into database.',
					'context'  => array(
						'db_error' => $db_error,
					),
				)
			);

			return new \WP_Error( 'rawatwp_site_insert_failed', __( 'Failed to add child site.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site_url,
				'action'   => 'site_preregistered',
				'status'   => 'success',
				'message'  => 'Child site pre-registered.',
			)
		);

		return $site_id;
	}

	/**
	 * Regenerate child key.
	 *
	 * @param int $site_id Site ID.
	 * @return string|\WP_Error
	 */
	public function regenerate_site_key( $site_id ) {
		$site = $this->database->get_site_by_id( $site_id );
		if ( ! $site ) {
			return new \WP_Error( 'rawatwp_site_not_found', __( 'Site not found.', 'rawatwp' ) );
		}

		$key = $this->security->generate_security_key();

		$updated = $this->database->update_site(
			$site_id,
			array(
				'security_key'      => $key,
				'connection_status' => 'pending',
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'rawatwp_key_regenerate_failed', __( 'Failed to regenerate key.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'key_regenerated',
				'status'   => 'success',
				'message'  => 'Security key child diganti.',
			)
		);

		return $key;
	}

	/**
	 * Get child sites.
	 *
	 * @return array
	 */
	public function get_sites() {
		return $this->database->get_sites();
	}

	/**
	 * Get updates summary by site.
	 *
	 * @return array
	 */
	public function get_sites_update_summary() {
		$sites = $this->database->get_sites();
		foreach ( $sites as &$site ) {
			$site['needs_update_items'] = array();
			if ( ! empty( $site['last_report']['items'] ) && is_array( $site['last_report']['items'] ) ) {
				$site['needs_update_items'] = $site['last_report']['items'];
			}
		}

		return $sites;
	}

	/**
	 * Request WordPress update snapshot from one child site.
	 *
	 * @param int $site_id Child site ID.
	 * @return array|\WP_Error
	 */
	public function request_site_updates_snapshot( $site_id ) {
		$site_id = (int) $site_id;
		$site    = $this->database->get_site_by_id( $site_id );
		if ( ! $site ) {
			return new \WP_Error( 'rawatwp_site_not_found', __( 'Site not found.', 'rawatwp' ) );
		}

		if ( 'connected' !== sanitize_key( (string) $site['connection_status'] ) ) {
			return new \WP_Error( 'rawatwp_site_not_connected', __( 'Child site is not connected.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'update_check_started',
				'status'   => 'processing',
				'message'  => 'Manual update check started for child site.',
			)
		);

		$request_data = array(
			'site_url'       => home_url(),
			'force_refresh'  => true,
			'request_source' => 'master_manual',
		);

		$response = $this->post_child_update_check_with_retry( $site, $request_data );

		if ( is_wp_error( $response ) ) {
			$detail = sanitize_text_field( $response->get_error_message() );
			$this->logger->log(
				array(
					'mode'     => 'master',
					'site_url' => $site['site_url'],
					'action'   => 'update_check_failed',
					'status'   => 'failed',
					'message'  => 'Update check failed: could not connect to child site.',
					'context'  => array(
						'detail' => $detail,
					),
				)
			);
			$this->persist_failed_site_update_snapshot( $site, $detail );

			return new \WP_Error( 'rawatwp_check_failed', __( 'Could not connect to child site.', 'rawatwp' ) );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $http_code < 200 || $http_code >= 300 || ! is_array( $body ) || empty( $body['status'] ) || 'ok' !== sanitize_key( (string) $body['status'] ) || empty( $body['updates'] ) || ! is_array( $body['updates'] ) ) {
			$detail = '';
			if ( is_array( $body ) && isset( $body['message'] ) ) {
				$detail = sanitize_text_field( (string) $body['message'] );
			}
			$this->logger->log(
				array(
					'mode'     => 'master',
					'site_url' => $site['site_url'],
					'action'   => 'update_check_failed',
					'status'   => 'failed',
					'message'  => 'Update check failed: child response was invalid.',
					'context'  => array(
						'http_code' => $http_code,
						'detail'    => $detail,
					),
				)
			);
			$this->persist_failed_site_update_snapshot( $site, '' !== $detail ? $detail : __( 'Child response is invalid.', 'rawatwp' ) );

			return new \WP_Error( 'rawatwp_check_invalid_response', __( 'Child response is invalid.', 'rawatwp' ) );
		}

		$snapshot = $this->sanitize_site_update_snapshot( (array) $body['updates'] );
		$report   = $this->merge_site_last_report(
			$site,
			array(
				'wp_update_check' => $snapshot,
			)
		);

		$update_fields = array(
			'connection_status' => 'connected',
			'last_seen'         => current_time( 'mysql' ),
			'last_report'       => $report,
		);

		$rawatwp_version = '';
		if ( isset( $body['rawatwp_version'] ) ) {
			$rawatwp_version = preg_replace( '/[^0-9A-Za-z.\-+]/', '', (string) $body['rawatwp_version'] );
			$rawatwp_version = sanitize_text_field( (string) $rawatwp_version );
		}
		if ( '' !== $rawatwp_version ) {
			$update_fields['rawatwp_version'] = $rawatwp_version;
		}

		$this->database->update_site( (int) $site['id'], $update_fields );

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'update_check_success',
				'status'   => 'success',
				'message'  => sprintf(
					'Update check completed. Core: %s, Themes: %d, Plugins: %d.',
					! empty( $snapshot['core']['needs_update'] ) ? 'needs update' : 'up to date',
					isset( $snapshot['counts']['themes'] ) ? (int) $snapshot['counts']['themes'] : 0,
					isset( $snapshot['counts']['plugins'] ) ? (int) $snapshot['counts']['plugins'] : 0
				),
				'context'  => $snapshot,
			)
		);

		return array(
			'site'     => $site,
			'snapshot' => $snapshot,
		);
	}

	/**
	 * Request WordPress update snapshots for many child sites.
	 *
	 * @param array $site_ids Site IDs.
	 * @return array
	 */
	public function request_site_updates_snapshot_batch( array $site_ids ) {
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $site_ids as $site_id ) {
			$result = $this->request_site_updates_snapshot( $site_id );
			if ( is_wp_error( $result ) ) {
				$site = $this->database->get_site_by_id( $site_id );
				$results['failed'][] = array(
					'site_id'   => $site_id,
					'site_name' => is_array( $site ) ? (string) $site['site_name'] : (string) $site_id,
					'message'   => $result->get_error_message(),
				);
				continue;
			}

			$results['success'][] = $result;
		}

		return $results;
	}

	/**
	 * Dispatch one package update command to one child site.
	 *
	 * @param array $package Package data.
	 * @param array $site Child site data.
	 * @return array
	 */
	public function dispatch_package_to_site( array $package, array $site ) {
		$download_url = $this->package_manager->build_download_url_for_site( $package, $site );
		$packet       = $this->security->build_signed_packet(
			array(
				'site_url'      => home_url(),
				'package_id'    => (int) $package['id'],
				'package_label' => $package['label'],
				'package_type'  => $package['type'],
				'target_slug'   => $package['target_slug'],
				'download_url'  => $download_url,
			),
			$site['security_key']
		);

		$endpoint = untrailingslashit( $site['site_url'] ) . '/wp-json/rawatwp/v1/child/apply-update';

		$this->logger->log(
			array(
				'mode'      => 'master',
				'site_url'  => $site['site_url'],
				'item_type' => $package['type'],
				'item_slug' => $package['target_slug'],
				'action'    => 'update_started',
				'status'    => 'update_started',
				'message'   => sprintf( 'Starting update push %s:%s to site %s.', $package['type'], $package['target_slug'], $site['site_name'] ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $packet ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = sanitize_text_field( $response->get_error_message() );
			$this->logger->log(
				array(
					'mode'      => 'master',
					'site_url'  => $site['site_url'],
					'item_type' => $package['type'],
					'item_slug' => $package['target_slug'],
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => 'Failed to push update: child connection issue.',
					'context'   => array(
						'reason_code' => 'network_error',
						'detail'      => $message,
					),
				)
			);

			return array(
				'status'       => 'failed',
				'message'      => 'Failed to connect to child site.',
				'http_code'    => 0,
				'is_transient' => true,
				'reason_code'  => 'network_error',
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$body      = json_decode( $raw_body, true );

		$status  = 'failed';
		$message = __( 'No detailed response from child.', 'rawatwp' );

		if ( is_array( $body ) ) {
			if ( isset( $body['status'] ) ) {
				$status = sanitize_key( (string) $body['status'] );
			}

			if ( isset( $body['message'] ) ) {
				$message = sanitize_text_field( (string) $body['message'] );
			}

			// WordPress REST standard error payload: {code, message, data:{status}}.
			if ( isset( $body['code'] ) && empty( $body['status'] ) ) {
				$status = 'failed';
				if ( ! empty( $body['message'] ) ) {
					$message = sanitize_text_field( (string) $body['message'] );
				}
			}
		} else {
			$snippet = sanitize_text_field( wp_strip_all_tags( $raw_body ) );
			if ( '' !== $snippet ) {
				$message = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 280 ) : substr( $snippet, 0, 280 );
			}
		}

		if ( $http_code >= 400 && 'update_success' === $status ) {
			$status = 'failed';
		}

		if ( $http_code >= 500 && false !== stripos( $message, 'critical error on this website' ) ) {
			$message = __( 'Child encountered a fatal error during update. Check RawatWP Logs and child server debug.log.', 'rawatwp' );
		}

		$is_transient = false;
		$reason_code  = 'update_failed';

		if ( $http_code >= 500 || 429 === $http_code || 408 === $http_code ) {
			$is_transient = true;
			$reason_code  = 'remote_temporary_error';
		} elseif ( $http_code >= 400 ) {
			$reason_code = 'remote_request_rejected';
		} elseif ( in_array( $status, array( 'rolled_back', 'rollback_failed', 'update_failed' ), true ) ) {
			$reason_code = 'child_update_failed';
		}

		if ( in_array( $status, array( 'update_success', 'success' ), true ) ) {
			$reason_code = 'ok';
		}

		$this->logger->log(
			array(
				'mode'      => 'master',
				'site_url'  => $site['site_url'],
				'item_type' => $package['type'],
				'item_slug' => $package['target_slug'],
				'action'    => 'update_result',
				'status'    => $status,
				'message'   => in_array( $status, array( 'update_success', 'success' ), true ) ? 'Update push completed successfully.' : 'Update push failed.',
				'context'   => array(
					'http_code'    => $http_code,
					'reason_code'  => $reason_code,
					'detail'       => $message,
					'is_transient' => $is_transient,
				),
			)
		);

		if ( in_array( $status, array( 'update_success', 'success' ), true ) && 'plugin' === (string) $package['type'] && 'rawatwp' === sanitize_key( (string) $package['target_slug'] ) ) {
			$this->database->update_site(
				(int) $site['id'],
				array(
					'rawatwp_version' => defined( 'RAWATWP_VERSION' ) ? (string) RAWATWP_VERSION : '',
					'last_seen'       => current_time( 'mysql' ),
				)
			);
		}

		return array(
			'status'       => $status,
			'message'      => $message,
			'http_code'    => $http_code,
			'is_transient' => $is_transient,
			'reason_code'  => $reason_code,
		);
	}

	/**
	 * Push selected package to child sites.
	 *
	 * @param int   $package_id Package ID.
	 * @param array $site_ids Site IDs.
	 * @return array
	 */
	public function push_package_to_sites( $package_id, array $site_ids ) {
		$package = $this->package_manager->get_package( $package_id );
		if ( ! $package ) {
			return array(
				'global_error' => __( 'Package not found.', 'rawatwp' ),
				'results'      => array(),
			);
		}

		if ( ! in_array( $package['type'], array( 'plugin', 'theme', 'core' ), true ) ) {
			return array(
				'global_error' => __( 'This package type is not supported. Patch installer is disabled.', 'rawatwp' ),
				'results'      => array(),
			);
		}

		$results = array();

		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			$site    = $this->database->get_site_by_id( $site_id );

			if ( ! $site ) {
				$results[] = array(
					'site_id' => $site_id,
					'status'  => 'failed',
					'message' => __( 'Site not found.', 'rawatwp' ),
				);
				continue;
			}

			$dispatch = $this->dispatch_package_to_site( $package, $site );
			$results[] = array(
				'site_id'   => $site_id,
				'site_name' => $site['site_name'],
				'status'    => $dispatch['status'],
				'message'   => $dispatch['message'],
			);
		}

		return array(
			'results' => $results,
		);
	}

	/**
	 * REST: register child.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_register( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Site is not pre-registered yet.' ), 404 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $site['security_key'], 'register_' . $site['id'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$update_fields = array(
			'site_name'         => isset( $packet['data']['site_name'] ) ? sanitize_text_field( $packet['data']['site_name'] ) : $site['site_name'],
			'connection_status' => 'connected',
			'last_seen'         => current_time( 'mysql' ),
		);
		$rawatwp_version = $this->extract_packet_rawatwp_version( $packet );
		if ( '' !== $rawatwp_version ) {
			$update_fields['rawatwp_version'] = $rawatwp_version;
		}

		$this->database->update_site( $site['id'], $update_fields );

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'connected',
				'status'   => 'connected',
				'message'  => 'Child connected successfully.',
			)
		);

		return new \WP_REST_Response(
			array(
				'status'   => 'connected',
				'message'  => 'Connected.',
				'child_id' => (int) $site['id'],
			),
			200
		);
	}

	/**
	 * REST: receive child update report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_report( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Unknown child site.' ), 404 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $site['security_key'], 'report_' . $site['id'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$items = array();
		if ( isset( $packet['data']['items'] ) && is_array( $packet['data']['items'] ) ) {
			foreach ( $packet['data']['items'] as $item ) {
				if ( empty( $item['slug'] ) || empty( $item['type'] ) ) {
					continue;
				}

				$items[] = array(
					'type'            => sanitize_key( $item['type'] ),
					'slug'            => sanitize_title( $item['slug'] ),
					'label'           => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
					'current_version' => isset( $item['current_version'] ) ? sanitize_text_field( $item['current_version'] ) : '',
					'needs_update'    => ! empty( $item['needs_update'] ),
				);
			}
		}

		$update_fields = array(
			'connection_status' => 'connected',
			'last_seen'         => current_time( 'mysql' ),
			'last_report'       => $this->merge_site_last_report(
				$site,
				array(
					'reported_at' => current_time( 'mysql' ),
					'items'       => $items,
				)
			),
		);
		$rawatwp_version = $this->extract_packet_rawatwp_version( $packet );
		if ( '' !== $rawatwp_version ) {
			$update_fields['rawatwp_version'] = $rawatwp_version;
		}

		$this->database->update_site( $site['id'], $update_fields );

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'reported',
				'status'   => 'reported',
				'message'  => sprintf( 'Child reported %d item(s) needing updates.', count( $items ) ),
				'context'  => array( 'items' => $items ),
			)
		);

		return new \WP_REST_Response(
			array(
				'status'  => 'reported',
				'message' => 'Report accepted.',
			),
			200
		);
	}

	/**
	 * REST: receive child logs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_log( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Unknown child site.' ), 404 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $site['security_key'], 'log_' . $site['id'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$events = array();
		if ( isset( $packet['data']['events'] ) && is_array( $packet['data']['events'] ) ) {
			$events = $packet['data']['events'];
		}

		foreach ( $events as $event ) {
			$this->logger->log(
				array(
					'site_url'  => $site['site_url'],
					'mode'      => 'child',
					'item_type' => isset( $event['item_type'] ) ? sanitize_key( $event['item_type'] ) : '',
					'item_slug' => isset( $event['item_slug'] ) ? sanitize_title( $event['item_slug'] ) : '',
					'action'    => isset( $event['action'] ) ? sanitize_key( $event['action'] ) : 'event',
					'status'    => isset( $event['status'] ) ? sanitize_key( $event['status'] ) : 'info',
					'message'   => isset( $event['message'] ) ? sanitize_text_field( $event['message'] ) : '',
					'context'   => isset( $event['context'] ) ? (array) $event['context'] : array(),
				)
			);
		}

		$update_fields = array(
			'connection_status' => 'connected',
			'last_seen'         => current_time( 'mysql' ),
		);
		$rawatwp_version = $this->extract_packet_rawatwp_version( $packet );
		if ( '' !== $rawatwp_version ) {
			$update_fields['rawatwp_version'] = $rawatwp_version;
		}

		$this->database->update_site( $site['id'], $update_fields );

		return new \WP_REST_Response(
			array(
				'status'  => 'ok',
				'message' => 'Log accepted.',
			),
			200
		);
	}

	/**
	 * REST: package download for child.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void|\WP_REST_Response
	 */
	public function rest_package_download( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_master() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not master.' ), 403 );
		}

		$package_id = (int) $request->get_param( 'package_id' );
		$child_id   = (int) $request->get_param( 'child_id' );
		$timestamp  = (int) $request->get_param( 'timestamp' );
		$nonce      = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		$signature  = sanitize_text_field( (string) $request->get_param( 'signature' ) );

		$package = $this->database->get_package_by_id( $package_id );
		$site    = $this->database->get_site_by_id( $child_id );

		if ( ! $package || ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Package/site not found.' ), 404 );
		}

		$verification = $this->security->verify_download_signature( $package_id, $child_id, $timestamp, $nonce, $signature, $site['security_key'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$file_path = $package['file_path'];
		if ( ! file_exists( $file_path ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Package file not found.' ), 404 );
		}

		$this->database->update_site(
			$site['id'],
			array(
				'last_seen' => current_time( 'mysql' ),
			)
		);

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $file_path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path );
		exit;
	}

	/**
	 * Extract child RawatWP version from signed packet.
	 *
	 * @param array $packet Signed packet payload.
	 * @return string
	 */
	private function extract_packet_rawatwp_version( array $packet ) {
		$version = '';
		if ( isset( $packet['data']['rawatwp_version'] ) ) {
			$version = (string) $packet['data']['rawatwp_version'];
		}

		$version = preg_replace( '/[^0-9A-Za-z.\-+]/', '', $version );
		return sanitize_text_field( (string) $version );
	}

	/**
	 * Merge last_report data safely.
	 *
	 * @param array $site Site row.
	 * @param array $new_data Data to merge.
	 * @return array
	 */
	private function merge_site_last_report( array $site, array $new_data ) {
		$last_report = array();
		if ( isset( $site['last_report'] ) && is_array( $site['last_report'] ) ) {
			$last_report = $site['last_report'];
		}

		foreach ( $new_data as $key => $value ) {
			$last_report[ sanitize_key( (string) $key ) ] = $value;
		}

		return $last_report;
	}

	/**
	 * Sanitize site update snapshot payload from child.
	 *
	 * @param array $snapshot Raw snapshot.
	 * @return array
	 */
	private function sanitize_site_update_snapshot( array $snapshot ) {
		$status        = isset( $snapshot['status'] ) ? sanitize_key( (string) $snapshot['status'] ) : 'success';
		$error_message = isset( $snapshot['error_message'] ) ? sanitize_text_field( (string) $snapshot['error_message'] ) : '';
		$core = isset( $snapshot['core'] ) && is_array( $snapshot['core'] ) ? $snapshot['core'] : array();
		$core = array(
			'needs_update'    => ! empty( $core['needs_update'] ),
			'current_version' => isset( $core['current_version'] ) ? sanitize_text_field( (string) $core['current_version'] ) : '',
			'latest_version'  => isset( $core['latest_version'] ) ? sanitize_text_field( (string) $core['latest_version'] ) : '',
		);

		$themes = array();
		if ( isset( $snapshot['themes'] ) && is_array( $snapshot['themes'] ) ) {
			foreach ( $snapshot['themes'] as $theme ) {
				if ( ! is_array( $theme ) ) {
					continue;
				}
				$slug = isset( $theme['slug'] ) ? sanitize_key( (string) $theme['slug'] ) : '';
				if ( '' === $slug ) {
					continue;
				}

				$themes[] = array(
					'slug'            => $slug,
					'name'            => isset( $theme['name'] ) ? sanitize_text_field( (string) $theme['name'] ) : $slug,
					'current_version' => isset( $theme['current_version'] ) ? sanitize_text_field( (string) $theme['current_version'] ) : '',
					'new_version'     => isset( $theme['new_version'] ) ? sanitize_text_field( (string) $theme['new_version'] ) : '',
				);
			}
		}

		$plugins = array();
		if ( isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ) {
			foreach ( $snapshot['plugins'] as $plugin ) {
				if ( ! is_array( $plugin ) ) {
					continue;
				}
				$slug = isset( $plugin['slug'] ) ? sanitize_key( (string) $plugin['slug'] ) : '';
				if ( '' === $slug ) {
					continue;
				}

				$plugins[] = array(
					'slug'            => $slug,
					'name'            => isset( $plugin['name'] ) ? sanitize_text_field( (string) $plugin['name'] ) : $slug,
					'current_version' => isset( $plugin['current_version'] ) ? sanitize_text_field( (string) $plugin['current_version'] ) : '',
					'new_version'     => isset( $plugin['new_version'] ) ? sanitize_text_field( (string) $plugin['new_version'] ) : '',
				);
			}
		}

		$counts = array(
			'core'    => ! empty( $core['needs_update'] ) ? 1 : 0,
			'themes'  => count( $themes ),
			'plugins' => count( $plugins ),
			'total'   => ( ! empty( $core['needs_update'] ) ? 1 : 0 ) + count( $themes ) + count( $plugins ),
		);

		if ( isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ) {
			if ( isset( $snapshot['counts']['core'] ) ) {
				$counts['core'] = max( 0, min( 1, (int) $snapshot['counts']['core'] ) );
			}
			if ( isset( $snapshot['counts']['themes'] ) ) {
				$counts['themes'] = max( 0, (int) $snapshot['counts']['themes'] );
			}
			if ( isset( $snapshot['counts']['plugins'] ) ) {
				$counts['plugins'] = max( 0, (int) $snapshot['counts']['plugins'] );
			}
			$counts['total'] = $counts['core'] + $counts['themes'] + $counts['plugins'];
		}

		$checked_at = isset( $snapshot['checked_at'] ) ? sanitize_text_field( (string) $snapshot['checked_at'] ) : current_time( 'mysql' );

		return array(
			'checked_at'     => $checked_at,
			'status'         => in_array( $status, array( 'success', 'failed' ), true ) ? $status : 'success',
			'error_message'  => $error_message,
			'core'           => $core,
			'themes'         => $themes,
			'plugins'        => $plugins,
			'counts'         => $counts,
		);
	}

	/**
	 * Persist failed update-check snapshot so UI shows clear reason instead of "Not checked yet".
	 *
	 * @param array  $site   Site row.
	 * @param string $reason Human-readable failure reason.
	 * @return void
	 */
	private function persist_failed_site_update_snapshot( array $site, $reason ) {
		$reason = sanitize_text_field( (string) $reason );
		if ( false !== stripos( $reason, 'No route was found matching the URL and request method.' ) ) {
			$reason = 'Child site still uses an older RawatWP version. Run "Update RawatWP on All Sites" first, then check again.';
		}
		if ( '' === $reason ) {
			$reason = __( 'Unable to contact child site for update check.', 'rawatwp' );
		}

		$snapshot = array(
			'checked_at'    => current_time( 'mysql' ),
			'status'        => 'failed',
			'error_message' => $reason,
			'core'          => array(
				'needs_update'    => false,
				'current_version' => '',
				'latest_version'  => '',
			),
			'themes'        => array(),
			'plugins'       => array(),
			'counts'        => array(
				'core'    => 0,
				'themes'  => 0,
				'plugins' => 0,
				'total'   => 0,
			),
		);

		$report = $this->merge_site_last_report(
			$site,
			array(
				'wp_update_check' => $snapshot,
			)
		);

		$this->database->update_site(
			(int) $site['id'],
			array(
				'last_seen'   => current_time( 'mysql' ),
				'last_report' => $report,
			)
		);
	}

	/**
	 * Send child update-check request with transient retry policy.
	 *
	 * @param array  $site         Site row.
	 * @param string $endpoint     Child endpoint.
	 * @param array  $request_data Signed payload data.
	 * @return array|\WP_Error
	 */
	private function post_child_update_check_with_retry( array $site, array $request_data ) {
		$max_attempts = 3;
		$last_error   = null;
		$endpoints    = $this->get_child_update_check_endpoints( $site );
		$no_route_hit = false;

		foreach ( $endpoints as $endpoint ) {
			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$packet = $this->security->build_signed_packet( $request_data, $site['security_key'] );
				$result = wp_remote_post(
					$endpoint,
					array(
						'timeout'         => 40,
						'connect_timeout' => 8,
						'redirection'     => 3,
						'headers'         => array(
							'Content-Type'      => 'application/json',
							'X-RawatWP-Request' => 'site-update-check',
						),
						'body'            => wp_json_encode( $packet ),
					)
				);

				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					if ( $attempt < $max_attempts && $this->is_transient_http_error( 0, $result->get_error_message() ) ) {
						sleep( 1 );
						continue;
					}
					break;
				}

				$http_code = (int) wp_remote_retrieve_response_code( $result );
				$body      = json_decode( (string) wp_remote_retrieve_body( $result ), true );
				$is_no_route = ( 404 === $http_code && is_array( $body ) && isset( $body['code'] ) && 'rest_no_route' === sanitize_key( (string) $body['code'] ) );
				if ( $is_no_route ) {
					$no_route_hit = true;
					break;
				}

				if ( $attempt < $max_attempts && $this->is_transient_http_error( $http_code, '' ) ) {
					sleep( 1 );
					continue;
				}

				return $result;
			}
		}

		if ( $no_route_hit ) {
			return new \WP_Error(
				'rawatwp_check_no_route',
				__( 'Child update-check endpoint is not available. Update RawatWP on child site first, then run check again.', 'rawatwp' )
			);
		}

		return $last_error instanceof \WP_Error ? $last_error : new \WP_Error( 'rawatwp_check_failed', __( 'Could not connect to child site.', 'rawatwp' ) );
	}

	/**
	 * Decide whether an HTTP/network failure is transient and worth retrying.
	 *
	 * @param int    $http_code HTTP status code.
	 * @param string $error_message WP error message.
	 * @return bool
	 */
	private function is_transient_http_error( $http_code, $error_message ) {
		$http_code = (int) $http_code;
		if ( in_array( $http_code, array( 0, 408, 425, 429, 500, 502, 503, 504, 522, 524 ), true ) ) {
			return true;
		}

		$error_message = strtolower( sanitize_text_field( (string) $error_message ) );
		if ( '' === $error_message ) {
			return false;
		}

		$transient_markers = array(
			'timed out',
			'timeout',
			'connection reset',
			'temporary',
			'could not resolve host',
			'failed to connect',
			'connection refused',
		);

		foreach ( $transient_markers as $marker ) {
			if ( false !== strpos( $error_message, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build fallback REST endpoint candidates for child update-check.
	 *
	 * @param array $site Child site row.
	 * @return array
	 */
	private function get_child_update_check_endpoints( array $site ) {
		$base = isset( $site['site_url'] ) ? untrailingslashit( (string) $site['site_url'] ) : '';
		if ( '' === $base ) {
			return array();
		}

		return array_values(
			array_unique(
				array(
					$base . '/wp-json/rawatwp/v1/child/check-updates',
					$base . '/index.php?rest_route=/rawatwp/v1/child/check-updates',
					$base . '/?rest_route=/rawatwp/v1/child/check-updates',
				)
			)
		);
	}
}
