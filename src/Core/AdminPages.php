<?php
namespace RawatWP\Core;

use RawatWP\Child\ChildManager;
use RawatWP\Child\MonitoredItemsManager;
use RawatWP\Master\MasterManager;
use RawatWP\Master\PackageManager;
use RawatWP\Master\QueueManager;
use RawatWP\Core\GitHubUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPages {
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
	 * Monitored items manager.
	 *
	 * @var MonitoredItemsManager
	 */
	private $monitored_items;

	/**
	 * Package manager.
	 *
	 * @var PackageManager
	 */
	private $package_manager;

	/**
	 * Queue manager.
	 *
	 * @var QueueManager
	 */
	private $queue_manager;

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
	 * GitHub updater.
	 *
	 * @var GitHubUpdater
	 */
	private $github_updater;

	/**
	 * Constructor.
	 *
	 * @param ModeManager          $mode_manager Mode manager.
	 * @param MasterManager        $master_manager Master manager.
	 * @param ChildManager         $child_manager Child manager.
	 * @param MonitoredItemsManager $monitored_items Monitored items manager.
	 * @param PackageManager       $package_manager Package manager.
	 * @param QueueManager         $queue_manager Queue manager.
	 * @param Logger               $logger Logger.
	 * @param SecurityManager      $security Security.
	 * @param GitHubUpdater        $github_updater GitHub updater.
	 */
	public function __construct( ModeManager $mode_manager, MasterManager $master_manager, ChildManager $child_manager, MonitoredItemsManager $monitored_items, PackageManager $package_manager, QueueManager $queue_manager, Logger $logger, SecurityManager $security, GitHubUpdater $github_updater ) {
		$this->mode_manager    = $mode_manager;
		$this->master_manager  = $master_manager;
		$this->child_manager   = $child_manager;
		$this->monitored_items = $monitored_items;
		$this->package_manager = $package_manager;
		$this->queue_manager   = $queue_manager;
		$this->logger          = $logger;
		$this->security        = $security;
		$this->github_updater  = $github_updater;
	}

	/**
	 * Register WordPress admin menu.
	 *
	 * @return void
	 */
	public function register_menus() {
		$mode = $this->mode_manager->get_mode();

		add_menu_page(
			'RawatWP',
			'RawatWP',
			'manage_options',
			'rawatwp-general',
			array( $this, 'render_general_page' ),
			'dashicons-update',
			58
		);

		add_submenu_page( 'rawatwp-general', 'General', 'General', 'manage_options', 'rawatwp-general', array( $this, 'render_general_page' ) );

		if ( 'master' === $mode ) {
			add_submenu_page( 'rawatwp-general', 'Sites', 'Sites', 'manage_options', 'rawatwp-sites', array( $this, 'render_sites_page' ) );
			add_submenu_page( 'rawatwp-general', 'Packages', 'Packages', 'manage_options', 'rawatwp-packages', array( $this, 'render_packages_page' ) );
			add_submenu_page( 'rawatwp-general', 'Updates', 'Updates', 'manage_options', 'rawatwp-updates', array( $this, 'render_updates_page' ) );
		}

		if ( 'child' === $mode ) {
			add_submenu_page( 'rawatwp-general', 'Connection', 'Connection', 'manage_options', 'rawatwp-connection', array( $this, 'render_connection_page' ) );
		}

		add_submenu_page( 'rawatwp-general', 'Logs', 'Logs', 'manage_options', 'rawatwp-logs', array( $this, 'render_logs_page' ) );
	}

	/**
	 * Enqueue RawatWP admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$hook_suffix = sanitize_text_field( (string) $hook_suffix );
		if ( false === strpos( $hook_suffix, 'rawatwp' ) ) {
			return;
		}

		wp_enqueue_style(
			'rawatwp-admin',
			RAWATWP_URL . 'assets/css/admin.css',
			array(),
			RAWATWP_VERSION
		);

		wp_enqueue_script(
			'rawatwp-admin',
			RAWATWP_URL . 'assets/js/admin.js',
			array(),
			RAWATWP_VERSION,
			true
		);
	}

	/**
	 * Register admin-post handlers.
	 *
	 * @return void
	 */
	public function register_admin_actions() {
		add_action( 'admin_post_rawatwp_save_general_settings', array( $this, 'handle_save_general_settings' ) );
		add_action( 'admin_post_rawatwp_save_and_connect', array( $this, 'handle_save_and_connect' ) );
		add_action( 'admin_post_rawatwp_disconnect_child', array( $this, 'handle_disconnect_child' ) );
		add_action( 'admin_post_rawatwp_add_site', array( $this, 'handle_add_site' ) );
		add_action( 'admin_post_rawatwp_regen_key', array( $this, 'handle_regenerate_site_key' ) );
		add_action( 'admin_post_rawatwp_check_site_updates', array( $this, 'handle_check_site_updates' ) );
		add_action( 'admin_post_rawatwp_check_all_site_updates', array( $this, 'handle_check_all_site_updates' ) );
		add_action( 'admin_post_rawatwp_queue_rawatwp_update_all_sites', array( $this, 'handle_queue_rawatwp_update_all_sites' ) );
		add_action( 'admin_post_rawatwp_upload_package', array( $this, 'handle_upload_package' ) );
		add_action( 'admin_post_rawatwp_scan_updates_folder', array( $this, 'handle_scan_updates_folder' ) );
		add_action( 'admin_post_rawatwp_delete_package', array( $this, 'handle_delete_package' ) );
		add_action( 'admin_post_rawatwp_bulk_delete_packages', array( $this, 'handle_bulk_delete_packages' ) );
		add_action( 'admin_post_rawatwp_push_update', array( $this, 'handle_push_update' ) );
		add_action( 'admin_post_rawatwp_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_rawatwp_clear_update_progress', array( $this, 'handle_clear_update_progress' ) );
		add_action( 'admin_post_rawatwp_check_update_now', array( $this, 'handle_check_update_now' ) );
		add_action( 'admin_post_rawatwp_queue_run_now', array( $this, 'handle_queue_run_now' ) );
		add_action( 'admin_post_rawatwp_queue_pause_toggle', array( $this, 'handle_queue_pause_toggle' ) );
		add_action( 'admin_post_rawatwp_regenerate_runner_token', array( $this, 'handle_regenerate_runner_token' ) );

		add_action( 'wp_ajax_rawatwp_upload_package_item', array( $this, 'handle_ajax_upload_package_item' ) );
		add_action( 'wp_ajax_rawatwp_queue_process_next', array( $this, 'handle_ajax_queue_process_next' ) );
		add_action( 'wp_ajax_rawatwp_queue_status', array( $this, 'handle_ajax_queue_status' ) );
	}

	/**
	 * Add "Check Update" link on RawatWP plugin row meta in Plugins page.
	 *
	 * @param array  $plugin_meta Existing plugin meta links.
	 * @param string $plugin_file Plugin file relative path.
	 * @param array  $plugin_data Plugin header data.
	 * @param string $status      Plugin status.
	 * @return array
	 */
	public function add_plugin_row_meta_check_update( $plugin_meta, $plugin_file, $plugin_data, $status ) {
		unset( $plugin_data, $status );

		if ( plugin_basename( RAWATWP_FILE ) !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $plugin_meta;
		}

		$check_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'rawatwp_check_update_now',
					'redirect_to' => admin_url( 'plugins.php' ),
				),
				admin_url( 'admin-post.php' )
			),
			'rawatwp_check_update_now'
		);

		$plugin_meta[] = '<a href="' . esc_url( $check_url ) . '">Check Update</a>';

		return $plugin_meta;
	}

	/**
	 * Render general page.
	 *
	 * @return void
	 */
	public function render_general_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		$delete_all_on_uninstall = '1' === (string) get_option( 'rawatwp_delete_all_on_uninstall', '1' );
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - General</h1>
			<p class="rawatwp-page-subtitle">Main settings for RawatWP.</p>
			<?php $this->render_notices(); ?>
			<div class="rawatwp-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'rawatwp_save_general_settings' ); ?>
					<input type="hidden" name="action" value="rawatwp_save_general_settings" />
					<div class="rawatwp-field-stack">
						<p class="rawatwp-field-group">
							<label for="rawatwp_mode"><strong>Active Mode</strong></label>
							<select id="rawatwp_mode" name="rawatwp_mode">
								<option value="">Select Mode</option>
								<option value="master" <?php selected( $mode, 'master' ); ?>>Master</option>
								<option value="child" <?php selected( $mode, 'child' ); ?>>Child</option>
							</select>
						</p>
						<p class="rawatwp-field-group">
							<label><strong>Uninstall Options</strong></label>
							<label>
								<input type="checkbox" name="delete_all_on_uninstall" value="1" <?php checked( $delete_all_on_uninstall ); ?> />
								Clean Uninstall
							</label>
						</p>
					</div>
					<?php submit_button( 'Save Settings' ); ?>
				</form>

			</div>
		</div>
		<?php
	}

	/**
	 * Render connection page.
	 *
	 * @return void
	 */
	public function render_connection_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - Connection</h1>
			<p class="rawatwp-page-subtitle">Connect this Child site to Master. All monitoring and update control are handled from Master.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'child' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>This page is available only in Child mode.</p>
				</div>
			<?php else : ?>
				<?php
				$settings      = $this->child_manager->get_settings();
				$is_connected  = ! empty( $settings['connected'] );
				$form_action   = $is_connected ? 'rawatwp_disconnect_child' : 'rawatwp_save_and_connect';
				$nonce_action  = $is_connected ? 'rawatwp_disconnect_child' : 'rawatwp_save_and_connect';
				$button_label  = $is_connected ? 'Disconnect' : 'Save & Connect';
				$button_style  = $is_connected ? 'secondary' : 'primary';
				$required_attr = $is_connected ? '' : 'required';
				?>
				<div class="rawatwp-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( $nonce_action ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( $form_action ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="master_url">Master URL</label></th>
								<td><input class="regular-text" type="url" id="master_url" name="master_url" value="<?php echo esc_attr( $settings['master_url'] ); ?>" <?php echo esc_attr( $required_attr ); ?> /></td>
							</tr>
							<tr>
								<th scope="row"><label for="security_key">Security Key</label></th>
								<td><input class="regular-text" type="text" id="security_key" name="security_key" value="<?php echo esc_attr( $settings['security_key'] ); ?>" <?php echo esc_attr( $required_attr ); ?> /></td>
							</tr>
						</table>
						<?php submit_button( $button_label, $button_style ); ?>
					</form>
					<p><strong>Status:</strong> <?php echo $is_connected ? 'Connected' : 'Not connected'; ?></p>
					<?php if ( ! empty( $settings['last_connected_at'] ) ) : ?>
						<p><strong>Last Connected:</strong> <?php echo esc_html( $this->format_datetime_for_display( $settings['last_connected_at'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $settings['child_id'] ) ) : ?>
						<p><strong>Child ID:</strong> <?php echo esc_html( (string) $settings['child_id'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render monitored items page.
	 *
	 * @return void
	 */
	public function render_monitored_items_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - Monitored Items</h1>
			<p class="rawatwp-page-subtitle">Manage monitored plugin/theme items in Child mode.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'child' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>This page is available only in Child mode.</p>
				</div>
			<?php else : ?>
				<div class="rawatwp-card">
					<p><strong>Simple flow:</strong> 1) Scan installed plugins/themes, 2) mark items that need updates, 3) send report to Master.</p>

					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_scan_installed_items' ); ?>
						<input type="hidden" name="action" value="rawatwp_scan_installed_items" />
						<?php submit_button( '1) Scan Installed Plugins & Themes', 'primary', 'submit', false ); ?>
					</form>

					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_scan_now' ); ?>
						<input type="hidden" name="action" value="rawatwp_scan_now" />
						<?php submit_button( '3) Send Report to Master', 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="rawatwp-card">
					<h2>Monitored Items List</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Type</th>
								<th>Slug</th>
								<th>Label</th>
								<th>Current Version</th>
								<th>Needs Update</th>
								<th class="rawatwp-no-sort">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$monitored_items = $this->monitored_items->get_items();
							if ( empty( $monitored_items ) ) :
								?>
								<tr>
									<td colspan="6">No items yet. Click scan to auto-import installed plugins/themes into the list.</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $monitored_items as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item['type'] ); ?></td>
									<td><?php echo esc_html( $item['slug'] ); ?></td>
									<td><?php echo esc_html( $item['label'] ); ?></td>
									<td><?php echo esc_html( $item['current_version'] ); ?></td>
									<td><?php echo ! empty( $item['needs_update'] ) ? 'Yes' : 'No'; ?></td>
									<td>
										<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'rawatwp_toggle_item' ); ?>
											<input type="hidden" name="action" value="rawatwp_toggle_item" />
											<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
											<input type="hidden" name="needs_update" value="<?php echo ! empty( $item['needs_update'] ) ? '0' : '1'; ?>" />
											<button class="button" type="submit"><?php echo ! empty( $item['needs_update'] ) ? 'Marked Safe' : 'Needs Update'; ?></button>
										</form>
										<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'rawatwp_delete_item' ); ?>
											<input type="hidden" name="action" value="rawatwp_delete_item" />
											<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
											<button class="button button-link-delete" type="submit">Delete</button>
										</form>
									</td>
								</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render sites page.
	 *
	 * @return void
	 */
	public function render_sites_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - Sites</h1>
			<p class="rawatwp-page-subtitle">Manage child sites connected to Master.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'master' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>This page is available only in Master mode.</p>
				</div>
			<?php else : ?>
				<?php
				$sites            = $this->master_manager->get_sites();
				$connected_site_ids = array();
				foreach ( $sites as $site_row ) {
					if ( isset( $site_row['connection_status'] ) && 'connected' === sanitize_key( (string) $site_row['connection_status'] ) ) {
						$connected_site_ids[] = (int) $site_row['id'];
					}
				}
				$connected_count         = count( $connected_site_ids );
				$master_version          = $this->get_runtime_rawatwp_version();
				$update_button_attributes = $connected_count <= 0 ? array( 'disabled' => 'disabled' ) : array();
				$health_data_by_site     = $this->build_sites_health_overview( $sites );
				?>
				<div class="rawatwp-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_add_site' ); ?>
						<input type="hidden" name="action" value="rawatwp_add_site" />
						<div class="rawatwp-field-stack">
							<p class="rawatwp-field-group">
								<label for="rawatwp-site-name"><strong>Child Site Name</strong></label>
								<input id="rawatwp-site-name" class="regular-text" type="text" name="site_name" required />
							</p>
							<p class="rawatwp-field-group">
								<label for="rawatwp-site-url"><strong>Child Domain / URL</strong></label>
								<input id="rawatwp-site-url" class="regular-text" type="url" name="site_url" required />
							</p>
						</div>
						<?php submit_button( 'Add Child Site' ); ?>
					</form>
				</div>

				<div class="rawatwp-card">
					<div class="rawatwp-card-header">
						<h2>Child Site List</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Queue RawatWP update to all connected child sites now?');">
							<?php wp_nonce_field( 'rawatwp_queue_rawatwp_update_all_sites' ); ?>
							<input type="hidden" name="action" value="rawatwp_queue_rawatwp_update_all_sites" />
							<?php submit_button( 'Update RawatWP on All Sites', 'secondary', 'submit', false, $update_button_attributes ); ?>
						</form>
					</div>
					<p class="description">Master RawatWP Version: <strong><?php echo esc_html( $master_version ); ?></strong> | Connected sites: <strong><?php echo esc_html( (string) $connected_count ); ?></strong></p>
					<table class="widefat striped rawatwp-sites-table">
						<thead>
							<tr>
								<th class="rawatwp-col-id">ID</th>
								<th class="rawatwp-col-site">Site Name</th>
								<th class="rawatwp-col-domain">Domain</th>
								<th class="rawatwp-col-rwp-version">RWP Ver.</th>
								<th class="rawatwp-col-key rawatwp-no-sort">Security Key</th>
								<th class="rawatwp-col-status">Status</th>
								<th class="rawatwp-col-last-seen">Last Seen</th>
								<th class="rawatwp-col-action rawatwp-no-sort">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sites as $site ) : ?>
								<tr>
									<td class="rawatwp-col-id"><?php echo esc_html( (string) $site['id'] ); ?></td>
									<td class="rawatwp-col-site"><?php echo esc_html( $site['site_name'] ); ?></td>
									<td class="rawatwp-domain rawatwp-col-domain"><?php echo esc_html( $site['site_url'] ); ?></td>
									<td class="rawatwp-col-rwp-version"><?php echo esc_html( ! empty( $site['rawatwp_version'] ) ? (string) $site['rawatwp_version'] : '-' ); ?></td>
									<td class="rawatwp-col-key">
										<div class="rawatwp-key-wrap">
											<button
												type="button"
												class="button rawatwp-copy-key"
												data-copy-text="<?php echo esc_attr( $site['security_key'] ); ?>"
											>
												Copy
											</button>
										</div>
									</td>
									<td class="rawatwp-col-status"><?php echo esc_html( $site['connection_status'] ); ?></td>
									<td class="rawatwp-col-last-seen"><?php echo esc_html( $this->format_datetime_for_display( isset( $site['last_seen'] ) ? $site['last_seen'] : '' ) ); ?></td>
									<td class="rawatwp-col-action">
										<form class="rawatwp-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'rawatwp_regen_key' ); ?>
											<input type="hidden" name="action" value="rawatwp_regen_key" />
											<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site['id'] ); ?>" />
										<button class="button" type="submit">Regenerate Key</button>
									</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

					<div class="rawatwp-card" id="rawatwp-update-health">
						<div class="rawatwp-card-header">
							<h2>Update Health Overview</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Run manual core/theme/plugin update check on all connected child sites now?');">
							<?php wp_nonce_field( 'rawatwp_check_all_site_updates' ); ?>
							<input type="hidden" name="action" value="rawatwp_check_all_site_updates" />
							<?php submit_button( 'Check All Connected Sites', 'secondary', 'submit', false, $update_button_attributes ); ?>
						</form>
					</div>
					<p class="description">Manual trigger only. RawatWP does not run scheduled auto-update checks.</p>
						<?php if ( $connected_count <= 0 ) : ?>
							<p>No connected child sites yet.</p>
						<?php else : ?>
							<?php
							$connected_sites = array();
							foreach ( $sites as $site ) {
								$is_connected = isset( $site['connection_status'] ) && 'connected' === sanitize_key( (string) $site['connection_status'] );
								if ( ! $is_connected ) {
									continue;
								}

								$site_id = (int) $site['id'];
								$health  = isset( $health_data_by_site[ $site_id ] ) && is_array( $health_data_by_site[ $site_id ] ) ? $health_data_by_site[ $site_id ] : array();

								$status_key   = isset( $health['status'] ) ? sanitize_key( (string) $health['status'] ) : 'unknown';
								$needs_total  = isset( $health['counts']['total'] ) ? (int) $health['counts']['total'] : 0;
								$needs_update = ( 'failed' !== $status_key && $needs_total > 0 );

								$site['_rawatwp_health']           = $health;
								$site['_rawatwp_health_status']    = $status_key;
								$site['_rawatwp_health_total']     = $needs_total;
								$site['_rawatwp_has_needs_update'] = $needs_update;

								$connected_sites[] = $site;
							}

							usort(
								$connected_sites,
								function( $a, $b ) {
									$a_needs  = ! empty( $a['_rawatwp_has_needs_update'] );
									$b_needs  = ! empty( $b['_rawatwp_has_needs_update'] );
									$a_failed = isset( $a['_rawatwp_health_status'] ) && 'failed' === $a['_rawatwp_health_status'];
									$b_failed = isset( $b['_rawatwp_health_status'] ) && 'failed' === $b['_rawatwp_health_status'];

									$a_rank = $a_needs ? 0 : ( $a_failed ? 1 : 2 );
									$b_rank = $b_needs ? 0 : ( $b_failed ? 1 : 2 );
									if ( $a_rank !== $b_rank ) {
										return $a_rank <=> $b_rank;
									}

									$a_name = isset( $a['site_name'] ) ? sanitize_text_field( (string) $a['site_name'] ) : '';
									$b_name = isset( $b['site_name'] ) ? sanitize_text_field( (string) $b['site_name'] ) : '';

									return strcasecmp( $a_name, $b_name );
								}
							);
							?>
							<div class="rawatwp-site-health-list">
								<?php foreach ( $connected_sites as $site ) : ?>
									<?php
									$site_id      = (int) $site['id'];
									$health       = isset( $site['_rawatwp_health'] ) && is_array( $site['_rawatwp_health'] ) ? $site['_rawatwp_health'] : array();
									$status_key   = isset( $site['_rawatwp_health_status'] ) ? sanitize_key( (string) $site['_rawatwp_health_status'] ) : '';
									$needs_total  = isset( $site['_rawatwp_health_total'] ) ? (int) $site['_rawatwp_health_total'] : 0;
									$needs_update = ! empty( $site['_rawatwp_has_needs_update'] );

									$item_classes = array( 'rawatwp-site-health-item' );
									if ( $needs_update ) {
										$item_classes[] = 'is-needs-update';
									} elseif ( 'failed' === $status_key ) {
										$item_classes[] = 'is-failed';
									} else {
										$item_classes[] = 'is-up-to-date';
									}
									?>
									<div class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
										<div class="rawatwp-site-health-head">
											<div>
												<strong><?php echo esc_html( $site['site_name'] . ' (' . $site['site_url'] . ')' ); ?></strong>
										</div>
									</div>
								<?php if ( empty( $health ) ) : ?>
									<p class="rawatwp-site-health-meta">Not checked yet.</p>
								<?php else : ?>
									<?php if ( isset( $health['status'] ) && 'failed' === sanitize_key( (string) $health['status'] ) ) : ?>
										<p class="rawatwp-site-health-meta">
											<?php
											echo esc_html(
												sprintf(
													'Last check failed on %s.',
													$this->format_datetime_for_display( isset( $health['checked_at'] ) ? (string) $health['checked_at'] : '' )
												)
											);
											?>
										</p>
										<p class="rawatwp-site-health-meta">
											<?php echo esc_html( ! empty( $health['error_message'] ) ? (string) $health['error_message'] : 'Unable to check updates on this child site.' ); ?>
										</p>
									<?php else : ?>
									<p class="rawatwp-site-health-meta">
										<?php
										echo esc_html(
												sprintf(
													'Core: %s | Themes: %d | Plugins: %d | Last check: %s',
													! empty( $health['core']['needs_update'] ) ? 'Needs update' : 'Up to date',
													isset( $health['counts']['themes'] ) ? (int) $health['counts']['themes'] : 0,
													isset( $health['counts']['plugins'] ) ? (int) $health['counts']['plugins'] : 0,
													$this->format_datetime_for_display( isset( $health['checked_at'] ) ? (string) $health['checked_at'] : '' )
												)
										);
										?>
									</p>
										<?php if ( $needs_total > 0 ) : ?>
											<ul class="rawatwp-site-health-update-list">
												<?php if ( ! empty( $health['core']['needs_update'] ) ) : ?>
													<li><strong>Core:</strong> <?php echo esc_html( (string) $health['core']['current_version'] ); ?> -> <?php echo esc_html( (string) $health['core']['latest_version'] ); ?></li>
												<?php endif; ?>

												<?php if ( ! empty( $health['themes'] ) && is_array( $health['themes'] ) ) : ?>
													<?php foreach ( $health['themes'] as $theme_item ) : ?>
														<li><strong>Theme:</strong> <?php echo esc_html( (string) $theme_item['name'] . ' (' . (string) $theme_item['current_version'] . ' -> ' . (string) $theme_item['new_version'] . ')' ); ?></li>
													<?php endforeach; ?>
												<?php endif; ?>

												<?php if ( ! empty( $health['plugins'] ) && is_array( $health['plugins'] ) ) : ?>
													<?php foreach ( $health['plugins'] as $plugin_item ) : ?>
														<li><strong>Plugin:</strong> <?php echo esc_html( (string) $plugin_item['name'] . ' (' . (string) $plugin_item['current_version'] . ' -> ' . (string) $plugin_item['new_version'] . ')' ); ?></li>
													<?php endforeach; ?>
												<?php endif; ?>
										</ul>
									<?php else : ?>
										<p class="rawatwp-site-health-meta">No pending updates found.</p>
									<?php endif; ?>
									<?php endif; ?>
								<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<script>
				(function() {
					function fallbackCopy(text) {
						var temp = document.createElement('textarea');
						temp.value = text;
						document.body.appendChild(temp);
						temp.select();
						document.execCommand('copy');
						document.body.removeChild(temp);
					}

					var buttons = document.querySelectorAll('.rawatwp-copy-key');
					buttons.forEach(function(button) {
						button.addEventListener('click', function() {
							var text = button.getAttribute('data-copy-text') || '';
							if (!text) {
								return;
							}

							if (navigator.clipboard && navigator.clipboard.writeText) {
								navigator.clipboard.writeText(text).then(function() {
									button.textContent = 'Copied';
									setTimeout(function() { button.textContent = 'Copy'; }, 1200);
								}).catch(function() {
									fallbackCopy(text);
									button.textContent = 'Copied';
									setTimeout(function() { button.textContent = 'Copy'; }, 1200);
								});
								return;
							}

							fallbackCopy(text);
							button.textContent = 'Copied';
							setTimeout(function() { button.textContent = 'Copy'; }, 1200);
						});
					});
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render packages page.
	 *
	 * @return void
	 */
	public function render_packages_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - Packages</h1>
			<p class="rawatwp-page-subtitle">Upload, scan, and manage update packages.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'master' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>This page is available only in Master mode.</p>
				</div>
			<?php else : ?>
				<div class="rawatwp-card">
					<form id="rawatwp-package-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_upload_package' ); ?>
						<input type="hidden" name="action" value="rawatwp_upload_package" />
						<div class="rawatwp-upload-stack">
							<label for="rawatwp-package-zip-input" class="rawatwp-upload-title">Choose Package</label>
							<input id="rawatwp-package-zip-input" type="file" name="package_zip[]" accept=".zip" multiple required />
							<p id="rawatwp-upload-file-count" class="description"></p>
							<div id="rawatwp-selected-files" class="rawatwp-selected-files" hidden>
								<div class="rawatwp-selected-files-head">
									<strong>Selected Files</strong>
									<button id="rawatwp-clear-selected-files" type="button" class="button-link">Clear all</button>
								</div>
								<ul id="rawatwp-selected-files-list" class="rawatwp-selected-files-list"></ul>
							</div>
						</div>
						<div class="rawatwp-upload-actions">
							<?php submit_button( 'Upload zip', 'primary', 'submit', false, array( 'id' => 'rawatwp-upload-submit' ) ); ?>
						</div>
					</form>

					<div id="rawatwp-upload-monitor" class="rawatwp-upload-monitor" hidden>
						<div class="rawatwp-upload-monitor-header">
							<strong>Upload Monitor</strong>
							<div class="rawatwp-upload-monitor-actions">
								<button id="rawatwp-upload-monitor-cancel" type="button" class="button button-secondary button-small">Cancel upload</button>
								<button id="rawatwp-upload-monitor-close" type="button" class="button-link">Close</button>
							</div>
						</div>
						<p id="rawatwp-upload-summary" class="rawatwp-upload-summary">Waiting for upload...</p>
						<div id="rawatwp-upload-items" class="rawatwp-upload-items"></div>
					</div>
				</div>

				<div class="rawatwp-card">
					<div class="rawatwp-card-header">
						<h2>Package List</h2>
						<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rawatwp_scan_updates_folder' ); ?>
							<input type="hidden" name="action" value="rawatwp_scan_updates_folder" />
							<?php submit_button( 'Scan Available Packages', 'secondary', 'submit', false ); ?>
						</form>
					</div>
					<form id="rawatwp-bulk-delete-packages" class="rawatwp-package-bulk-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete all selected packages? The zip files and package data will be permanently removed.');">
						<?php wp_nonce_field( 'rawatwp_bulk_delete_packages' ); ?>
						<input type="hidden" name="action" value="rawatwp_bulk_delete_packages" />
						<select name="bulk_action" required>
							<option value="">Bulk Actions</option>
							<option value="delete">Delete</option>
						</select>
						<button class="button action" type="submit">Apply</button>
					</form>

					<table class="widefat striped rawatwp-package-table">
					<thead>
						<tr>
							<th class="rawatwp-col-check">
								<input type="checkbox" id="rawatwp-check-all-packages" />
							</th>
							<th>ID</th>
							<th>Label</th>
							<th>Type</th>
							<th>Source</th>
							<th>Target Slug</th>
							<th>File</th>
							<th>Uploaded At</th>
							<th class="rawatwp-no-sort">Action</th>
						</tr>
					</thead>
					<tbody>
						<?php $packages = $this->package_manager->get_packages(); ?>
						<?php if ( empty( $packages ) ) : ?>
							<tr>
								<td colspan="9">No packages yet.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $packages as $package ) : ?>
								<tr>
									<td class="rawatwp-col-check">
										<input
											type="checkbox"
											class="rawatwp-package-check"
											form="rawatwp-bulk-delete-packages"
											name="package_ids[]"
											value="<?php echo esc_attr( (string) $package['id'] ); ?>"
										/>
									</td>
									<td><?php echo esc_html( (string) $package['id'] ); ?></td>
									<td><?php echo esc_html( $this->format_package_label_for_display( $package ) ); ?></td>
									<td><?php echo esc_html( $this->format_package_type_for_display( isset( $package['type'] ) ? (string) $package['type'] : '' ) ); ?></td>
									<td><?php echo esc_html( $this->format_package_source_for_display( $package ) ); ?></td>
									<td><?php echo esc_html( $package['target_slug'] ); ?></td>
									<td><?php echo esc_html( $this->format_package_file_name_for_display( $package ) ); ?></td>
									<td><?php echo esc_html( $this->format_datetime_for_display( isset( $package['created_at'] ) ? $package['created_at'] : '' ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this package? The zip file and package data will be permanently removed.');">
											<?php wp_nonce_field( 'rawatwp_delete_package' ); ?>
											<input type="hidden" name="action" value="rawatwp_delete_package" />
											<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $package['id'] ); ?>" />
											<button class="button button-link-delete" type="submit">Delete</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					</table>
				</div>

				<script>
				(function() {
					var checkAll = document.getElementById('rawatwp-check-all-packages');
					var rowChecks = document.querySelectorAll('.rawatwp-package-check');
					var uploadForm = document.getElementById('rawatwp-package-upload-form');
					var fileInput = document.getElementById('rawatwp-package-zip-input');
					var submitButton = document.getElementById('rawatwp-upload-submit');
					var fileCountInfo = document.getElementById('rawatwp-upload-file-count');
					var selectedFilesWrap = document.getElementById('rawatwp-selected-files');
					var selectedFilesList = document.getElementById('rawatwp-selected-files-list');
					var clearSelectedFilesBtn = document.getElementById('rawatwp-clear-selected-files');
					var uploadMonitor = document.getElementById('rawatwp-upload-monitor');
					var uploadSummary = document.getElementById('rawatwp-upload-summary');
					var uploadItems = document.getElementById('rawatwp-upload-items');
					var closeMonitorBtn = document.getElementById('rawatwp-upload-monitor-close');
					var cancelMonitorBtn = document.getElementById('rawatwp-upload-monitor-cancel');
					var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'rawatwp_upload_package_item' ) ); ?>;
					var uploadedRows = [];
					var selectedFiles = [];
					var activeXhr = null;
					var cancelRequested = false;

					function setSummary(text) {
						if (uploadSummary) {
							uploadSummary.textContent = text;
						}
					}

					function supportsDataTransfer() {
						return typeof window.DataTransfer !== 'undefined';
					}

					function syncInputFilesFromSelected() {
						if (!fileInput) {
							return;
						}
						if (!supportsDataTransfer()) {
							if (!selectedFiles.length) {
								fileInput.value = '';
							}
							return;
						}

						var transfer = new DataTransfer();
						selectedFiles.forEach(function(file) {
							transfer.items.add(file);
						});
						fileInput.files = transfer.files;
					}

					function updateUploadButtonsState() {
						if (!uploadForm || !submitButton) {
							return;
						}

						var isUploading = '1' === uploadForm.getAttribute('data-uploading');
						submitButton.disabled = isUploading || selectedFiles.length < 1;

						if (fileInput) {
							fileInput.disabled = isUploading;
						}
						if (clearSelectedFilesBtn) {
							clearSelectedFilesBtn.disabled = isUploading || selectedFiles.length < 1;
						}
						if (cancelMonitorBtn) {
							cancelMonitorBtn.disabled = !isUploading;
						}
					}

					function renderSelectedFilesList() {
						if (!selectedFilesList || !selectedFilesWrap) {
							return;
						}

						selectedFilesList.innerHTML = '';
						if (!selectedFiles.length) {
							selectedFilesWrap.hidden = true;
							if (fileCountInfo) {
								fileCountInfo.textContent = '';
							}
							updateUploadButtonsState();
							return;
						}

						selectedFilesWrap.hidden = false;
						selectedFiles.forEach(function(file, index) {
							var item = document.createElement('li');
							item.className = 'rawatwp-selected-files-item';
							item.innerHTML = '' +
								'<span class=\"rawatwp-selected-files-name\"></span>' +
								'<button type=\"button\" class=\"button-link-delete rawatwp-selected-files-remove\" data-index=\"' + index + '\">Remove</button>';
							item.querySelector('.rawatwp-selected-files-name').textContent = file.name;
							selectedFilesList.appendChild(item);
						});

						if (fileCountInfo) {
							fileCountInfo.textContent = selectedFiles.length + ' file(s) selected';
						}
						updateUploadButtonsState();
					}

					function createUploadRow(fileName) {
						if (!uploadItems) {
							return null;
						}
						var row = document.createElement('div');
						row.className = 'rawatwp-upload-item';
						row.innerHTML = '' +
							'<div class=\"rawatwp-upload-item-head\">' +
								'<span class=\"rawatwp-upload-item-name\"></span>' +
								'<span class=\"rawatwp-upload-item-status\">Queued</span>' +
							'</div>' +
							'<div class=\"rawatwp-upload-item-message\">Waiting in queue.</div>' +
							'<div class=\"rawatwp-upload-item-bar-wrap\">' +
								'<div class=\"rawatwp-upload-item-bar\"></div>' +
							'</div>' +
							'<div class=\"rawatwp-upload-item-percent\">0.00%</div>';

						row.querySelector('.rawatwp-upload-item-name').textContent = fileName;
						uploadItems.appendChild(row);

						return row;
					}

					function updateUploadRow(row, percent, status, state) {
						if (!row) {
							return;
						}

						var safePercent = Math.max(0, Math.min(100, Number(percent || 0)));
						var percentText = safePercent.toFixed(2) + '%';
						var statusEl = row.querySelector('.rawatwp-upload-item-status');
						var messageEl = row.querySelector('.rawatwp-upload-item-message');
						var barEl = row.querySelector('.rawatwp-upload-item-bar');
						var percentEl = row.querySelector('.rawatwp-upload-item-percent');
						var stateMap = {
							queued: 'Queued',
							uploading: 'Uploading',
							validating: 'Validating',
							saving: 'Saving',
							success: 'Success',
							failed: 'Failed'
						};

						row.classList.remove('is-queued', 'is-uploading', 'is-validating', 'is-saving', 'is-success', 'is-failed');
						row.classList.add('is-' + state);

						if (statusEl) {
							statusEl.textContent = stateMap[state] || 'Queued';
						}
						if (messageEl) {
							messageEl.textContent = status;
						}
						if (barEl) {
							barEl.style.width = percentText;
						}
						if (percentEl) {
							percentEl.textContent = percentText;
						}
					}

					function parseErrorMessage(xhr) {
						try {
							var payload = JSON.parse(xhr.responseText || '{}');
							if (payload && payload.data && payload.data.message) {
								return payload.data.message;
							}
						} catch (e) {}
						if (xhr.status === 413) {
							return 'Upload failed: file is too large for server limit.';
						}
						if (xhr.status >= 500) {
							return 'Upload failed: server error.';
						}
						return 'Upload failed: unexpected response.';
					}

					if (closeMonitorBtn && uploadMonitor && uploadForm) {
						closeMonitorBtn.addEventListener('click', function() {
							if ('1' === uploadForm.getAttribute('data-uploading')) {
								return;
							}
							uploadMonitor.hidden = true;
						});
					}

					if (cancelMonitorBtn && uploadForm) {
						cancelMonitorBtn.addEventListener('click', function() {
							if ('1' !== uploadForm.getAttribute('data-uploading')) {
								return;
							}

							cancelRequested = true;
							setSummary('Cancel requested... finishing current file.');

							if (activeXhr) {
								activeXhr.abort();
							}
						});
					}

					if (checkAll) {
						checkAll.addEventListener('change', function() {
							rowChecks.forEach(function(item) {
								item.checked = checkAll.checked;
							});
						});
					}

					if (!uploadForm || !window.XMLHttpRequest || !fileInput || !submitButton) {
						return;
					}

					fileInput.addEventListener('change', function() {
						selectedFiles = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
						renderSelectedFilesList();
					});

					if (selectedFilesList) {
						selectedFilesList.addEventListener('click', function(event) {
							var target = event.target;
							if (!target || !target.classList.contains('rawatwp-selected-files-remove')) {
								return;
							}

							if ('1' === uploadForm.getAttribute('data-uploading')) {
								return;
							}

							var index = Number(target.getAttribute('data-index'));
							if (Number.isNaN(index) || index < 0 || index >= selectedFiles.length) {
								return;
							}

							selectedFiles.splice(index, 1);
							syncInputFilesFromSelected();
							renderSelectedFilesList();
						});
					}

					if (clearSelectedFilesBtn) {
						clearSelectedFilesBtn.addEventListener('click', function() {
							if ('1' === uploadForm.getAttribute('data-uploading')) {
								return;
							}

							selectedFiles = [];
							syncInputFilesFromSelected();
							renderSelectedFilesList();
						});
					}

					uploadForm.addEventListener('submit', function(event) {
						var files = selectedFiles.slice();
						if ('1' === uploadForm.getAttribute('data-uploading')) {
							event.preventDefault();
							return;
						}
						if (!files.length) {
							event.preventDefault();
							return;
						}

						event.preventDefault();
						uploadForm.setAttribute('data-uploading', '1');
						cancelRequested = false;
						activeXhr = null;
						updateUploadButtonsState();

						if (uploadMonitor) {
							uploadMonitor.hidden = false;
						}
						if (uploadItems) {
							uploadItems.innerHTML = '';
						}

						var total = files.length;
						var finished = 0;
						var success = 0;
						var failed = 0;
						uploadedRows = files.map(function(file) {
							return createUploadRow(file.name);
						});

						setSummary('Preparing upload...');

						function refreshSummary() {
							var overall = total > 0 ? (finished / total) * 100 : 0;
							setSummary(
								'Total: ' + total +
								' | Completed: ' + finished +
								' | Success: ' + success +
								' | Failed: ' + failed +
								' | Overall: ' + overall.toFixed(2) + '%'
							);
						}

						function finishBatch() {
							uploadForm.removeAttribute('data-uploading');
							activeXhr = null;
							refreshSummary();
							updateUploadButtonsState();

							if (cancelRequested && success <= 0) {
								setSummary('Upload canceled. No files were uploaded.');
							} else if (cancelRequested && success > 0) {
								setSummary('Upload canceled. Some files were uploaded successfully.');
							} else if (success > 0) {
								setSummary((failed > 0 ? 'Upload finished with some failures.' : 'Upload finished successfully.') + ' Refreshing package list...');
								setTimeout(function() {
									window.location.reload();
								}, 1200);
							}
						}

						function uploadFileAt(index) {
							if (cancelRequested) {
								for (var i = index; i < files.length; i++) {
									finished++;
									failed++;
									updateUploadRow(uploadedRows[i], 0, 'Upload canceled by user.', 'failed');
								}
								refreshSummary();
								finishBatch();
								return;
							}

							if (index >= files.length) {
								finishBatch();
								return;
							}

							var file = files[index];
							var row = uploadedRows[index];
							var progressValue = 0;
							var lastActivity = Date.now();

							updateUploadRow(row, 0, 'Uploading...', 'uploading');
							refreshSummary();

							var xhr = new XMLHttpRequest();
							activeXhr = xhr;
							xhr.open('POST', ajaxUrl, true);
							xhr.timeout = 900000;

							var stallWatcher = window.setInterval(function() {
								if (Date.now() - lastActivity > 45000) {
									window.clearInterval(stallWatcher);
									xhr.abort();
								}
							}, 5000);

							xhr.upload.addEventListener('progress', function(e) {
								lastActivity = Date.now();
								if (!e.lengthComputable) {
									return;
								}
								progressValue = (e.loaded / e.total) * 100;
								updateUploadRow(row, progressValue, 'Uploading...', 'uploading');
							});

							xhr.upload.addEventListener('load', function() {
								lastActivity = Date.now();
								updateUploadRow(row, 100, 'Validating...', 'validating');
							});

							xhr.onreadystatechange = function() {
								lastActivity = Date.now();
								if (xhr.readyState >= 3) {
									updateUploadRow(row, 100, 'Saving...', 'saving');
								}
							};

							xhr.onload = function() {
								window.clearInterval(stallWatcher);
								activeXhr = null;
								finished++;

								var ok = false;
								var message = '';
								try {
									var payload = JSON.parse(xhr.responseText || '{}');
									if (xhr.status >= 200 && xhr.status < 300 && payload && payload.success) {
										ok = true;
										message = payload.data && payload.data.message ? payload.data.message : 'Uploaded successfully.';
									} else {
										message = payload && payload.data && payload.data.message ? payload.data.message : parseErrorMessage(xhr);
									}
								} catch (e) {
									message = parseErrorMessage(xhr);
								}

								if (ok) {
									success++;
									updateUploadRow(row, 100, message, 'success');
								} else {
									failed++;
									updateUploadRow(row, progressValue, message, 'failed');
								}

								refreshSummary();
								uploadFileAt(index + 1);
							};

							xhr.onerror = function() {
								window.clearInterval(stallWatcher);
								activeXhr = null;
								finished++;
								failed++;
								updateUploadRow(row, progressValue, 'Upload failed: network error.', 'failed');
								refreshSummary();
								uploadFileAt(index + 1);
							};

							xhr.onabort = function() {
								window.clearInterval(stallWatcher);
								activeXhr = null;
								finished++;
								failed++;
								if (cancelRequested) {
									updateUploadRow(row, progressValue, 'Upload canceled by user.', 'failed');
								} else {
									updateUploadRow(row, progressValue, 'Upload interrupted: no activity detected.', 'failed');
								}
								refreshSummary();
								uploadFileAt(index + 1);
							};

							xhr.ontimeout = function() {
								window.clearInterval(stallWatcher);
								activeXhr = null;
								finished++;
								failed++;
								updateUploadRow(row, progressValue, 'Upload timeout: server did not respond in time.', 'failed');
								refreshSummary();
								uploadFileAt(index + 1);
							};

							var formData = new FormData();
							formData.append('action', 'rawatwp_upload_package_item');
							formData.append('_ajax_nonce', ajaxNonce);
							formData.append('package_zip_item', file, file.name);
							xhr.send(formData);
						}

						uploadFileAt(0);
					});

					renderSelectedFilesList();
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render updates page.
	 *
	 * @return void
	 */
	public function render_updates_page() {
		$this->assert_admin();
		$mode = $this->mode_manager->get_mode();
		?>
			<div class="wrap rawatwp-admin">
				<h1>RawatWP - Updates</h1>
				<p class="rawatwp-page-subtitle">Send updates to child sites with a simple workflow.</p>
				<?php $this->render_notices(); ?>
				<?php if ( 'master' !== $mode ) : ?>
					<div class="rawatwp-card">
						<p>This page is available only in Master mode.</p>
					</div>
				<?php else : ?>
					<?php
					$packages          = $this->package_manager->get_packages();
					$child_sites       = $this->master_manager->get_sites();
					$health_data_by_site = $this->build_sites_health_overview( $child_sites );
					$queue_rows        = $this->queue_manager->get_queue_rows( 200 );
					$queue_counts      = $this->queue_manager->get_queue_counts();
					$queue_paused      = $this->queue_manager->is_paused();
					$runner_token      = $this->security->get_queue_runner_token();
					$runner_url        = add_query_arg(
						array(
							'token' => $runner_token,
							'limit' => 1,
						),
						rest_url( 'rawatwp/v1/master/queue-run' )
					);
					$has_pending       = $this->has_pending_queue_items( $queue_rows );
					$ajax_nonce        = wp_create_nonce( 'rawatwp_queue_ajax' );
					$on_queue_count    = isset( $queue_counts['on_queue'] ) ? (int) $queue_counts['on_queue'] : 0;
					$processing_count  = isset( $queue_counts['processing'] ) ? (int) $queue_counts['processing'] : 0;
					$success_count     = isset( $queue_counts['success'] ) ? (int) $queue_counts['success'] : 0;
					$failed_count      = isset( $queue_counts['failed'] ) ? (int) $queue_counts['failed'] : 0;
					$needs_update_site = 0;
					$needs_by_url      = array();
					foreach ( $child_sites as $site_summary ) {
						$url         = isset( $site_summary['site_url'] ) ? (string) $site_summary['site_url'] : '';
						$site_id     = isset( $site_summary['id'] ) ? (int) $site_summary['id'] : 0;
						$site_health = ( $site_id > 0 && isset( $health_data_by_site[ $site_id ] ) && is_array( $health_data_by_site[ $site_id ] ) ) ? $health_data_by_site[ $site_id ] : array();
						$needs_count = ( isset( $site_health['counts']['total'] ) && 'failed' !== ( isset( $site_health['status'] ) ? sanitize_key( (string) $site_health['status'] ) : 'success' ) ) ? (int) $site_health['counts']['total'] : 0;
						if ( $needs_count > 0 ) {
							$needs_update_site++;
						}
						if ( '' !== $url ) {
							$needs_by_url[ $url ] = $needs_count;
						}
					}
					$queue_total_active = $on_queue_count + $processing_count;
					$status_labels      = array(
						'on_queue'   => 'On Queue',
						'processing' => 'Processing',
						'success'    => 'Success',
						'failed'     => 'Failed',
					);
					?>
					<div class="rawatwp-card">
						<h2>Quick Summary</h2>
						<div class="rawatwp-update-kpis">
						<div class="rawatwp-update-kpi">
							<span class="label">Total Child Sites</span>
							<span class="value"><?php echo esc_html( (string) count( $child_sites ) ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Sites Needing Updates</span>
							<span class="value"><?php echo esc_html( (string) $needs_update_site ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Processing / Queued</span>
							<span class="value"><?php echo esc_html( (string) $queue_total_active ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Success / Failed</span>
							<span class="value"><?php echo esc_html( (string) $success_count . ' / ' . (string) $failed_count ); ?></span>
							</div>
						</div>

						<div class="rawatwp-updates-controls">
							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'rawatwp_queue_run_now' ); ?>
								<input type="hidden" name="action" value="rawatwp_queue_run_now" />
								<?php submit_button( 'Run Queue Now', 'secondary', 'submit', false ); ?>
							</form>

							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'rawatwp_queue_pause_toggle' ); ?>
								<input type="hidden" name="action" value="rawatwp_queue_pause_toggle" />
								<input type="hidden" name="pause_value" value="<?php echo $queue_paused ? '0' : '1'; ?>" />
								<?php submit_button( $queue_paused ? 'Resume Queue' : 'Pause Queue', 'secondary', 'submit', false ); ?>
							</form>
						</div>
						<p class="description">Current queue status: <strong><?php echo $queue_paused ? 'Paused' : 'Active'; ?></strong></p>
					</div>

					<div class="rawatwp-card">
						<h2>Send Update</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_push_update' ); ?>
						<input type="hidden" name="action" value="rawatwp_push_update" />
						<table class="form-table" role="presentation">
							<tr>
							<th scope="row">Select Package</th>
							<td>
								<select name="package_id" required>
									<option value="">Select package</option>
									<?php foreach ( $packages as $package ) : ?>
										<option value="<?php echo esc_attr( (string) $package['id'] ); ?>"><?php echo esc_html( $this->format_package_label_for_display( $package ) . ' (' . $this->format_package_type_for_display( isset( $package['type'] ) ? (string) $package['type'] : '' ) . ' / ' . $package['target_slug'] . ' / ' . $this->format_package_source_for_display( $package ) . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							</tr>
							<tr>
								<th scope="row">Select Child Sites</th>
								<td>
									<label class="rawatwp-select-all-sites">
										<input type="checkbox" id="rawatwp-select-all-sites" />
										Select all child sites
									</label>
									<div class="rawatwp-updates-site-list">
										<?php foreach ( $child_sites as $site ) : ?>
											<?php
											$site_url    = isset( $site['site_url'] ) ? (string) $site['site_url'] : '';
											$needs_count = isset( $needs_by_url[ $site_url ] ) ? (int) $needs_by_url[ $site_url ] : 0;
											?>
											<label class="rawatwp-updates-site-item">
												<input class="rawatwp-site-check" type="checkbox" name="site_ids[]" value="<?php echo esc_attr( (string) $site['id'] ); ?>" />
												<span class="rawatwp-updates-site-name"><?php echo esc_html( $site['site_name'] . ' (' . $site_url . ')' ); ?></span>
												<span class="rawatwp-updates-site-meta">
													<?php
													echo esc_html(
														sprintf(
															'Status: %s | Needs update: %d item(s)',
															isset( $site['connection_status'] ) ? (string) $site['connection_status'] : '-',
															$needs_count
														)
													);
													?>
												</span>
											</label>
										<?php endforeach; ?>
									</div>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Send Update Now' ); ?>
						</form>
					</div>

					<div class="rawatwp-card" id="rawatwp-update-progress">
						<div class="rawatwp-card-header">
							<h2>Update Progress</h2>
							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear all completed update progress rows? Running queue items will stay untouched.');">
								<?php wp_nonce_field( 'rawatwp_clear_update_progress' ); ?>
								<input type="hidden" name="action" value="rawatwp_clear_update_progress" />
								<?php submit_button( 'Clear Progress', 'secondary', 'submit', false ); ?>
							</form>
						</div>
						<p class="description">Auto-clean is active: completed progress rows older than 30 days are removed, and total completed rows are capped at 10,000.</p>
						<table class="widefat striped">
						<thead>
							<tr>
								<th>Site</th>
								<th>Package</th>
								<th>Status</th>
								<th>Progress</th>
								<th>Updated</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $queue_rows ) ) : ?>
								<tr>
									<td colspan="5">No update process yet.</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $queue_rows as $row ) : ?>
									<?php
									$status_key   = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
									$status_label = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : ucfirst( $status_key );
									$detail_text  = '';
									$progress     = isset( $row['progress'] ) ? (float) $row['progress'] : 0.0;
									$progress     = max( 0.0, min( 100.0, $progress ) );
									$progress_txt = number_format( $progress, 2, '.', '' );
									if ( 'failed' === $status_key ) {
										$detail_text = '' !== (string) $row['message'] ? (string) $row['message'] : ( isset( $row['reason_code'] ) ? (string) $row['reason_code'] : '' );
									}
									?>
									<tr>
										<td><?php echo esc_html( $row['site_name'] . ( '' !== $row['site_url'] ? ' (' . $row['site_url'] . ')' : '' ) ); ?></td>
										<td><?php echo esc_html( $row['item_label'] . ( '' !== $row['item_type'] ? ' [' . $row['item_type'] . ':' . $row['item_slug'] . ']' : '' ) ); ?></td>
										<td>
											<span class="rawatwp-status-badge rawatwp-status-<?php echo esc_attr( $status_key ); ?>"><?php echo esc_html( $status_label ); ?></span>
											<?php if ( '' !== $detail_text ) : ?>
												<div class="description"><?php echo esc_html( $detail_text ); ?></div>
											<?php endif; ?>
										</td>
											<td class="rawatwp-progress-cell">
											<div class="rawatwp-progress-wrap">
												<div class="rawatwp-progress-bar" style="width:<?php echo esc_attr( $progress_txt ); ?>%;"></div>
											</div>
											<?php echo esc_html( $progress_txt ); ?>%
										</td>
										<td><?php echo esc_html( $this->format_datetime_for_display( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
						</table>

						<details class="rawatwp-advanced-updates">
							<summary>Advanced Settings</summary>
							<p class="rawatwp-mt-2"><label><input type="checkbox" id="rawatwp-browser-worker-enable" checked> Enable browser worker while this page is open</label></p>
							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rawatwp_regenerate_runner_token' ); ?>
							<input type="hidden" name="action" value="rawatwp_regenerate_runner_token" />
							<?php submit_button( 'Regenerate Runner Token', 'secondary', 'submit', false ); ?>
							</form>
							<p class="description">Runner URL for system cron (every 1 minute):</p>
							<p><code class="rawatwp-break-word"><?php echo esc_html( $runner_url ); ?></code></p>
						</details>
					</div>
					<script>
					(function() {
						if (window.location.hash === '#rawatwp-update-progress') {
							window.setTimeout(function() {
								var updateProgressCard = document.getElementById('rawatwp-update-progress');
								if (!updateProgressCard) {
									return;
								}
								updateProgressCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
							}, 120);
						}

						var hasPending = <?php echo (int) ( $has_pending ? 1 : 0 ); ?>;
						var paused = <?php echo (int) ( $queue_paused ? 1 : 0 ); ?>;
						var ajaxNonce = <?php echo wp_json_encode( $ajax_nonce ); ?>;
						var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
						var browserWorkerCheckbox = document.getElementById('rawatwp-browser-worker-enable');
						var selectAllSites = document.getElementById('rawatwp-select-all-sites');
						var siteChecks = document.querySelectorAll('.rawatwp-site-check');
						var workerBusy = false;

						if (selectAllSites) {
							selectAllSites.addEventListener('change', function() {
								siteChecks.forEach(function(item) {
									item.checked = selectAllSites.checked;
								});
							});
						}

						function runBrowserWorkerStep() {
							if (!hasPending || paused || workerBusy) {
							return;
						}
						if (!browserWorkerCheckbox || !browserWorkerCheckbox.checked) {
							return;
						}

						workerBusy = true;
						var formData = new FormData();
						formData.append('action', 'rawatwp_queue_process_next');
						formData.append('_ajax_nonce', ajaxNonce);

						fetch(ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						})
						.then(function() {
							setTimeout(function() {
								window.location.reload();
							}, 1200);
						})
						.catch(function() {
							workerBusy = false;
						});
					}

					if (hasPending && !paused) {
						setTimeout(runBrowserWorkerStep, 2000);
						setTimeout(function() {
							window.location.reload();
						}, 10000);
					}
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		$this->assert_admin();
		$this->logger->maybe_run_maintenance( 30, 10000, 1800 );
		$logs = $this->logger->get_logs( 300 );
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - Logs</h1>
			<p class="rawatwp-page-subtitle">Update and connection activity history for RawatWP.</p>
			<?php $this->render_notices(); ?>
			<div class="rawatwp-card">
				<p>Logs are auto-maintained: delete data older than 30 days and cap at latest 10,000 rows to keep database light.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Time</th>
							<th>Site</th>
							<th>Mode</th>
							<th>Item</th>
							<th>Action</th>
							<th>Status</th>
							<th>Message</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $this->format_datetime_for_display( isset( $log['created_at'] ) ? $log['created_at'] : '' ) ); ?></td>
								<td><?php echo esc_html( $log['site_url'] ); ?></td>
								<td><?php echo esc_html( $this->format_log_mode_for_display( isset( $log['mode'] ) ? (string) $log['mode'] : '' ) ); ?></td>
								<td><?php echo esc_html( $this->format_log_item_for_display( isset( $log['item_type'] ) ? (string) $log['item_type'] : '', isset( $log['item_slug'] ) ? (string) $log['item_slug'] : '' ) ); ?></td>
								<td><?php echo esc_html( $this->format_log_action_for_display( isset( $log['action'] ) ? (string) $log['action'] : '' ) ); ?></td>
								<td><?php echo esc_html( $this->format_log_status_for_display( isset( $log['status'] ) ? (string) $log['status'] : '' ) ); ?></td>
								<td><?php echo esc_html( $this->format_log_message_for_display( $log ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="rawatwp-log-actions">
					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear all RawatWP logs?');">
						<?php wp_nonce_field( 'rawatwp_clear_logs' ); ?>
						<input type="hidden" name="action" value="rawatwp_clear_logs" />
						<?php submit_button( 'Clear Logs', 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save general settings in one action.
	 *
	 * @return void
	 */
	public function handle_save_general_settings() {
		$this->assert_admin_post( 'rawatwp_save_general_settings' );

		$mode   = isset( $_POST['rawatwp_mode'] ) ? sanitize_key( wp_unslash( $_POST['rawatwp_mode'] ) ) : '';
		$result = $this->mode_manager->set_mode( $mode );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-general', '', $result->get_error_message() );
		}

		$enabled = ! empty( $_POST['delete_all_on_uninstall'] ) ? '1' : '0';
		update_option( 'rawatwp_delete_all_on_uninstall', $enabled, false );

		$this->github_updater->save_settings(
			array(
			)
		);

		$redirect_page = 'child' === $mode ? 'rawatwp-connection' : 'rawatwp-general';
		$this->redirect_with_notice( $redirect_page, 'Settings saved successfully.', '' );
	}

	/**
	 * Save connection data and connect/register to master.
	 *
	 * @return void
	 */
	public function handle_save_and_connect() {
		$this->assert_admin_post( 'rawatwp_save_and_connect' );

		$master_url   = isset( $_POST['master_url'] ) ? wp_unslash( $_POST['master_url'] ) : '';
		$security_key = isset( $_POST['security_key'] ) ? wp_unslash( $_POST['security_key'] ) : '';

		$this->child_manager->save_settings( $master_url, $security_key );
		$result = $this->child_manager->connect_to_master();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-connection', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-connection', 'Connected to Master.', '' );
	}

	/**
	 * Disconnect child from master.
	 *
	 * @return void
	 */
	public function handle_disconnect_child() {
		$this->assert_admin_post( 'rawatwp_disconnect_child' );

		if ( ! $this->child_manager->disconnect() ) {
			$this->redirect_with_notice( 'rawatwp-connection', '', 'Disconnect failed.' );
		}

		$this->redirect_with_notice( 'rawatwp-connection', 'Child disconnected.', '' );
	}

	/**
	 * Handle add monitored item.
	 *
	 * @return void
	 */
	public function handle_add_monitored_item() {
		$this->assert_admin_post( 'rawatwp_add_item' );

		$item_type  = isset( $_POST['item_type'] ) ? wp_unslash( $_POST['item_type'] ) : 'plugin';
		$item_label = isset( $_POST['item_label'] ) ? wp_unslash( $_POST['item_label'] ) : '';
		$item_slug  = isset( $_POST['item_slug'] ) ? wp_unslash( $_POST['item_slug'] ) : '';

		$result = $this->monitored_items->add_item(
			array(
				'type'            => $item_type,
				'slug'            => $item_slug,
				'label'           => $item_label,
				'current_version' => isset( $_POST['item_current_version'] ) ? wp_unslash( $_POST['item_current_version'] ) : '',
				'needs_update'    => ! empty( $_POST['item_needs_update'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Monitored item added.', '' );
	}

	/**
	 * Handle toggle monitored item needs_update.
	 *
	 * @return void
	 */
	public function handle_toggle_monitored_item() {
		$this->assert_admin_post( 'rawatwp_toggle_item' );

		$item_id      = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
		$needs_update = ! empty( $_POST['needs_update'] );

		if ( ! $this->monitored_items->set_needs_update( $item_id, $needs_update ) ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'Failed to update monitored item status.' );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Monitored item status updated.', '' );
	}

	/**
	 * Handle delete monitored item.
	 *
	 * @return void
	 */
	public function handle_delete_monitored_item() {
		$this->assert_admin_post( 'rawatwp_delete_item' );

		$item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';

		if ( ! $this->monitored_items->remove_item( $item_id ) ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'Monitored item not found.' );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Monitored item deleted.', '' );
	}

	/**
	 * Handle scan now (report needs update to master).
	 *
	 * @return void
	 */
	public function handle_scan_now() {
		$this->assert_admin_post( 'rawatwp_scan_now' );

		$result = $this->child_manager->send_report_to_master();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Needs-update report sent to master.', '' );
	}

	/**
	 * Handle scan installed plugins/themes and import into monitored items.
	 *
	 * @return void
	 */
	public function handle_scan_installed_items() {
		$this->assert_admin_post( 'rawatwp_scan_installed_items' );

		if ( 'child' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'This feature is available only in Child mode.' );
		}

		$result = $this->monitored_items->import_installed_items();

		$message = sprintf(
			'Scan complete. Added: %d, Synced: %d, Plugins found: %d, Themes found: %d, Needs Update detected: %d.',
			(int) $result['added'],
			(int) $result['updated'],
			(int) $result['plugins_found'],
			(int) $result['themes_found'],
			(int) $result['auto_needs_update']
		);

		$this->redirect_with_notice( 'rawatwp-monitored-items', $message, '' );
	}

	/**
	 * Handle add site (master pre-register).
	 *
	 * @return void
	 */
	public function handle_add_site() {
		$this->assert_admin_post( 'rawatwp_add_site' );

		$site_name    = isset( $_POST['site_name'] ) ? wp_unslash( $_POST['site_name'] ) : '';
		$site_url     = isset( $_POST['site_url'] ) ? wp_unslash( $_POST['site_url'] ) : '';
		$security_key = isset( $_POST['security_key'] ) ? wp_unslash( $_POST['security_key'] ) : '';

		$result = $this->master_manager->add_site( $site_name, $site_url, $security_key );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-sites', 'Child site added successfully.', '' );
	}

	/**
	 * Handle regenerate site key.
	 *
	 * @return void
	 */
	public function handle_regenerate_site_key() {
		$this->assert_admin_post( 'rawatwp_regen_key' );

		$site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
		$result  = $this->master_manager->regenerate_site_key( $site_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-sites', 'Child security key regenerated successfully.', '' );
	}

	/**
	 * Run manual update check for one child site.
	 *
	 * @return void
	 */
	public function handle_check_site_updates() {
		$this->assert_admin_post( 'rawatwp_check_site_updates' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'This feature is available only in Master mode.' );
		}

		$site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
		if ( $site_id <= 0 ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'Invalid child site.' );
		}

		$result = $this->master_manager->request_site_updates_snapshot( $site_id );
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg(
				array(
					'page' => 'rawatwp-sites',
				),
				admin_url( 'admin.php' )
			) . '#rawatwp-update-health';
			$this->redirect_to_url_with_notice( $url, '', $result->get_error_message() );
		}

		$site_name = isset( $result['site']['site_name'] ) ? sanitize_text_field( (string) $result['site']['site_name'] ) : 'Child site';
		$snapshot  = isset( $result['snapshot'] ) && is_array( $result['snapshot'] ) ? $result['snapshot'] : array();
		$themes    = isset( $snapshot['counts']['themes'] ) ? (int) $snapshot['counts']['themes'] : 0;
		$plugins   = isset( $snapshot['counts']['plugins'] ) ? (int) $snapshot['counts']['plugins'] : 0;
		$total     = isset( $snapshot['counts']['total'] ) ? (int) $snapshot['counts']['total'] : ( $themes + $plugins );
		$core_text = ( isset( $snapshot['core']['needs_update'] ) && ! empty( $snapshot['core']['needs_update'] ) ) ? 'needs update' : 'up to date';

		$notice = sprintf(
			'Update check completed for %s. Core: %s, Themes: %d, Plugins: %d, Total: %d.',
			$site_name,
			$core_text,
			$themes,
			$plugins,
			$total
		);

		$url = add_query_arg(
			array(
				'page' => 'rawatwp-sites',
			),
			admin_url( 'admin.php' )
		) . '#rawatwp-update-health';

		$this->redirect_to_url_with_notice( $url, $notice, '' );
	}

	/**
	 * Run manual update check for all connected child sites.
	 *
	 * @return void
	 */
	public function handle_check_all_site_updates() {
		$this->assert_admin_post( 'rawatwp_check_all_site_updates' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'This feature is available only in Master mode.' );
		}

		$sites    = $this->master_manager->get_sites();
		$site_ids = array();
		foreach ( $sites as $site ) {
			if ( isset( $site['connection_status'] ) && 'connected' === sanitize_key( (string) $site['connection_status'] ) ) {
				$site_ids[] = (int) $site['id'];
			}
		}

		if ( empty( $site_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'No connected child sites found.' );
		}

		$result = $this->queue_manager->enqueue_update_check_batch( $site_ids );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', $result->get_error_message() );
		}

		$queued  = isset( $result['queued'] ) ? (int) $result['queued'] : 0;
		$skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

		$notice = sprintf(
			'Update check has been queued for %d connected site(s). You can monitor progress in "Update Progress".',
			$queued
		);
		if ( $skipped > 0 ) {
			$notice .= ' ' . sprintf( '%d site(s) were skipped because they are unavailable or disconnected.', $skipped );
		}

		$url = add_query_arg(
			array(
				'page' => 'rawatwp-updates',
			),
			admin_url( 'admin.php' )
		) . '#rawatwp-update-progress';

		$this->redirect_to_url_with_notice( $url, $notice, '' );
	}

	/**
	 * Queue RawatWP plugin update to all connected child sites.
	 *
	 * @return void
	 */
	public function handle_queue_rawatwp_update_all_sites() {
		$this->assert_admin_post( 'rawatwp_queue_rawatwp_update_all_sites' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'This feature is available only in Master mode.' );
		}

		$sites    = $this->master_manager->get_sites();
		$site_ids = array();
		foreach ( $sites as $site ) {
			if ( isset( $site['connection_status'] ) && 'connected' === sanitize_key( (string) $site['connection_status'] ) ) {
				$site_ids[] = (int) $site['id'];
			}
		}

		if ( empty( $site_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'No connected child sites found.' );
		}

		$package = $this->package_manager->ensure_rawatwp_self_package();
		if ( is_wp_error( $package ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', $package->get_error_message() );
		}

		if ( ! is_array( $package ) || empty( $package['id'] ) || 'plugin' !== (string) $package['type'] || 'rawatwp' !== sanitize_key( (string) $package['target_slug'] ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', 'Failed to prepare valid RawatWP package.' );
		}

		$result = $this->queue_manager->enqueue_batch( (int) $package['id'], $site_ids );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-sites', '', $result->get_error_message() );
		}

		$message = sprintf(
			'RawatWP update request saved. %d connected site(s) are now in queue. Check "Update Progress" below.',
			count( $site_ids )
		);
		if ( ! empty( $result['skipped'] ) ) {
			$message .= ' ' . sprintf(
				'%d item(s) were skipped because they were already in queue or not eligible.',
				(int) $result['skipped']
			);
		}
		$updates_url = add_query_arg(
			array(
				'page' => 'rawatwp-updates',
			),
			admin_url( 'admin.php' )
		);
		$updates_url .= '#rawatwp-update-progress';
		$this->redirect_to_url_with_notice( $updates_url, $message, '' );
	}

	/**
	 * Handle package upload.
	 *
	 * @return void
	 */
	public function handle_upload_package() {
		$this->assert_admin_post( 'rawatwp_upload_package' );

		if ( empty( $_FILES['package_zip'] ) || ! is_array( $_FILES['package_zip'] ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Zip file not found.' );
		}

		$uploaded = $_FILES['package_zip'];
		$files    = array();

		if ( isset( $uploaded['name'] ) && is_array( $uploaded['name'] ) ) {
			foreach ( $uploaded['name'] as $index => $name ) {
				if ( empty( $name ) ) {
					continue;
				}
				$files[] = array(
					'name'     => $name,
					'type'     => isset( $uploaded['type'][ $index ] ) ? $uploaded['type'][ $index ] : '',
					'tmp_name' => isset( $uploaded['tmp_name'][ $index ] ) ? $uploaded['tmp_name'][ $index ] : '',
					'error'    => isset( $uploaded['error'][ $index ] ) ? $uploaded['error'][ $index ] : 0,
					'size'     => isset( $uploaded['size'][ $index ] ) ? $uploaded['size'][ $index ] : 0,
				);
			}
		} else {
			$files[] = $uploaded;
		}

		if ( empty( $files ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'No zip file selected.' );
		}

		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $files as $file ) {
			$result = $this->package_manager->upload_package( $file );
			if ( is_wp_error( $result ) ) {
				$failed++;
				$errors[] = $result->get_error_message();
				continue;
			}
			$success++;
		}

		if ( $failed > 0 ) {
			$error = sprintf(
				'Upload completed with partial failures. Success: %d, Failed: %d. %s',
				$success,
				$failed,
				implode( ' | ', array_slice( $errors, 0, 2 ) )
			);
			$this->redirect_with_notice( 'rawatwp-packages', '', $error );
		}

		$this->redirect_with_notice( 'rawatwp-packages', sprintf( '%d zip file(s) uploaded successfully.', $success ), '' );
	}

	/**
	 * Handle single package upload via AJAX for per-file progress.
	 *
	 * @return void
	 */
	public function handle_ajax_upload_package_item() {
		$this->assert_admin();
		check_ajax_referer( 'rawatwp_upload_package_item' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			wp_send_json_error(
				array(
					'message' => 'This feature is available only in Master mode.',
				),
				403
			);
		}

		if ( empty( $_FILES['package_zip_item'] ) || ! is_array( $_FILES['package_zip_item'] ) ) {
			wp_send_json_error(
				array(
					'message' => 'Zip file not found.',
				),
				400
			);
		}

		$file      = $_FILES['package_zip_item'];
		$file_name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$result    = $this->package_manager->upload_package( $file );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				array(
					'mode'      => 'master',
					'action'    => 'package_upload_failed',
					'status'    => 'failed',
					'item_type' => 'unknown',
					'item_slug' => '',
					'message'   => sprintf(
						'Package upload failed%s: %s',
						'' !== $file_name ? ' (' . $file_name . ')' : '',
						$result->get_error_message()
					),
					'context'   => array(
						'file_name' => $file_name,
						'error'     => $result->get_error_code(),
					),
				)
			);

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf( 'Uploaded successfully: %s', '' !== $file_name ? $file_name : 'zip file' ),
				'package' => is_array( $result ) ? $result : array(),
			)
		);
	}

	/**
	 * Handle folder scan: /public_html/updates.
	 *
	 * @return void
	 */
	public function handle_scan_updates_folder() {
		$this->assert_admin_post( 'rawatwp_scan_updates_folder' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'This feature is available only in Master mode.' );
		}

		$result = $this->package_manager->scan_updates_directory();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', $result->get_error_message() );
		}

		$message = $this->format_scan_result_notice( $result );

		$this->redirect_with_notice( 'rawatwp-packages', $message, '' );
	}

	/**
	 * Handle single package delete.
	 *
	 * @return void
	 */
	public function handle_delete_package() {
		$this->assert_admin_post( 'rawatwp_delete_package' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'This feature is available only in Master mode.' );
		}

		$package_id = isset( $_POST['package_id'] ) ? (int) $_POST['package_id'] : 0;
		if ( $package_id <= 0 ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Invalid package.' );
		}

		$result = $this->package_manager->delete_package( $package_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-packages', 'Package deleted successfully.', '' );
	}

	/**
	 * Handle bulk package delete.
	 *
	 * @return void
	 */
	public function handle_bulk_delete_packages() {
		$this->assert_admin_post( 'rawatwp_bulk_delete_packages' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'This feature is available only in Master mode.' );
		}

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$package_ids = isset( $_POST['package_ids'] ) && is_array( $_POST['package_ids'] ) ? array_map( 'intval', $_POST['package_ids'] ) : array();

		if ( 'delete' !== $bulk_action ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Please select a bulk action first.' );
		}

		if ( empty( $package_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Select at least one package.' );
		}

		$result = $this->package_manager->delete_packages( $package_ids );
		if ( (int) $result['failed'] > 0 ) {
			$error = sprintf(
				'Bulk delete completed with partial failures. Success: %d, Failed: %d.',
				(int) $result['deleted'],
				(int) $result['failed']
			);
			$this->redirect_with_notice( 'rawatwp-packages', '', $error );
		}

		$notice = sprintf( 'Bulk delete completed. %d package(s) deleted.', (int) $result['deleted'] );
		$this->redirect_with_notice( 'rawatwp-packages', $notice, '' );
	}

	/**
	 * Handle push update command.
	 *
	 * @return void
	 */
	public function handle_push_update() {
		$this->assert_admin_post( 'rawatwp_push_update' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'This feature is available only in Master mode.' );
		}

		$package_id = isset( $_POST['package_id'] ) ? (int) $_POST['package_id'] : 0;
		$site_ids   = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ? array_map( 'intval', $_POST['site_ids'] ) : array();

		if ( $package_id <= 0 || empty( $site_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Select one package and at least one child site.' );
		}

		$result = $this->queue_manager->enqueue_batch( $package_id, $site_ids );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', $result->get_error_message() );
		}

		$site_count = isset( $result['site_count'] ) ? (int) $result['site_count'] : count( $site_ids );
		$queued     = isset( $result['queued'] ) ? (int) $result['queued'] : 0;
		$skipped    = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

		if ( ! empty( $result['is_patch_sequence'] ) ) {
			$numbers_text = '';
			if ( ! empty( $result['patch_numbers'] ) && is_array( $result['patch_numbers'] ) ) {
				$numbers_text = implode( ' -> ', array_map( 'intval', $result['patch_numbers'] ) );
			}

			$message = sprintf(
				'Patch update request saved for %d site(s).',
				$site_count
			);
			$message .= ' ' . sprintf(
				'Total queued task(s): %d.',
				$queued
			);
			if ( '' !== $numbers_text ) {
				$message .= ' ' . sprintf(
					'Patch order: %s.',
					$numbers_text
				);
			}
			$message .= ' Check "Update Progress" below.';
		} else {
			$message = sprintf(
				'Update request saved. %d site(s) are now in queue. Check "Update Progress" below.',
				$queued
			);
		}

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				'%d item(s) were skipped because they were already in queue or not eligible.',
				$skipped
			);
		}

		$updates_url = add_query_arg(
			array(
				'page' => 'rawatwp-updates',
			),
			admin_url( 'admin.php' )
		);
		$updates_url .= '#rawatwp-update-progress';
		$this->redirect_to_url_with_notice( $updates_url, $message, '' );
	}

	/**
	 * Handle clear logs action.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		$this->assert_admin_post( 'rawatwp_clear_logs' );

		if ( ! $this->logger->clear_logs() ) {
			$this->redirect_with_notice( 'rawatwp-logs', '', 'Failed to clear logs.' );
		}

		$this->redirect_with_notice( 'rawatwp-logs', 'Logs cleared successfully.', '' );
	}

	/**
	 * Handle clear update progress action.
	 *
	 * @return void
	 */
	public function handle_clear_update_progress() {
		$this->assert_admin_post( 'rawatwp_clear_update_progress' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'This feature is available only in Master mode.' );
		}

		$deleted = (int) $this->queue_manager->clear_finished_queue_history();
		$this->redirect_with_notice(
			'rawatwp-updates',
			sprintf( 'Update progress cleared. Removed %d completed row(s).', $deleted ),
			''
		);
	}

	/**
	 * Handle manual run queue once.
	 *
	 * @return void
	 */
	public function handle_queue_run_now() {
		$this->assert_admin_post( 'rawatwp_queue_run_now' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Queue feature is available only in Master mode.' );
		}

		$result = $this->queue_manager->run_worker( 'admin_manual', 1 );
		$notice = sprintf(
			'Queue processed manually. Processed: %d, Pending: %d, Processing: %d.',
			(int) $result['processed'],
			(int) $result['counts']['on_queue'],
			(int) $result['counts']['processing']
		);

		$this->redirect_with_notice( 'rawatwp-updates', $notice, '' );
	}

	/**
	 * Handle queue pause/resume.
	 *
	 * @return void
	 */
	public function handle_queue_pause_toggle() {
		$this->assert_admin_post( 'rawatwp_queue_pause_toggle' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Queue feature is available only in Master mode.' );
		}

		$pause_value = isset( $_POST['pause_value'] ) ? sanitize_key( wp_unslash( $_POST['pause_value'] ) ) : '0';
		if ( '1' === $pause_value ) {
			$this->queue_manager->pause_queue();
			$this->redirect_with_notice( 'rawatwp-updates', 'Queue paused.', '' );
		}

		$this->queue_manager->resume_queue();
		$this->redirect_with_notice( 'rawatwp-updates', 'Queue resumed.', '' );
	}

	/**
	 * Handle regenerate queue runner token.
	 *
	 * @return void
	 */
	public function handle_regenerate_runner_token() {
		$this->assert_admin_post( 'rawatwp_regenerate_runner_token' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Runner feature is available only in Master mode.' );
		}

		$this->security->regenerate_queue_runner_token();
		$this->redirect_with_notice( 'rawatwp-updates', 'Runner token regenerated successfully.', '' );
	}

	/**
	 * AJAX: browser worker process next queue item.
	 *
	 * @return void
	 */
	public function handle_ajax_queue_process_next() {
		$this->assert_admin();
		check_ajax_referer( 'rawatwp_queue_ajax' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			wp_send_json_error( array( 'message' => 'Queue feature is available only in Master mode.' ), 403 );
		}

		$result = $this->queue_manager->run_worker( 'browser_worker', 1 );

		wp_send_json_success(
			array(
				'processed' => (int) $result['processed'],
				'counts'    => isset( $result['counts'] ) ? $result['counts'] : array(),
				'paused'    => ! empty( $result['paused'] ),
				'locked'    => ! empty( $result['locked'] ),
			)
		);
	}

	/**
	 * AJAX: get queue summary status.
	 *
	 * @return void
	 */
	public function handle_ajax_queue_status() {
		$this->assert_admin();
		check_ajax_referer( 'rawatwp_queue_ajax' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			wp_send_json_error( array( 'message' => 'Queue feature is available only in Master mode.' ), 403 );
		}

		wp_send_json_success(
			array(
				'counts' => $this->queue_manager->get_queue_counts(),
				'paused' => $this->queue_manager->is_paused(),
			)
		);
	}

	/**
	 * Handle manual check now for self-update metadata.
	 *
	 * @return void
	 */
	public function handle_check_update_now() {
		$this->assert_admin_post( 'rawatwp_check_update_now' );
		$redirect_url = $this->get_requested_redirect_url();

		$result = $this->github_updater->force_check_now();
		if ( is_wp_error( $result ) ) {
			if ( '' !== $redirect_url ) {
				$this->redirect_to_url_with_notice( $redirect_url, '', $result->get_error_message() );
			}
			$this->redirect_with_notice( 'rawatwp-general', '', $result->get_error_message() );
		}

		$latest_version = is_array( $result ) && isset( $result['version'] ) ? sanitize_text_field( (string) $result['version'] ) : '-';
		$notice         = 'RawatWP update check completed, latest version: ' . $latest_version;

		if ( '' !== $redirect_url ) {
			$this->redirect_to_url_with_notice( $redirect_url, $notice, '' );
		}
		$this->redirect_with_notice( 'rawatwp-general', $notice, '' );
	}

	/**
	 * Render success/error notice on Plugins page after check update action.
	 *
	 * @return void
	 */
	public function render_plugins_page_notices() {
		if ( ! is_admin() ) {
			return;
		}

		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$this->render_notices();
	}

	/**
	 * Format package label to a readable UI string.
	 *
	 * @param array $package Package row.
	 * @return string
	 */
	private function format_package_label_for_display( array $package ) {
		$label     = isset( $package['label'] ) ? sanitize_text_field( (string) $package['label'] ) : '';
		$file_name = isset( $package['file_name'] ) ? sanitize_text_field( (string) $package['file_name'] ) : '';

		$is_machine_like = (bool) preg_match( '/^\d{8}[-_]\d{6}[-_]/', $label )
			|| ( false !== stripos( $label, '.zip.part' ) )
			|| ( false !== stripos( $label, '.zip' ) );

		$source = $is_machine_like || '' === $label ? $file_name : $label;
		$source = preg_replace( '/\.part$/i', '', (string) $source );
		$source = preg_replace( '/\.zip$/i', '', (string) $source );
		$source = preg_replace( '/^\d{8}[-_]\d{6}[-_]/', '', (string) $source );
		$source = preg_replace( '/[-_][A-Za-z0-9]{6}$/', '', (string) $source );
		$source = str_replace( array( '-', '_' ), ' ', (string) $source );
		$source = preg_replace( '/\s+/', ' ', (string) $source );
		$source = trim( (string) $source );

		if ( '' === $source ) {
			return $label;
		}

		return ucwords( $source );
	}

	/**
	 * Format stored file name for UI display.
	 *
	 * @param array $package Package row.
	 * @return string
	 */
	private function format_package_file_name_for_display( array $package ) {
		$file_name = isset( $package['file_name'] ) ? sanitize_text_field( (string) $package['file_name'] ) : '';
		if ( '' === $file_name ) {
			return '-';
		}

		$display = preg_replace( '/^\d{8}[-_]\d{6}[-_]/', '', $file_name );
		$display = preg_replace( '/[-_][A-Za-z0-9]{6}(\.zip)$/', '$1', (string) $display );

		return '' !== trim( (string) $display ) ? $display : $file_name;
	}

	/**
	 * Format package type for user-facing UI.
	 *
	 * @param string $type Raw package type.
	 * @return string
	 */
	private function format_package_type_for_display( $type ) {
		$type = sanitize_key( (string) $type );
		if ( 'core' === $type ) {
			return 'WordPress Core';
		}
		if ( 'theme' === $type ) {
			return 'Theme';
		}
		if ( 'plugin' === $type ) {
			return 'Plugin';
		}

		return ucfirst( $type );
	}

	/**
	 * Format package source for user-facing UI.
	 *
	 * @param array $package Package row.
	 * @return string
	 */
	private function format_package_source_for_display( array $package ) {
		$source_type = isset( $package['source_type'] ) ? sanitize_key( (string) $package['source_type'] ) : 'direct';
		$source_name = isset( $package['source_name'] ) ? sanitize_file_name( (string) $package['source_name'] ) : '';

		if ( 'patch_bundle' === $source_type ) {
			return '' !== $source_name ? 'Patch Source (' . $source_name . ')' : 'Patch Source';
		}

		if ( 'patch_source' === $source_type ) {
			return 'Patch Source';
		}

		return 'Direct';
	}

	/**
	 * Build a human-friendly notice for package scan result.
	 *
	 * @param array $result Scan result payload.
	 * @return string
	 */
	private function format_scan_result_notice( array $result ) {
		$total    = isset( $result['total'] ) ? (int) $result['total'] : 0;
		$imported = isset( $result['imported'] ) ? (int) $result['imported'] : 0;
		$skipped  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
		$failed   = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

		if ( $total <= 0 ) {
			return 'Scan complete. No zip files were found.';
		}

		if ( $imported > 0 && 0 === $failed && 0 === $skipped ) {
			return sprintf( 'Scan complete. Added %d new package(s).', $imported );
		}

		if ( 0 === $imported && 0 === $failed && $skipped === $total ) {
			return sprintf( 'Scan complete. %d zip file(s) found, all already in your package list.', $total );
		}

		if ( 0 === $imported && $failed > 0 ) {
			return sprintf( 'Scan complete. %d zip file(s) found, but none could be added. Failed: %d.', $total, $failed );
		}

		return sprintf(
			'Scan complete. %d zip file(s) found: %d added, %d already listed, %d failed.',
			$total,
			$imported,
			$skipped,
			$failed
		);
	}

	/**
	 * Format log mode for UI.
	 *
	 * @param string $mode Raw mode.
	 * @return string
	 */
	private function format_log_mode_for_display( $mode ) {
		$mode = sanitize_key( (string) $mode );

		$map = array(
			'master' => 'Master',
			'child'  => 'Child',
		);

		if ( isset( $map[ $mode ] ) ) {
			return $map[ $mode ];
		}

		if ( '' === $mode ) {
			return '-';
		}

		return ucwords( str_replace( '_', ' ', $mode ) );
	}

	/**
	 * Build per-site health overview from stored child update snapshots.
	 *
	 * @param array $sites Site rows.
	 * @return array
	 */
	private function build_sites_health_overview( array $sites ) {
		$results = array();

		foreach ( $sites as $site ) {
			$site_id = isset( $site['id'] ) ? (int) $site['id'] : 0;
			if ( $site_id <= 0 ) {
				continue;
			}

			$report = isset( $site['last_report'] ) && is_array( $site['last_report'] ) ? $site['last_report'] : array();
			if ( empty( $report['wp_update_check'] ) || ! is_array( $report['wp_update_check'] ) ) {
				$results[ $site_id ] = array();
				continue;
			}

			$snapshot = $report['wp_update_check'];
			$core     = isset( $snapshot['core'] ) && is_array( $snapshot['core'] ) ? $snapshot['core'] : array();
			$themes   = isset( $snapshot['themes'] ) && is_array( $snapshot['themes'] ) ? $snapshot['themes'] : array();
			$plugins  = isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array();
			$counts   = isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ? $snapshot['counts'] : array();

			$results[ $site_id ] = array(
				'checked_at'     => isset( $snapshot['checked_at'] ) ? sanitize_text_field( (string) $snapshot['checked_at'] ) : '',
				'status'         => isset( $snapshot['status'] ) ? sanitize_key( (string) $snapshot['status'] ) : 'success',
				'error_message'  => isset( $snapshot['error_message'] ) ? sanitize_text_field( (string) $snapshot['error_message'] ) : '',
				'core'           => array(
					'needs_update'    => ! empty( $core['needs_update'] ),
					'current_version' => isset( $core['current_version'] ) ? sanitize_text_field( (string) $core['current_version'] ) : '',
					'latest_version'  => isset( $core['latest_version'] ) ? sanitize_text_field( (string) $core['latest_version'] ) : '',
				),
				'themes'         => array_values(
					array_filter(
						array_map(
							function( $theme ) {
								if ( ! is_array( $theme ) ) {
									return array();
								}

								return array(
									'name'            => isset( $theme['name'] ) ? sanitize_text_field( (string) $theme['name'] ) : '',
									'current_version' => isset( $theme['current_version'] ) ? sanitize_text_field( (string) $theme['current_version'] ) : '',
									'new_version'     => isset( $theme['new_version'] ) ? sanitize_text_field( (string) $theme['new_version'] ) : '',
								);
							},
							$themes
						),
						static function( $theme ) {
							return is_array( $theme ) && ! empty( $theme );
						}
					)
				),
				'plugins'        => array_values(
					array_filter(
						array_map(
							function( $plugin ) {
								if ( ! is_array( $plugin ) ) {
									return array();
								}

								return array(
									'name'            => isset( $plugin['name'] ) ? sanitize_text_field( (string) $plugin['name'] ) : '',
									'current_version' => isset( $plugin['current_version'] ) ? sanitize_text_field( (string) $plugin['current_version'] ) : '',
									'new_version'     => isset( $plugin['new_version'] ) ? sanitize_text_field( (string) $plugin['new_version'] ) : '',
								);
							},
							$plugins
						),
						static function( $plugin ) {
							return is_array( $plugin ) && ! empty( $plugin );
						}
					)
				),
				'counts'         => array(
					'core'    => isset( $counts['core'] ) ? max( 0, min( 1, (int) $counts['core'] ) ) : ( ! empty( $core['needs_update'] ) ? 1 : 0 ),
					'themes'  => isset( $counts['themes'] ) ? max( 0, (int) $counts['themes'] ) : count( $themes ),
					'plugins' => isset( $counts['plugins'] ) ? max( 0, (int) $counts['plugins'] ) : count( $plugins ),
					'total'   => isset( $counts['total'] ) ? max( 0, (int) $counts['total'] ) : ( ( ! empty( $core['needs_update'] ) ? 1 : 0 ) + count( $themes ) + count( $plugins ) ),
				),
			);
		}

		return $results;
	}

	/**
	 * Format log item for UI.
	 *
	 * @param string $item_type Item type.
	 * @param string $item_slug Item slug.
	 * @return string
	 */
	private function format_log_item_for_display( $item_type, $item_slug ) {
		$item_type = sanitize_key( (string) $item_type );
		$item_slug = sanitize_title( (string) $item_slug );

		$type_labels = array(
			'plugin' => 'Plugin',
			'theme'  => 'Theme',
			'core'   => 'WordPress Core',
		);

		$type_label = isset( $type_labels[ $item_type ] ) ? $type_labels[ $item_type ] : '';
		if ( 'core' === $item_type ) {
			return 'WordPress Core';
		}

		$slug_label = $this->humanize_slug_for_display( $item_slug );

		if ( '' === $type_label && '' === $slug_label ) {
			return '-';
		}

		if ( '' === $type_label ) {
			return $slug_label;
		}

		if ( '' === $slug_label ) {
			return $type_label;
		}

		return $type_label . ': ' . $slug_label;
	}

	/**
	 * Format log action for UI.
	 *
	 * @param string $action Raw action.
	 * @return string
	 */
	private function format_log_action_for_display( $action ) {
		$action = sanitize_key( (string) $action );
		$map    = array(
			'mode_switched'      => 'Mode Changed',
			'site_preregistered' => 'Child Site Added',
			'key_regenerated'    => 'Security Key Regenerated',
			'connected'          => 'Connected',
			'disconnected'       => 'Disconnected',
			'reported'           => 'Update Report',
			'package_uploaded'   => 'Package Uploaded',
			'package_scanned'    => 'Package Imported',
			'package_deleted'    => 'Package Deleted',
			'package_upload_failed' => 'Package Upload Failed',
			'queue_created'      => 'Queue Created',
			'update_check_started' => 'Update Check Started',
			'update_check_success' => 'Update Check Completed',
			'update_check_failed'  => 'Update Check Failed',
			'update_started'     => 'Update Started',
			'update_result'      => 'Update Result',
			'update_success'     => 'Update Succeeded',
			'update_failed'      => 'Update Failed',
			'rollback_started'   => 'Rollback Started',
			'rollback_success'   => 'Rollback Succeeded',
			'rollback_failed'    => 'Rollback Failed',
			'event'              => 'Activity',
		);

		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}

		if ( '' === $action ) {
			return '-';
		}

		return ucwords( str_replace( '_', ' ', $action ) );
	}

	/**
	 * Format log status for UI.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function format_log_status_for_display( $status ) {
		$status = sanitize_key( (string) $status );
		$map    = array(
			'ok'               => 'Success',
			'success'          => 'Success',
			'connected'        => 'Connected',
			'disconnected'     => 'Disconnected',
			'reported'         => 'Reported',
			'on_queue'         => 'On Queue',
			'processing'       => 'Processing',
			'info'             => 'Info',
			'update_started'   => 'In Progress',
			'update_success'   => 'Success',
			'update_failed'    => 'Failed',
			'rollback_started' => 'In Progress',
			'rollback_success' => 'Success',
			'rollback_failed'  => 'Failed',
			'rolled_back'      => 'Rolled Back',
			'failed'           => 'Failed',
			'skipped'          => 'Skipped',
			'imported'         => 'Imported',
		);

		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}

		if ( '' === $status ) {
			return '-';
		}

		return ucwords( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Format log message to a user-friendly sentence.
	 *
	 * @param array $log Log row.
	 * @return string
	 */
	private function format_log_message_for_display( array $log ) {
		$action  = isset( $log['action'] ) ? sanitize_key( (string) $log['action'] ) : '';
		$status  = isset( $log['status'] ) ? sanitize_key( (string) $log['status'] ) : '';
		$mode    = isset( $log['mode'] ) ? sanitize_key( (string) $log['mode'] ) : '';
		$message = isset( $log['message'] ) ? sanitize_text_field( (string) $log['message'] ) : '';
		$context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : array();

		$message = trim( preg_replace( '/\s+/', ' ', $message ) );
		if ( '' === $message ) {
			return $this->build_human_friendly_fallback_message( $action, $status, $mode, $context );
		}

		$direct_map = array(
			'Mode diubah ke master.'                        => 'Active mode changed to Master.',
			'Mode diubah ke child.'                         => 'Active mode changed to Child.',
			'Child site didaftarkan (pre-register).'        => 'Child site was added successfully.',
			'Child site pre-registered.'                    => 'Child site was added successfully.',
			'Security key child diganti.'                   => 'Child security key was regenerated.',
			'Child connected successfully.'                 => 'Child site connected successfully.',
			'Child connected to master.'                    => 'Child site connected to Master.',
			'Child disconnected from master (local).'       => 'Child site was disconnected from Master.',
			'Update started.'                               => 'Update has started.',
			'Core update started.'                          => 'WordPress core update has started.',
			'Rollback started.'                             => 'Automatic rollback started.',
			'Rollback completed successfully.'              => 'Automatic rollback completed successfully.',
			'Update push completed successfully.'           => 'Update completed successfully on child site.',
			'Update push failed.'                           => 'Update failed on child site.',
			'Failed to push update: child connection issue.' => 'Update failed: could not connect to child site.',
			'No detailed response from child.'              => 'No detailed response was returned by child site.',
			'Package upload URL is empty.'                  => 'Package link is missing.',
			'Package download URL is empty.'                => 'Update package link is missing.',
			'Update sukses melalui WP-native.'              => 'Update completed successfully using WordPress installer.',
			'Update sukses melalui fallback replace.'       => 'Update completed successfully using safe fallback method.',
			'Update sukses via WP-native.'                  => 'Update completed successfully using WordPress installer.',
			'Update sukses via fallback replace.'           => 'Update completed successfully using safe fallback method.',
			'Manual update check started for child site.'   => 'Manual update check has started on child site.',
			'Manual update check failed on child site.'     => 'Manual update check failed on child site.',
		);

		if ( isset( $direct_map[ $message ] ) ) {
			return $direct_map[ $message ];
		}

		if ( false !== stripos( $message, 'critical error on this website' ) ) {
			return 'Update failed because the child site encountered a critical error.';
		}

		if ( false !== stripos( $message, 'Child update exception:' ) ) {
			return 'Update failed due to an unexpected error on child site.';
		}

		if ( false !== stripos( $message, 'WP-native exception:' ) ) {
			return 'WordPress installer failed, then safe fallback was attempted.';
		}

		if ( false !== stripos( $message, '| Rollback failed.' ) ) {
			return 'Update failed and automatic rollback also failed. Please check child site immediately.';
		}

		if ( false !== stripos( $message, '| Rollback completed successfully.' ) ) {
			return 'Update failed, but automatic rollback restored the previous version.';
		}

		if ( 'update_result' === $action ) {
			return $this->build_human_friendly_fallback_message( $action, $status, $mode, $context );
		}

		return $message;
	}

	/**
	 * Build fallback user-facing message when raw message is empty/technical.
	 *
	 * @param string $action Action key.
	 * @param string $status Status key.
	 * @param string $mode Mode key.
	 * @param array  $context Context map.
	 * @return string
	 */
	private function build_human_friendly_fallback_message( $action, $status, $mode, array $context ) {
		if ( 'update_result' === $action ) {
			if ( in_array( $status, array( 'update_success', 'success', 'ok' ), true ) ) {
				return 'Update completed successfully on child site.';
			}

			$reason_code = isset( $context['reason_code'] ) ? sanitize_key( (string) $context['reason_code'] ) : '';
			$detail      = isset( $context['detail'] ) ? sanitize_text_field( (string) $context['detail'] ) : '';
			$reason_map  = array(
				'network_error'          => 'Update failed: could not connect to child site.',
				'remote_temporary_error' => 'Update failed temporarily because of network/server issue.',
				'remote_request_rejected' => 'Update request was rejected by child site.',
				'child_update_failed'    => 'Child site reported update failure.',
				'update_failed'          => 'Update failed on child site.',
			);

			$base = isset( $reason_map[ $reason_code ] ) ? $reason_map[ $reason_code ] : 'Update failed on child site.';
			if ( '' !== $detail ) {
				if ( false !== stripos( $detail, 'critical error on this website' ) ) {
					return 'Update failed because the child site encountered a critical error.';
				}

				return $base . ' Detail: ' . $detail;
			}

			return $base;
		}

		if ( 'reported' === $action ) {
			return 'child' === $mode ? 'Update report sent to Master.' : 'Update report received from child site.';
		}

		if ( 'queue_created' === $action ) {
			$queued  = isset( $context['queued'] ) ? (int) $context['queued'] : 0;
			$skipped = isset( $context['skipped'] ) ? (int) $context['skipped'] : 0;
			$queue_kind = isset( $context['queue_kind'] ) ? sanitize_key( (string) $context['queue_kind'] ) : '';
			if ( 'check_updates' === $queue_kind ) {
				return sprintf( 'Update-check queue created: %d site(s) ready, %d skipped.', $queued, $skipped );
			}
			return sprintf( 'Update queue created: %d site(s) ready, %d skipped.', $queued, $skipped );
		}

		$map = array(
			'mode_switched'      => 'Active mode was updated.',
			'site_preregistered' => 'Child site was added successfully.',
			'key_regenerated'    => 'Security key was regenerated.',
			'connected'          => 'Connection established.',
			'disconnected'       => 'Connection disconnected.',
			'package_uploaded'   => 'Package uploaded successfully.',
			'package_scanned'    => 'Package imported from updates folder.',
			'package_deleted'    => 'Package deleted successfully.',
			'package_upload_failed' => 'Package upload failed.',
			'update_check_started' => 'Manual update check has started on child site.',
			'update_check_success' => 'Manual update check completed.',
			'update_check_failed'  => 'Manual update check failed on child site.',
			'update_started'     => 'Update has started.',
			'update_success'     => 'Update completed successfully.',
			'update_failed'      => 'Update failed.',
			'rollback_started'   => 'Automatic rollback started.',
			'rollback_success'   => 'Automatic rollback completed successfully.',
			'rollback_failed'    => 'Automatic rollback failed.',
		);

		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}

		return 'RawatWP activity recorded.';
	}

	/**
	 * Humanize slug-like values for display.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private function humanize_slug_for_display( $slug ) {
		$slug = sanitize_text_field( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = preg_replace( '/\s+/', ' ', (string) $slug );
		$slug = trim( (string) $slug );

		return ucwords( $slug );
	}

	/**
	 * Render success/error notices from query string.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notice = isset( $_GET['rawatwp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['rawatwp_notice'] ) ) : '';
		$error  = isset( $_GET['rawatwp_error'] ) ? sanitize_text_field( wp_unslash( $_GET['rawatwp_error'] ) ) : '';

		if ( '' !== $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/**
	 * Check whether queue still has pending/processing rows.
	 *
	 * @param array $queue_rows Queue rows.
	 * @return bool
	 */
	private function has_pending_queue_items( array $queue_rows ) {
		foreach ( $queue_rows as $row ) {
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			if ( in_array( $status, array( 'on_queue', 'processing' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify admin capability.
	 *
	 * @return void
	 */
	private function assert_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rawatwp' ) );
		}
	}

	/**
	 * Verify admin-post request with nonce + capability.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function assert_admin_post( $nonce_action ) {
		$this->assert_admin();
		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect to plugin page with success/error notice.
	 *
	 * @param string $page Page slug.
	 * @param string $notice Notice message.
	 * @param string $error Error message.
	 * @return void
	 */
	private function redirect_with_notice( $page, $notice, $error ) {
		$args = array( 'page' => $page );
		if ( '' !== $notice ) {
			$args['rawatwp_notice'] = $notice;
		}
		if ( '' !== $error ) {
			$args['rawatwp_error'] = $error;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect to arbitrary admin URL with success/error notice query args.
	 *
	 * @param string $url Target URL.
	 * @param string $notice Notice text.
	 * @param string $error Error text.
	 * @return void
	 */
	private function redirect_to_url_with_notice( $url, $notice, $error ) {
		$args = array();
		if ( '' !== $notice ) {
			$args['rawatwp_notice'] = $notice;
		}
		if ( '' !== $error ) {
			$args['rawatwp_error'] = $error;
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Read and validate optional redirect target from request.
	 *
	 * @return string
	 */
	private function get_requested_redirect_url() {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '';
		if ( '' === $redirect_to ) {
			return '';
		}

		$fallback = admin_url( 'plugins.php' );
		$valid    = wp_validate_redirect( $redirect_to, $fallback );
		if ( 0 !== strpos( $valid, admin_url() ) ) {
			return $fallback;
		}

		return $valid;
	}

	/**
	 * Get currently installed RawatWP version from plugin header.
	 *
	 * @return string
	 */
	private function get_runtime_rawatwp_version() {
		$version = '';

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'get_plugin_data' ) && defined( 'RAWATWP_FILE' ) ) {
			$data = get_plugin_data( RAWATWP_FILE, false, false );
			if ( is_array( $data ) && ! empty( $data['Version'] ) ) {
				$version = sanitize_text_field( (string) $data['Version'] );
			}
		}

		if ( '' === $version && defined( 'RAWATWP_VERSION' ) ) {
			$version = sanitize_text_field( (string) RAWATWP_VERSION );
		}

		return '' !== $version ? $version : '-';
	}

	/**
	 * Format DATETIME string to WordPress locale + timezone.
	 *
	 * @param string $datetime MySQL DATETIME string.
	 * @return string
	 */
	private function format_datetime_for_display( $datetime ) {
		$datetime = sanitize_text_field( (string) $datetime );
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '-';
		}

		$timezone = wp_timezone();
		$object   = date_create_immutable_from_format( 'Y-m-d H:i:s', $datetime, $timezone );
		if ( false === $object ) {
			$timestamp = strtotime( $datetime );
			if ( false === $timestamp ) {
				return $datetime;
			}
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, $timezone );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $object->getTimestamp(), $timezone );
	}
}
