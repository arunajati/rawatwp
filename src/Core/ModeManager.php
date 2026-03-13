<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ModeManager {
	const OPTION_MODE = 'rawatwp_mode';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Get current mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		$mode = get_option( self::OPTION_MODE, '' );
		$mode = sanitize_key( $mode );

		return in_array( $mode, array( 'master', 'child' ), true ) ? $mode : '';
	}

	/**
	 * Set plugin mode and reset opposite mode configuration.
	 *
	 * @param string $mode Mode value.
	 * @return bool|\WP_Error
	 */
	public function set_mode( $mode ) {
		$mode = sanitize_key( $mode );

		if ( ! in_array( $mode, array( 'master', 'child' ), true ) ) {
			return new \WP_Error( 'rawatwp_invalid_mode', __( 'Invalid mode.', 'rawatwp' ) );
		}

		$current_mode = $this->get_mode();
		if ( $current_mode === $mode ) {
			return true;
		}

		$this->reset_opposite_mode_data( $mode );
		update_option( self::OPTION_MODE, $mode, false );

		$this->logger->log(
			array(
				'mode'    => $mode,
				'action'  => 'mode_switched',
				'status'  => 'success',
				'message' => sprintf( 'Mode diubah ke %s.', $mode ),
			)
		);

		return true;
	}

	/**
	 * Check if master mode.
	 *
	 * @return bool
	 */
	public function is_master() {
		return 'master' === $this->get_mode();
	}

	/**
	 * Check if child mode.
	 *
	 * @return bool
	 */
	public function is_child() {
		return 'child' === $this->get_mode();
	}

	/**
	 * Reset opposite mode options safely.
	 *
	 * @param string $next_mode New mode.
	 * @return void
	 */
	private function reset_opposite_mode_data( $next_mode ) {
		if ( 'master' === $next_mode ) {
			delete_option( 'rawatwp_child_settings' );
			delete_option( 'rawatwp_monitored_items' );
		}

		if ( 'child' === $next_mode ) {
			delete_option( 'rawatwp_master_settings' );
			delete_option( 'rawatwp_queue_paused' );
			delete_option( 'rawatwp_queue_maintenance_last_run' );
		}
	}
}
