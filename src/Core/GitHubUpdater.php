<?php
namespace RawatWP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubUpdater {
	/**
	 * Settings option key.
	 */
	const OPTION_SETTINGS = 'rawatwp_github_updater';

	/**
	 * Last error option key.
	 */
	const OPTION_LAST_ERROR = 'rawatwp_github_last_error';

	/**
	 * Release cache transient key prefix.
	 */
	const TRANSIENT_RELEASE_PREFIX = 'rawatwp_gh_release_';

	/**
	 * Active settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = $this->get_settings();
	}

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_plugin_folder_name' ), 10, 4 );
	}

	/**
	 * Get updater settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$default_enabled = defined( 'RAWATWP_GH_DEFAULT_ENABLED' ) && RAWATWP_GH_DEFAULT_ENABLED ? '1' : '0';
		$default_owner   = defined( 'RAWATWP_GH_OWNER' ) ? (string) RAWATWP_GH_OWNER : $this->get_embedded_owner();
		$default_repo    = defined( 'RAWATWP_GH_REPO' ) ? (string) RAWATWP_GH_REPO : '';
		$default_token   = defined( 'RAWATWP_GH_TOKEN' ) ? (string) RAWATWP_GH_TOKEN : '';

		$settings = wp_parse_args(
			$settings,
			array(
				'enabled' => $default_enabled,
				'owner'   => $default_owner,
				'repo'    => $default_repo,
				'token'   => $default_token,
			)
		);

		$owner = '' !== $default_owner ? $default_owner : (string) $settings['owner'];
		$repo  = '' !== $default_repo ? $default_repo : (string) $settings['repo'];
		$token = '' !== $default_token ? $default_token : (string) $settings['token'];

		return array(
			'enabled' => '1',
			'owner'   => sanitize_text_field( $owner ),
			'repo'    => sanitize_text_field( $repo ),
			'token'   => sanitize_text_field( $token ),
		);
	}

	/**
	 * Save updater settings.
	 *
	 * @param array $settings Settings input.
	 * @return bool
	 */
	public function save_settings( array $settings ) {
		$current = $this->get_settings();

		$sanitized = array(
			'enabled' => '1',
			'owner'   => sanitize_text_field( (string) $current['owner'] ),
			'repo'    => sanitize_text_field( (string) $current['repo'] ),
			'token'   => sanitize_text_field( (string) $current['token'] ),
		);

		$this->settings = $sanitized;
		$this->flush_release_cache();

		return (bool) update_option( self::OPTION_SETTINGS, $sanitized, false );
	}

	/**
	 * Force refresh GitHub release metadata cache.
	 *
	 * @return void
	 */
	public function flush_release_cache() {
		delete_transient( $this->get_release_cache_key() );
	}

