<?php
namespace RawatWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MonitoredItemsManager {
	/**
	 * Option key.
	 */
	const OPTION_MONITORED_ITEMS = 'rawatwp_monitored_items';

	/**
	 * Get monitored items.
	 *
	 * @return array
	 */
	public function get_items() {
		$items = get_option( self::OPTION_MONITORED_ITEMS, array() );
		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( $items );
	}

	/**
	 * Add monitored item.
	 *
	 * @param array $item Item data.
	 * @return array|\WP_Error
	 */
	public function add_item( array $item ) {
		$type = sanitize_key( $item['type'] );
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return new \WP_Error( 'rawatwp_bad_item_type', __( 'Invalid item type.', 'rawatwp' ) );
		}

		$slug = sanitize_title( $item['slug'] );
		if ( '' === $slug && ! empty( $item['label'] ) ) {
			$slug = sanitize_title( $item['label'] );
		}

		if ( '' === $slug ) {
			return new \WP_Error( 'rawatwp_bad_item_slug', __( 'Item slug is required.', 'rawatwp' ) );
		}

		$label = sanitize_text_field( $item['label'] );
		if ( '' === $label ) {
			$label = $slug;
		}

		$current_version = isset( $item['current_version'] ) ? sanitize_text_field( $item['current_version'] ) : '';
		$needs_update    = ! empty( $item['needs_update'] );

		$items = $this->get_items();

		foreach ( $items as $existing ) {
			if ( $existing['type'] === $type && $existing['slug'] === $slug ) {
				return new \WP_Error( 'rawatwp_item_exists', __( 'Item already exists in monitored list.', 'rawatwp' ) );
			}
		}

		$new_item = array(
			'id'              => wp_generate_uuid4(),
			'type'            => $type,
			'slug'            => $slug,
			'label'           => $label,
			'current_version' => $current_version,
			'needs_update'    => $needs_update,
		);

		$items[] = $new_item;
		update_option( self::OPTION_MONITORED_ITEMS, $items, false );

