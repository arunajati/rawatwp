<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SecurityManager {
	/**
	 * Option key for queue runner token.
	 */
	const OPTION_QUEUE_RUNNER_TOKEN = 'rawatwp_queue_runner_token';

	/**
	 * Allowed timestamp drift in seconds.
	 */
	const TTL = 300;

	/**
	 * Generate unique child security key.
	 *
	 * @return string
	 */
	public function generate_security_key() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Build signed packet for REST body.
	 *
	 * @param array  $data Data payload.
	 * @param string $key Secret key.
	 * @return array
	 */
	public function build_signed_packet( array $data, $key ) {
		$packet = array(
			'data'      => $data,
			'timestamp' => time(),
			'nonce'     => wp_generate_uuid4(),
		);

		$packet['signature'] = $this->sign_payload( $packet, $key );

		return $packet;
	}

	/**
	 * Verify signed packet.
	 *
	 * @param array  $packet Packet.
	 * @param string $key Secret key.
	 * @param string $context Replay context.
	 * @return true|\WP_Error
	 */
	public function verify_signed_packet( array $packet, $key, $context = 'default' ) {
		if ( empty( $packet['timestamp'] ) || empty( $packet['nonce'] ) || empty( $packet['signature'] ) || ! isset( $packet['data'] ) ) {
			return new \WP_Error( 'rawatwp_bad_packet', __( 'Signed packet is incomplete.', 'rawatwp' ) );
		}

		$timestamp = (int) $packet['timestamp'];
		if ( abs( time() - $timestamp ) > self::TTL ) {
			return new \WP_Error( 'rawatwp_expired', __( 'Request kadaluarsa.', 'rawatwp' ) );
		}

		$expected = $this->sign_payload(
			array(
				'data'      => $packet['data'],
				'timestamp' => $timestamp,
				'nonce'     => sanitize_text_field( $packet['nonce'] ),
			),
			$key
		);

		if ( ! hash_equals( $expected, (string) $packet['signature'] ) ) {
			return new \WP_Error( 'rawatwp_bad_signature', __( 'Invalid signature.', 'rawatwp' ) );
		}

		$nonce_key = 'rawatwp_nonce_' . md5( $context . '|' . $packet['nonce'] );
		if ( get_transient( $nonce_key ) ) {
			return new \WP_Error( 'rawatwp_replay', __( 'Request duplikat terdeteksi.', 'rawatwp' ) );
		}

		set_transient( $nonce_key, 1, self::TTL );

		return true;
	}

	/**
	 * Sign download request tuple.
	 *
	 * @param int    $package_id Package ID.
	 * @param int    $child_id Child ID.
	 * @param int    $timestamp Timestamp.
	 * @param string $nonce Nonce.
	 * @param string $key Secret.
	 * @return string
	 */
	public function sign_download_tuple( $package_id, $child_id, $timestamp, $nonce, $key ) {
		$value = implode(
			'|',
			array(
				(int) $package_id,
				(int) $child_id,
				(int) $timestamp,
				sanitize_text_field( $nonce ),
			)
		);

		return hash_hmac( 'sha256', $value, $key );
	}

	/**
	 * Verify package download signature.
	 *
	 * @param int    $package_id Package ID.
	 * @param int    $child_id Child ID.
	 * @param int    $timestamp Timestamp.
	 * @param string $nonce Nonce.
	 * @param string $signature Signature.
	 * @param string $key Secret.
	 * @return true|\WP_Error
	 */
	public function verify_download_signature( $package_id, $child_id, $timestamp, $nonce, $signature, $key ) {
		$timestamp = (int) $timestamp;
		if ( abs( time() - $timestamp ) > self::TTL ) {
			return new \WP_Error( 'rawatwp_expired_download', __( 'Token download kadaluarsa.', 'rawatwp' ) );
		}

		$expected = $this->sign_download_tuple( $package_id, $child_id, $timestamp, $nonce, $key );
		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return new \WP_Error( 'rawatwp_bad_download_signature', __( 'Invalid download token.', 'rawatwp' ) );
		}

		$nonce_key = 'rawatwp_dl_nonce_' . md5( (string) $child_id . '|' . (string) $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new \WP_Error( 'rawatwp_replay_download', __( 'Download token has already been used.', 'rawatwp' ) );
		}

		set_transient( $nonce_key, 1, self::TTL );

		return true;
	}

	/**
	 * Sign payload.
	 *
	 * @param array  $payload Packet without signature.
	 * @param string $key Secret key.
	 * @return string
	 */
	private function sign_payload( array $payload, $key ) {
		$normalized = $this->normalize_payload( $payload );
		$json       = wp_json_encode( $normalized );

		return hash_hmac( 'sha256', $json, $key );
	}

	/**
	 * Normalize payload for deterministic signature.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalize_payload( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
		if ( $is_assoc ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize_payload( $item );
		}

		return $value;
	}

	/**
	 * Get queue runner token (generate if missing).
	 *
	 * @return string
	 */
	public function get_queue_runner_token() {
		$token = (string) get_option( self::OPTION_QUEUE_RUNNER_TOKEN, '' );
		if ( '' !== $token ) {
			return $token;
		}

		$token = wp_generate_password( 64, false, false );
		update_option( self::OPTION_QUEUE_RUNNER_TOKEN, $token, false );

		return $token;
	}

	/**
	 * Regenerate queue runner token.
	 *
	 * @return string
	 */
	public function regenerate_queue_runner_token() {
		$token = wp_generate_password( 64, false, false );
		update_option( self::OPTION_QUEUE_RUNNER_TOKEN, $token, false );

		return $token;
	}

	/**
	 * Verify queue runner token from request.
	 *
	 * @param string $token Token from request.
	 * @return true|\WP_Error
	 */
	public function verify_queue_runner_token( $token ) {
		$token    = sanitize_text_field( (string) $token );
		$expected = $this->get_queue_runner_token();

		if ( '' === $token || ! hash_equals( $expected, $token ) ) {
			return new \WP_Error( 'rawatwp_bad_runner_token', __( 'Invalid queue worker token.', 'rawatwp' ) );
		}

		return true;
	}
}