	/**
	 * Return latest updater error message.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return sanitize_text_field( (string) get_option( self::OPTION_LAST_ERROR, '' ) );
	}

	/**
	 * Hook: inject update info into WordPress plugin update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! $this->is_configured() ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			update_option( self::OPTION_LAST_ERROR, $release->get_error_message(), false );
			return $transient;
		}

		update_option( self::OPTION_LAST_ERROR, '', false );

		$plugin_file   = plugin_basename( RAWATWP_FILE );
		$remote_version = sanitize_text_field( (string) $release['version'] );

		if ( version_compare( $remote_version, RAWATWP_VERSION, '<=' ) ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}

			$transient->no_update[ $plugin_file ] = (object) array(
				'id'          => 'rawatwp/rawatwp.php',
				'slug'        => 'rawatwp',
				'plugin'      => $plugin_file,
				'new_version' => RAWATWP_VERSION,
				'url'         => esc_url_raw( RAWATWP_SITE_URL ),
				'package'     => '',
			);

			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $plugin_file ] = (object) array(
			'id'            => 'rawatwp/rawatwp.php',
			'slug'          => 'rawatwp',
			'plugin'        => $plugin_file,
			'new_version'   => $remote_version,
			'url'           => esc_url_raw( RAWATWP_SITE_URL ),
			'package'       => isset( $release['package_url'] ) ? esc_url_raw( $release['package_url'] ) : '',
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => PHP_VERSION,
		);

		return $transient;
	}

	/**
	 * Hook: plugin info modal data.
	 *
	 * @param false|object|array $result Current result.
	 * @param string             $action Action.
	 * @param object             $args API args.
	 * @return false|object|array
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'rawatwp' !== $args->slug ) {
			return $result;
		}

		if ( ! $this->is_configured() ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$body = isset( $release['body'] ) ? (string) $release['body'] : '';
		$body = '' !== $body ? wp_kses_post( wpautop( $body ) ) : 'Tidak ada catatan rilis.';

		return (object) array(
			'name'          => 'RawatWP',
			'slug'          => 'rawatwp',
			'version'       => sanitize_text_field( (string) $release['version'] ),
			'author'        => '<a href="' . esc_url( RAWATWP_SITE_URL ) . '">arunajr.com</a>',
			'homepage'      => esc_url_raw( RAWATWP_SITE_URL ),
			'download_link' => isset( $release['package_url'] ) ? esc_url_raw( $release['package_url'] ) : '',
			'sections'      => array(
				'description' => 'RawatWP updater orchestration plugin.',
				'changelog'   => $body,
			),
		);
	}

	/**
	 * Hook: add auth headers when downloading from GitHub.
	 *
	 * @param array  $args Request args.
	 * @param string $url Request URL.
	 * @return array
	 */
	public function filter_http_request_args( $args, $url ) {
		if ( ! $this->is_configured() ) {
			return $args;
		}

		if ( ! $this->is_github_related_url( $url ) ) {
			return $args;
		}

		$token = (string) $this->settings['token'];
		if ( '' === $token ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['User-Agent']    = 'RawatWP/' . RAWATWP_VERSION;
		$host                             = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( 'objects.githubusercontent.com' !== $host ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		if ( false !== strpos( (string) $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Hook: normalize extracted plugin source folder to /rawatwp.
	 *
	 * @param string       $source Source path.
	 * @param string       $remote_source Remote source.
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $extra Extra args.
	 * @return string|\WP_Error
	 */
	public function normalize_plugin_folder_name( $source, $remote_source, $upgrader, $extra ) {
		if ( empty( $extra['plugin'] ) || plugin_basename( RAWATWP_FILE ) !== $extra['plugin'] ) {
			return $source;
		}

		$source = wp_normalize_path( (string) $source );
		if ( '' === $source || ! is_dir( $source ) ) {
			return $source;
		}

		if ( 'rawatwp' === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$normalized_target = wp_normalize_path( trailingslashit( dirname( $source ) ) . 'rawatwp' );
		if ( $wp_filesystem->exists( $normalized_target ) ) {
			$wp_filesystem->delete( $normalized_target, true );
		}

		$moved = $wp_filesystem->move( $source, $normalized_target, true );
		if ( ! $moved ) {
			return new \WP_Error( 'rawatwp_update_move_failed', __( 'Gagal menyiapkan folder update RawatWP.', 'rawatwp' ) );
		}

		return $normalized_target;
	}

	/**
	 * Force update metadata refresh.
	 *
	 * @return true|\WP_Error
	 */
	public function force_check_now() {
		$this->flush_release_cache();
		$release = $this->get_latest_release( true );
		if ( is_wp_error( $release ) ) {
			update_option( self::OPTION_LAST_ERROR, $release->get_error_message(), false );
			return $release;
		}

		update_option( self::OPTION_LAST_ERROR, '', false );
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();

		return true;
	}

	/**
	 * Check settings completeness.
	 *
	 * @return bool
	 */
	private function is_configured() {
		if ( '1' !== (string) $this->settings['enabled'] ) {
			return false;
		}

		return '' !== (string) $this->settings['owner'] && '' !== (string) $this->settings['repo'];
	}

	/**
	 * Get latest release metadata from GitHub API.
	 *
	 * @param bool $force_refresh Ignore cache.
	 * @return array|\WP_Error
	 */
	private function get_latest_release( $force_refresh = false ) {
		$cache_key = $this->get_release_cache_key();
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$owner = rawurlencode( (string) $this->settings['owner'] );
		$repo  = rawurlencode( (string) $this->settings['repo'] );
		$url   = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $owner, $repo );

		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'RawatWP/' . RAWATWP_VERSION,
			),
		);

		if ( '' !== (string) $this->settings['token'] ) {
			$args['headers']['Authorization'] = 'Bearer ' . (string) $this->settings['token'];
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rawatwp_gh_request_failed', 'Gagal koneksi ke server update: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new \WP_Error( 'rawatwp_gh_bad_response', 'Data release tidak bisa dibaca. Cek koneksi atau akses sumber update.' );
		}

		$tag = isset( $body['tag_name'] ) ? sanitize_text_field( (string) $body['tag_name'] ) : '';
		if ( '' === $tag ) {
			return new \WP_Error( 'rawatwp_gh_no_tag', 'Release tidak memiliki tag versi.' );
		}

		$version     = ltrim( $tag, "vV \t\n\r\0\x0B" );
		$package_url = $this->pick_release_asset_url( $body );
		if ( '' === $package_url ) {
			return new \WP_Error( 'rawatwp_gh_no_asset', 'Release wajib punya asset ZIP installable, misalnya rawatwp-0.1.19.zip.' );
		}

		$release = array(
			'version'     => $version,
			'tag'         => $tag,
			'package_url' => $package_url,
			'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : '',
			'published'   => isset( $body['published_at'] ) ? sanitize_text_field( (string) $body['published_at'] ) : '',
			'body'        => isset( $body['body'] ) ? (string) $body['body'] : '',
		);

		set_transient( $cache_key, $release, DAY_IN_SECONDS );

		return $release;
	}

	/**
	 * Select release asset ZIP URL.
	 *
	 * @param array $release GitHub release payload.
	 * @return string
	 */
	private function pick_release_asset_url( array $release ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			$name = isset( $asset['name'] ) ? sanitize_file_name( (string) $asset['name'] ) : '';
			if ( '' === $name || ! preg_match( '/^rawatwp.*\.zip$/i', $name ) ) {
				continue;
			}

			if ( ! empty( $this->settings['token'] ) && ! empty( $asset['url'] ) ) {
				return esc_url_raw( (string) $asset['url'] );
			}

			if ( ! empty( $asset['browser_download_url'] ) ) {
				return esc_url_raw( (string) $asset['browser_download_url'] );
			}
		}

		return '';
	}

	/**
	 * Check if URL is related to configured GitHub repository.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_github_related_url( $url ) {
		$url  = (string) $url;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );
		if ( ! in_array( $host, array( 'api.github.com', 'github.com', 'objects.githubusercontent.com' ), true ) ) {
			return false;
		}

		$needle = '/' . trim( (string) $this->settings['owner'], '/' ) . '/' . trim( (string) $this->settings['repo'], '/' ) . '/';
		return false !== strpos( strtolower( $url ), strtolower( $needle ) ) || 'objects.githubusercontent.com' === $host;
	}

	/**
	 * Build release cache key for current repo.
	 *
	 * @return string
	 */
	private function get_release_cache_key() {
		$owner = (string) $this->settings['owner'];
		$repo  = (string) $this->settings['repo'];

		return self::TRANSIENT_RELEASE_PREFIX . md5( strtolower( $owner . '/' . $repo ) );
	}

	/**
	 * Embedded repository owner for internal updater source.
	 *
	 * @return string
	 */
	private function get_embedded_owner() {
		$codes = array( 97, 114, 117, 110, 97, 106, 97, 116, 105 );
		$name  = '';
		foreach ( $codes as $code ) {
			$name .= chr( (int) $code );
		}

		return $name;
	}
}
