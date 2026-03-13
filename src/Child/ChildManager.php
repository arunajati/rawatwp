<?php
namespace RawatWP\Child;

use RawatWP\Core\Database;
use RawatWP\Core\Logger;
use RawatWP\Core\ModeManager;
use RawatWP\Core\SecurityManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChildManager {
	/**
	 * Settings option key.
	 */
	const OPTION_CHILD_SETTINGS = 'rawatwp_child_settings';

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
	 * Mode manager.
	 *
	 * @var ModeManager
	 */
	private $mode_manager;

	/**
	 * Monitored items manager.
	 *
	 * @var MonitoredItemsManager
	 */
	private $monitored_items;

	/**
	 * Update engine.
	 *
	 * @var UpdateEngine
	 */
	private $update_engine;

	/**
	 * Constructor.
	 *
	 * @param Database              $database Database.
	 * @param Logger                $logger Logger.
	 * @param SecurityManager       $security Security manager.
	 * @param ModeManager           $mode_manager Mode manager.
	 * @param MonitoredItemsManager $monitored_items Monitored items.
	 * @param UpdateEngine          $update_engine Update engine.
	 */
	public function __construct( Database $database, Logger $logger, SecurityManager $security, ModeManager $mode_manager, MonitoredItemsManager $monitored_items, UpdateEngine $update_engine ) {
		$this->database        = $database;
		$this->logger          = $logger;
		$this->security        = $security;
		$this->mode_manager    = $mode_manager;
		$this->monitored_items = $monitored_items;
		$this->update_engine   = $update_engine;
	}

	/**
	 * Get child settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = get_option( self::OPTION_CHILD_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'master_url' => '',
				'security_key' => '',
				'connected' => false,
				'child_id' => 0,
				'last_connected_at' => '',
			)
		);
	}

	/**
	 * Save child connection settings.
	 *
	 * @param string $master_url Master URL.
	 * @param string $security_key Security key.
	 * @return bool
	 */
	public function save_settings( $master_url, $security_key ) {
		$settings = $this->get_settings();

		$settings['master_url']   = esc_url_raw( trim( (string) $master_url ) );
		$settings['security_key'] = sanitize_text_field( $security_key );
		$settings['connected']    = false;
		$settings['child_id']     = 0;

		return (bool) update_option( self::OPTION_CHILD_SETTINGS, $settings, false );
	}

	/**
	 * Connect child to master register endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function connect_to_master() {
		$settings = $this->get_settings();

		if ( '' === $settings['master_url'] || '' === $settings['security_key'] ) {
			return new \WP_Error( 'rawatwp_missing_child_connection', __( 'Master URL and security key are required.', 'rawatwp' ) );
		}

		$endpoint = untrailingslashit( $settings['master_url'] ) . '/wp-json/rawatwp/v1/master/register';
		$packet   = $this->security->build_signed_packet(
			array(
				'site_name'       => get_bloginfo( 'name' ),
				'site_url'        => home_url(),
				'rawatwp_version' => $this->get_local_rawatwp_version(),
			),
			$settings['security_key']
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $packet ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['status'] ) || 'connected' !== $body['status'] ) {
			$message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'Failed to connect to Master.', 'rawatwp' );
			return new \WP_Error( 'rawatwp_connect_failed', $message );
		}

		$settings['connected']         = true;
		$settings['child_id']          = isset( $body['child_id'] ) ? (int) $body['child_id'] : 0;
		$settings['last_connected_at'] = current_time( 'mysql' );

		update_option( self::OPTION_CHILD_SETTINGS, $settings, false );

		$this->logger->log(
			array(
				'mode'     => 'child',
				'site_url' => home_url(),
				'action'   => 'connected',
				'status'   => 'connected',
				'message'  => 'Child connected to master.',
			)
		);

		return $settings;
	}

	/**
	 * Disconnect child from master (local state).
	 *
	 * @return bool
	 */
	public function disconnect() {
		$settings = $this->get_settings();

		$settings['connected'] = false;
		$settings['child_id']  = 0;

		$saved = (bool) update_option( self::OPTION_CHILD_SETTINGS, $settings, false );
		if ( $saved ) {
			$this->logger->log(
				array(
					'mode'     => 'child',
					'site_url' => home_url(),
					'action'   => 'disconnected',
					'status'   => 'disconnected',
					'message'  => 'Child disconnected from master (local).',
				)
			);
		}

		return $saved;
	}

	/**
	 * Send monitored items marked as needs_update.
	 *
	 * @return true|\WP_Error
	 */
	public function send_report_to_master() {
		$settings = $this->get_settings();

		if ( '' === $settings['master_url'] || '' === $settings['security_key'] ) {
			return new \WP_Error( 'rawatwp_not_configured', __( 'Child configuration is incomplete.', 'rawatwp' ) );
		}

		$items = $this->monitored_items->get_needs_update_items();

		$packet = $this->security->build_signed_packet(
			array(
				'site_name'       => get_bloginfo( 'name' ),
				'site_url'        => home_url(),
				'rawatwp_version' => $this->get_local_rawatwp_version(),
				'items'           => $items,
			),
			$settings['security_key']
		);

		$endpoint = untrailingslashit( $settings['master_url'] ) . '/wp-json/rawatwp/v1/master/report';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $packet ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['status'] ) || 'reported' !== $body['status'] ) {
			$message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'Failed to send report to master.', 'rawatwp' );
			return new \WP_Error( 'rawatwp_report_failed', $message );
		}

		$this->logger->log(
			array(
				'mode'     => 'child',
				'site_url' => home_url(),
				'action'   => 'reported',
				'status'   => 'reported',
				'message'  => sprintf( 'Needs-update report sent (%d item(s)).', count( $items ) ),
			)
		);

		return true;
	}

	/**
	 * Send update events to master log endpoint.
	 *
	 * @param array $events Events list.
	 * @return true|\WP_Error
	 */
	public function send_events_to_master( array $events ) {
		$settings = $this->get_settings();

		if ( '' === $settings['master_url'] || '' === $settings['security_key'] ) {
			return new \WP_Error( 'rawatwp_not_configured', __( 'Child configuration is incomplete.', 'rawatwp' ) );
		}

		$packet = $this->security->build_signed_packet(
			array(
				'site_url'        => home_url(),
				'rawatwp_version' => $this->get_local_rawatwp_version(),
				'events'          => $events,
			),
			$settings['security_key']
		);

		$endpoint = untrailingslashit( $settings['master_url'] ) . '/wp-json/rawatwp/v1/master/log';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $packet ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['status'] ) || 'ok' !== $body['status'] ) {
			$message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'Failed to send logs to master.', 'rawatwp' );
			return new \WP_Error( 'rawatwp_log_failed', $message );
		}

		return true;
	}

	/**
	 * REST: apply update command from master.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_apply_update( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_child() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not child.' ), 403 );
		}

		$settings = $this->get_settings();
		if ( '' === $settings['security_key'] ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Child security key is not set.' ), 400 );
		}

		$packet       = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $settings['security_key'], 'child_apply_update' );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$data = isset( $packet['data'] ) && is_array( $packet['data'] ) ? $packet['data'] : array();

		$target_type = isset( $data['package_type'] ) ? sanitize_key( $data['package_type'] ) : '';
		$target_slug = isset( $data['target_slug'] ) ? sanitize_key( $data['target_slug'] ) : '';
		if ( 'core' === $target_type && '' === $target_slug ) {
			$target_slug = 'wordpress-core';
		}

		$item        = $this->monitored_items->get_item_by_type_slug( $target_type, $target_slug );

		if ( ! $item ) {
			if ( in_array( $target_type, array( 'plugin', 'theme', 'core' ), true ) ) {
				$item = array(
					'id'              => '',
					'type'            => $target_type,
					'slug'            => '' !== $target_slug ? $target_slug : sanitize_key( (string) ( $data['package_label'] ?? 'package' ) ),
					'label'           => isset( $data['package_label'] ) ? sanitize_text_field( $data['package_label'] ) : $target_slug,
					'current_version' => '',
					'needs_update'    => true,
				);
			} else {
				$message = 'Target not found in child monitored items.';
				$this->logger->log(
					array(
						'mode'      => 'child',
						'item_type' => $target_type,
						'item_slug' => $target_slug,
						'action'    => 'update_failed',
						'status'    => 'update_failed',
						'message'   => $message,
					)
				);

				$events = array(
					array(
						'item_type' => $target_type,
						'item_slug' => $target_slug,
						'action'    => 'update_failed',
						'status'    => 'update_failed',
						'message'   => $message,
						'context'   => array(),
					),
				);
				$this->send_events_to_master( $events );

				return new \WP_REST_Response( array( 'status' => 'update_failed', 'message' => $message ), 400 );
			}
		}

		try {
			$result = $this->update_engine->apply_update( $data, $item );
		} catch ( \Throwable $throwable ) {
			$message = sprintf( 'Child update exception: %s', $throwable->getMessage() );
			$this->logger->log(
				array(
					'mode'      => 'child',
					'item_type' => $target_type,
					'item_slug' => $target_slug,
					'action'    => 'update_failed',
					'status'    => 'update_failed',
					'message'   => $message,
					'context'   => array(
						'exception_class' => get_class( $throwable ),
					),
				)
			);

			$this->send_events_to_master(
				array(
					array(
						'item_type' => $target_type,
						'item_slug' => $target_slug,
						'action'    => 'update_failed',
						'status'    => 'update_failed',
						'message'   => $message,
						'context'   => array(
							'exception_class' => get_class( $throwable ),
						),
					),
				)
			);

			return new \WP_REST_Response(
				array(
					'status'  => 'update_failed',
					'message' => $message,
				),
				500
			);
		}
		$this->send_events_to_master( isset( $result['events'] ) ? (array) $result['events'] : array() );

		if ( 'update_success' === $result['status'] && ! empty( $item['id'] ) ) {
			$this->monitored_items->set_needs_update( $item['id'], false );
		}

		return new \WP_REST_Response(
			array(
				'status'  => $result['status'],
				'message' => $result['message'],
			),
			'update_success' === $result['status'] ? 200 : 500
		);
	}

	/**
	 * REST: check available WordPress updates on child site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_check_updates( \WP_REST_Request $request ) {
		if ( ! $this->mode_manager->is_child() ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Mode is not child.' ), 403 );
		}

		$settings = $this->get_settings();
		if ( '' === $settings['security_key'] ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Child security key is not set.' ), 400 );
		}

		$packet = $request->get_json_params();
		if ( ! is_array( $packet ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => 'Invalid JSON payload.' ), 400 );
		}

		$verification = $this->security->verify_signed_packet( $packet, $settings['security_key'], 'child_check_updates' );
		if ( is_wp_error( $verification ) ) {
			return new \WP_REST_Response( array( 'status' => 'failed', 'message' => $verification->get_error_message() ), 403 );
		}

		$data          = isset( $packet['data'] ) && is_array( $packet['data'] ) ? $packet['data'] : array();
		$force_refresh = ! empty( $data['force_refresh'] );
		$snapshot      = $this->collect_wp_updates_snapshot( $force_refresh );

		if ( is_wp_error( $snapshot ) ) {
			$this->logger->log(
				array(
					'mode'     => 'child',
					'site_url' => home_url(),
					'action'   => 'update_check_failed',
					'status'   => 'failed',
					'message'  => 'Manual update check failed on child site.',
					'context'  => array(
						'detail' => $snapshot->get_error_message(),
					),
				)
			);

			return new \WP_REST_Response(
				array(
					'status'  => 'failed',
					'message' => 'Failed to check available updates on child site.',
				),
				500
			);
		}

		$this->logger->log(
			array(
				'mode'     => 'child',
				'site_url' => home_url(),
				'action'   => 'update_check_success',
				'status'   => 'success',
				'message'  => sprintf(
					'Manual update check completed. Core: %s, Themes: %d, Plugins: %d.',
					! empty( $snapshot['core']['needs_update'] ) ? 'needs update' : 'up to date',
					isset( $snapshot['counts']['themes'] ) ? (int) $snapshot['counts']['themes'] : 0,
					isset( $snapshot['counts']['plugins'] ) ? (int) $snapshot['counts']['plugins'] : 0
				),
			)
		);

		return new \WP_REST_Response(
			array(
				'status'          => 'ok',
				'message'         => 'Update check completed.',
				'rawatwp_version' => $this->get_local_rawatwp_version(),
				'updates'         => $snapshot,
			),
			200
		);
	}

	/**
	 * Get local RawatWP version.
	 *
	 * @return string
	 */
	private function get_local_rawatwp_version() {
		$version = defined( 'RAWATWP_VERSION' ) ? (string) RAWATWP_VERSION : '';
		$version = preg_replace( '/[^0-9A-Za-z.\-+]/', '', $version );

		return sanitize_text_field( (string) $version );
	}

	/**
	 * Collect update availability snapshot for core/themes/plugins.
	 *
	 * @param bool $force_refresh True to force refresh from WordPress update source.
	 * @return array|\WP_Error
	 */
	private function collect_wp_updates_snapshot( $force_refresh = true ) {
		if ( ! function_exists( 'wp_update_plugins' ) || ! function_exists( 'wp_update_themes' ) || ! function_exists( 'wp_version_check' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $force_refresh ) {
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();
		}

		$current_wp_version = sanitize_text_field( (string) get_bloginfo( 'version' ) );
		$core_updates       = function_exists( 'get_core_updates' ) ? get_core_updates( array( 'dismissed' => false ) ) : array();
		$core_needs_update  = false;
		$core_latest        = $current_wp_version;
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $core_update ) {
				if ( ! is_object( $core_update ) ) {
					continue;
				}
				$response = isset( $core_update->response ) ? sanitize_key( (string) $core_update->response ) : '';
				$version  = isset( $core_update->current ) ? sanitize_text_field( (string) $core_update->current ) : '';
				if ( 'upgrade' === $response && '' !== $version ) {
					$core_needs_update = true;
					$core_latest       = $version;
					break;
				}
			}
		}

		$plugin_updates = array();
		$plugins_data   = get_plugins();
		$plugin_tx      = get_site_transient( 'update_plugins' );
		$plugin_resp    = ( is_object( $plugin_tx ) && isset( $plugin_tx->response ) && is_array( $plugin_tx->response ) ) ? $plugin_tx->response : array();

		foreach ( $plugin_resp as $plugin_file => $info ) {
			$plugin_file = sanitize_text_field( (string) $plugin_file );
			$slug        = '';
			$new_version = '';

			if ( is_object( $info ) ) {
				$slug        = isset( $info->slug ) ? sanitize_key( (string) $info->slug ) : '';
				$new_version = isset( $info->new_version ) ? sanitize_text_field( (string) $info->new_version ) : '';
			} elseif ( is_array( $info ) ) {
				$slug        = isset( $info['slug'] ) ? sanitize_key( (string) $info['slug'] ) : '';
				$new_version = isset( $info['new_version'] ) ? sanitize_text_field( (string) $info['new_version'] ) : '';
			}

			if ( '' === $slug ) {
				$folder_slug = dirname( $plugin_file );
				$slug        = '.' !== $folder_slug ? sanitize_key( (string) $folder_slug ) : sanitize_key( basename( $plugin_file, '.php' ) );
			}

			$current_version = '';
			$name            = '';
			if ( isset( $plugins_data[ $plugin_file ] ) && is_array( $plugins_data[ $plugin_file ] ) ) {
				$current_version = isset( $plugins_data[ $plugin_file ]['Version'] ) ? sanitize_text_field( (string) $plugins_data[ $plugin_file ]['Version'] ) : '';
				$name            = isset( $plugins_data[ $plugin_file ]['Name'] ) ? sanitize_text_field( (string) $plugins_data[ $plugin_file ]['Name'] ) : '';
			}

			if ( '' === $name ) {
				$name = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
			}

			$plugin_updates[] = array(
				'slug'            => $slug,
				'name'            => $name,
				'current_version' => $current_version,
				'new_version'     => $new_version,
			);
		}

		$theme_updates = array();
		$themes_data   = wp_get_themes();
		$theme_tx      = get_site_transient( 'update_themes' );
		$theme_resp    = ( is_object( $theme_tx ) && isset( $theme_tx->response ) && is_array( $theme_tx->response ) ) ? $theme_tx->response : array();

		foreach ( $theme_resp as $stylesheet => $info ) {
			$stylesheet = sanitize_key( (string) $stylesheet );
			if ( '' === $stylesheet ) {
				continue;
			}

			$new_version = '';
			if ( is_array( $info ) ) {
				$new_version = isset( $info['new_version'] ) ? sanitize_text_field( (string) $info['new_version'] ) : '';
			} elseif ( is_object( $info ) ) {
				$new_version = isset( $info->new_version ) ? sanitize_text_field( (string) $info->new_version ) : '';
			}

			$theme_obj        = isset( $themes_data[ $stylesheet ] ) ? $themes_data[ $stylesheet ] : null;
			$current_version  = $theme_obj ? sanitize_text_field( (string) $theme_obj->get( 'Version' ) ) : '';
			$name             = $theme_obj ? sanitize_text_field( (string) $theme_obj->get( 'Name' ) ) : ucwords( str_replace( array( '-', '_' ), ' ', $stylesheet ) );

			$theme_updates[] = array(
				'slug'            => $stylesheet,
				'name'            => $name,
				'current_version' => $current_version,
				'new_version'     => $new_version,
			);
		}

		usort(
			$plugin_updates,
			static function( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		usort(
			$theme_updates,
			static function( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$core_count = $core_needs_update ? 1 : 0;

		return array(
			'checked_at' => current_time( 'mysql' ),
			'core'       => array(
				'needs_update'    => $core_needs_update,
				'current_version' => $current_wp_version,
				'latest_version'  => $core_latest,
			),
			'themes'     => $theme_updates,
			'plugins'    => $plugin_updates,
			'counts'     => array(
				'core'    => $core_count,
				'themes'  => count( $theme_updates ),
				'plugins' => count( $plugin_updates ),
				'total'   => $core_count + count( $theme_updates ) + count( $plugin_updates ),
			),
		);
	}
}
