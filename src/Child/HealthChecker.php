<?php
namespace RawatWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HealthChecker {
	/**
	 * Run simple post-update health checks.
	 *
	 * @param string $target_path Expected target path.
	 * @return array
	 */
	public function run( $target_path ) {
		$checks = array();

		$home_response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout' => 15,
			)
		);

		$checks['homepage'] = $this->validate_http_response( $home_response );

		$login_response     = wp_remote_get(
			wp_login_url(),
			array(
				'timeout' => 15,
			)
		);
		$checks['wp_login'] = $this->validate_http_response( $login_response );

		$checks['target_exists'] = file_exists( $target_path );

		$failed = array_filter(
			$checks,
			static function( $value ) {
				return false === $value;
			}
		);

		return array(
			'ok'      => empty( $failed ),
			'checks'  => $checks,
			'message' => empty( $failed ) ? 'Health check ok.' : 'Health check failed.',
		);
	}

	/**
	 * Validate HTTP response for fatal indicators.
	 *
	 * @param array|\WP_Error $response Response.
	 * @return bool
	 */
	private function validate_http_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 100 || $code >= 500 ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$body = strtolower( $body );

		$indicators = array(
			'there has been a critical error on this website',
			'fatal error',
			'uncaught error',
		);

		foreach ( $indicators as $indicator ) {
			if ( false !== strpos( $body, $indicator ) ) {
				return false;
			}
		}

		return true;
	}
}
