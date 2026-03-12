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
			return new \WP_Error( 'rawatwp_bad_site_data', __( 'Nama site dan URL wajib diisi.', 'rawatwp' ) );
		}

		if ( $this->database->get_site_by_url( $site_url ) ) {
			return new \WP_Error( 'rawatwp_site_exists', __( 'Site sudah terdaftar.', 'rawatwp' ) );
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
					'message'  => 'Gagal insert child site ke database.',
					'context'  => array(
						'db_error' => $db_error,
					),
				)
			);

			return new \WP_Error( 'rawatwp_site_insert_failed', __( 'Gagal menambahkan site child.', 'rawatwp' ) );
		}

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site_url,
				'action'   => 'site_preregistered',
				'status'   => 'success',
				'message'  => 'Child site didaftarkan (pre-register).',
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
			return new \WP_Error( 'rawatwp_site_not_found', __( 'Site tidak ditemukan.', 'rawatwp' ) );
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
			return new \WP_Error( 'rawatwp_key_regenerate_failed', __( 'Gagal regenerate key.', 'rawatwp' ) );
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
				'message'   => sprintf( 'Mulai push update %s:%s ke site %s.', $package['type'], $package['target_slug'], $site['site_name'] ),
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
					'message'   => 'Gagal push update: koneksi ke child bermasalah.',
					'context'   => array(
						'reason_code' => 'network_error',
						'detail'      => $message,
					),
				)
			);

			return array(
				'status'       => 'failed',
				'message'      => 'Gagal koneksi ke child site.',
				'http_code'    => 0,
				'is_transient' => true,
				'reason_code'  => 'network_error',
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$body      = json_decode( $raw_body, true );

		$status  = 'failed';
		$message = __( 'Tidak ada respons detail dari child.', 'rawatwp' );

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
			$message = __( 'Child mengalami error fatal saat proses update. Cek RawatWP Logs di child dan debug.log server child.', 'rawatwp' );
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
				'message'   => in_array( $status, array( 'update_success', 'success' ), true ) ? 'Push update selesai dengan sukses.' : 'Push update gagal.',
				'context'   => array(
					'http_code'    => $http_code,
					'reason_code'  => $reason_code,
					'detail'       => $message,
					'is_transient' => $is_transient,
				),
			)
		);

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
				'global_error' => __( 'Package tidak ditemukan.', 'rawatwp' ),
				'results'      => array(),
			);
		}

		if ( ! in_array( $package['type'], array( 'plugin', 'theme', 'core' ), true ) ) {
			return array(
				'global_error' => __( 'Type package ini tidak didukung. Patch installer sudah dinonaktifkan.', 'rawatwp' ),
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
					'message' => __( 'Site tidak ditemukan.', 'rawatwp' ),
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
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode bukan master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Payload JSON tidak valid.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Site belum pre-register.' ), 404 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $site['security_key'], 'register_' . $site['id'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$this->database->update_site(
			$site['id'],
			array(
				'site_name'          => isset( $packet['data']['site_name'] ) ? sanitize_text_field( $packet['data']['site_name'] ) : $site['site_name'],
				'connection_status'  => 'connected',
				'last_seen'          => current_time( 'mysql' ),
			)
		);

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'connected',
				'status'   => 'connected',
				'message'  => 'Child berhasil terkoneksi.',
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
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode bukan master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Payload JSON tidak valid.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Site child tidak dikenal.' ), 404 );
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

		$this->database->update_site(
			$site['id'],
			array(
				'connection_status' => 'connected',
				'last_seen'         => current_time( 'mysql' ),
				'last_report'       => array(
					'reported_at' => current_time( 'mysql' ),
					'items'       => $items,
				),
			)
		);

		$this->logger->log(
			array(
				'mode'     => 'master',
				'site_url' => $site['site_url'],
				'action'   => 'reported',
				'status'   => 'reported',
				'message'  => sprintf( 'Child melaporkan %d item butuh update.', count( $items ) ),
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
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode bukan master.' ), 403 );
		}

		$packet   = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Payload JSON tidak valid.' ), 400 );
		}

		$site_url = isset( $packet['data']['site_url'] ) ? $packet['data']['site_url'] : '';
		$site     = $this->database->get_site_by_url( $site_url );

		if ( ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Site child tidak dikenal.' ), 404 );
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

		$this->database->update_site(
			$site['id'],
			array(
				'connection_status' => 'connected',
				'last_seen'         => current_time( 'mysql' ),
			)
		);

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
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode bukan master.' ), 403 );
		}

		$package_id = (int) $request->get_param( 'package_id' );
		$child_id   = (int) $request->get_param( 'child_id' );
		$timestamp  = (int) $request->get_param( 'timestamp' );
		$nonce      = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		$signature  = sanitize_text_field( (string) $request->get_param( 'signature' ) );

		$package = $this->database->get_package_by_id( $package_id );
		$site    = $this->database->get_site_by_id( $child_id );

		if ( ! $package || ! $site ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Package/site tidak ditemukan.' ), 404 );
		}

		$verification = $this->security->verify_download_signature( $package_id, $child_id, $timestamp, $nonce, $signature, $site['security_key'] );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$file_path = $package['file_path'];
		if ( ! file_exists( $file_path ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'File package tidak ditemukan.' ), 404 );
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
}