		return $new_item;
	}

	/**
	 * Remove monitored item.
	 *
	 * @param string $item_id Item ID.
	 * @return bool
	 */
	public function remove_item( $item_id ) {
		$item_id = sanitize_text_field( $item_id );
		$items   = $this->get_items();
		$before  = count( $items );

		$items = array_values(
			array_filter(
				$items,
				static function( $item ) use ( $item_id ) {
					return ! isset( $item['id'] ) || $item['id'] !== $item_id;
				}
			)
		);

		if ( $before === count( $items ) ) {
			return false;
		}

		update_option( self::OPTION_MONITORED_ITEMS, $items, false );

		return true;
	}

	/**
	 * Set needs_update flag.
	 *
	 * @param string $item_id Item ID.
	 * @param bool   $needs_update State.
	 * @return bool
	 */
	public function set_needs_update( $item_id, $needs_update ) {
		$item_id       = sanitize_text_field( $item_id );
		$needs_update  = (bool) $needs_update;
		$items         = $this->get_items();
		$found         = false;

		foreach ( $items as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $item_id ) {
				$item['needs_update'] = $needs_update;
				$found               = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		update_option( self::OPTION_MONITORED_ITEMS, $items, false );

		return true;
	}

	/**
	 * Get item by type/slug pair.
	 *
	 * @param string $type Item type.
	 * @param string $slug Item slug.
	 * @return array|null
	 */
	public function get_item_by_type_slug( $type, $slug ) {
		$type = sanitize_key( $type );
		$slug = sanitize_title( $slug );

		foreach ( $this->get_items() as $item ) {
			if ( $item['type'] === $type && $item['slug'] === $slug ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Get only needs_update items.
	 *
	 * @return array
	 */
	public function get_needs_update_items() {
		return array_values(
			array_filter(
				$this->get_items(),
				static function( $item ) {
					return ! empty( $item['needs_update'] );
				}
			)
		);
	}

	/**
	 * Scan installed plugins/themes and import as monitored items (manual action).
	 *
	 * @return array
	 */
	public function import_installed_items() {
		$items       = $this->get_items();
		$existing    = array();
		$added       = 0;
		$updated     = 0;
		$skipped     = 0;
		$plugins_n   = 0;
		$themes_n    = 0;
		$updates_map = $this->get_native_update_flags();

		foreach ( $items as $index => $item ) {
			if ( empty( $item['type'] ) || empty( $item['slug'] ) ) {
				continue;
			}
			$key               = sanitize_key( $item['type'] ) . '|' . sanitize_title( $item['slug'] );
			$existing[ $key ]  = (int) $index;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugins_n++;

			$dirname = dirname( $plugin_file );
			$slug    = '.' === $dirname
				? sanitize_title( pathinfo( wp_basename( $plugin_file ), PATHINFO_FILENAME ) )
				: sanitize_title( $dirname );

			if ( '' === $slug ) {
				$skipped++;
				continue;
			}

			$key = 'plugin|' . $slug;
			if ( isset( $existing[ $key ] ) ) {
				$index         = (int) $existing[ $key ];
				$current_label = isset( $plugin_data['Name'] ) ? sanitize_text_field( $plugin_data['Name'] ) : $slug;
				$current_ver   = isset( $plugin_data['Version'] ) ? sanitize_text_field( $plugin_data['Version'] ) : '';
				$needs_update  = isset( $updates_map['plugins'][ $slug ] );

				if ( isset( $items[ $index ] ) && is_array( $items[ $index ] ) ) {
					$items[ $index ]['label']           = '' !== $current_label ? $current_label : $slug;
					$items[ $index ]['current_version'] = $current_ver;
					$items[ $index ]['needs_update']    = $needs_update;
					$updated++;
				}
				continue;
			}

			$label = isset( $plugin_data['Name'] ) ? sanitize_text_field( $plugin_data['Name'] ) : $slug;
			$version = isset( $plugin_data['Version'] ) ? sanitize_text_field( $plugin_data['Version'] ) : '';

			$items[] = array(
				'id'              => wp_generate_uuid4(),
				'type'            => 'plugin',
				'slug'            => $slug,
				'label'           => '' !== $label ? $label : $slug,
				'current_version' => $version,
				'needs_update'    => isset( $updates_map['plugins'][ $slug ] ),
			);
			$existing[ $key ] = count( $items ) - 1;
			$added++;
		}

		$themes = wp_get_themes();
		foreach ( $themes as $theme_slug => $theme ) {
			$themes_n++;

			$slug = sanitize_title( (string) $theme_slug );
			if ( '' === $slug ) {
				$skipped++;
				continue;
			}

			$key = 'theme|' . $slug;
			if ( isset( $existing[ $key ] ) ) {
				$index         = (int) $existing[ $key ];
				$current_label = sanitize_text_field( $theme->get( 'Name' ) );
				$current_ver   = sanitize_text_field( $theme->get( 'Version' ) );
				$needs_update  = isset( $updates_map['themes'][ $slug ] );

				if ( isset( $items[ $index ] ) && is_array( $items[ $index ] ) ) {
					$items[ $index ]['label']           = '' !== $current_label ? $current_label : $slug;
					$items[ $index ]['current_version'] = $current_ver;
					$items[ $index ]['needs_update']    = $needs_update;
					$updated++;
				}
				continue;
			}

			$label = sanitize_text_field( $theme->get( 'Name' ) );
			$version = sanitize_text_field( $theme->get( 'Version' ) );

			$items[] = array(
				'id'              => wp_generate_uuid4(),
				'type'            => 'theme',
				'slug'            => $slug,
				'label'           => '' !== $label ? $label : $slug,
				'current_version' => $version,
				'needs_update'    => isset( $updates_map['themes'][ $slug ] ),
			);
			$existing[ $key ] = count( $items ) - 1;
			$added++;
		}

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['type'] ) || empty( $item['slug'] ) ) {
				continue;
			}
			$type = sanitize_key( (string) $item['type'] );
			$slug = sanitize_title( (string) $item['slug'] );
			if ( 'plugin' === $type && ! isset( $updates_map['plugins'][ $slug ] ) ) {
				$items[ $index ]['needs_update'] = false;
			}
			if ( 'theme' === $type && ! isset( $updates_map['themes'][ $slug ] ) ) {
				$items[ $index ]['needs_update'] = false;
			}
		}

		update_option( self::OPTION_MONITORED_ITEMS, $items, false );

		$auto_needs_update = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['needs_update'] ) ) {
				$auto_needs_update++;
			}
		}

		return array(
			'added'             => $added,
			'updated'           => $updated,
			'skipped'           => $skipped,
			'plugins_found'     => $plugins_n,
			'themes_found'      => $themes_n,
			'auto_needs_update' => $auto_needs_update,
		);
	}

	/**
	 * Get native WordPress update flags for installed plugins/themes.
	 *
	 * @return array
	 */
	private function get_native_update_flags() {
		if ( ! function_exists( 'wp_update_plugins' ) || ! function_exists( 'wp_update_themes' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}

		// Trigger native update refresh (best effort). Any runtime issue should not break scan.
		try {
			wp_update_plugins();
		} catch ( \Throwable $e ) {
			// noop.
		}
		try {
			wp_update_themes();
		} catch ( \Throwable $e ) {
			// noop.
		}

		$plugin_flags = array();
		$theme_flags  = array();

		$plugin_tx   = get_site_transient( 'update_plugins' );
		$plugin_resp = ( is_object( $plugin_tx ) && isset( $plugin_tx->response ) && is_array( $plugin_tx->response ) ) ? $plugin_tx->response : array();
		foreach ( $plugin_resp as $plugin_file => $info ) {
			$plugin_file = sanitize_text_field( (string) $plugin_file );
			$slug        = '';
			if ( is_object( $info ) && ! empty( $info->slug ) ) {
				$slug = sanitize_title( (string) $info->slug );
			} elseif ( is_array( $info ) && ! empty( $info['slug'] ) ) {
				$slug = sanitize_title( (string) $info['slug'] );
			}
			if ( '' === $slug ) {
				$dirname = dirname( $plugin_file );
				$slug    = '.' === $dirname ? sanitize_title( pathinfo( wp_basename( $plugin_file ), PATHINFO_FILENAME ) ) : sanitize_title( $dirname );
			}
			if ( '' !== $slug ) {
				$plugin_flags[ $slug ] = true;
			}
		}

		$theme_tx   = get_site_transient( 'update_themes' );
		$theme_resp = ( is_object( $theme_tx ) && isset( $theme_tx->response ) && is_array( $theme_tx->response ) ) ? $theme_tx->response : array();
		foreach ( $theme_resp as $stylesheet => $info ) {
			unset( $info );
			$slug = sanitize_title( (string) $stylesheet );
			if ( '' !== $slug ) {
				$theme_flags[ $slug ] = true;
			}
		}

		return array(
			'plugins' => $plugin_flags,
			'themes'  => $theme_flags,
		);
	}
}
