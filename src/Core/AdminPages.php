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
			add_submenu_page( 'rawatwp-general', 'Monitored Items', 'Monitored Items', 'manage_options', 'rawatwp-monitored-items', array( $this, 'render_monitored_items_page' ) );
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
		add_action( 'admin_post_rawatwp_add_item', array( $this, 'handle_add_monitored_item' ) );
		add_action( 'admin_post_rawatwp_toggle_item', array( $this, 'handle_toggle_monitored_item' ) );
		add_action( 'admin_post_rawatwp_delete_item', array( $this, 'handle_delete_monitored_item' ) );
		add_action( 'admin_post_rawatwp_scan_now', array( $this, 'handle_scan_now' ) );
		add_action( 'admin_post_rawatwp_scan_installed_items', array( $this, 'handle_scan_installed_items' ) );
		add_action( 'admin_post_rawatwp_add_site', array( $this, 'handle_add_site' ) );
		add_action( 'admin_post_rawatwp_regen_key', array( $this, 'handle_regenerate_site_key' ) );
		add_action( 'admin_post_rawatwp_upload_package', array( $this, 'handle_upload_package' ) );
		add_action( 'admin_post_rawatwp_scan_updates_folder', array( $this, 'handle_scan_updates_folder' ) );
		add_action( 'admin_post_rawatwp_delete_package', array( $this, 'handle_delete_package' ) );
		add_action( 'admin_post_rawatwp_bulk_delete_packages', array( $this, 'handle_bulk_delete_packages' ) );
		add_action( 'admin_post_rawatwp_push_update', array( $this, 'handle_push_update' ) );
		add_action( 'admin_post_rawatwp_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_rawatwp_check_update_now', array( $this, 'handle_check_update_now' ) );
		add_action( 'admin_post_rawatwp_queue_run_now', array( $this, 'handle_queue_run_now' ) );
		add_action( 'admin_post_rawatwp_queue_pause_toggle', array( $this, 'handle_queue_pause_toggle' ) );
		add_action( 'admin_post_rawatwp_regenerate_runner_token', array( $this, 'handle_regenerate_runner_token' ) );

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
		$delete_all_on_uninstall = '1' === (string) get_option( 'rawatwp_delete_all_on_uninstall', '0' );
		?>
		<div class="wrap rawatwp-admin">
			<h1>RawatWP - General</h1>
			<p class="rawatwp-page-subtitle">Pengaturan utama plugin RawatWP.</p>
			<?php $this->render_notices(); ?>
			<div class="rawatwp-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'rawatwp_save_general_settings' ); ?>
					<input type="hidden" name="action" value="rawatwp_save_general_settings" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rawatwp_mode">Mode Aktif</label></th>
							<td>
								<select id="rawatwp_mode" name="rawatwp_mode">
									<option value="">Pilih Mode</option>
									<option value="master" <?php selected( $mode, 'master' ); ?>>Master</option>
									<option value="child" <?php selected( $mode, 'child' ); ?>>Child</option>
								</select>
								<p class="description">Pilih satu mode:<br />- Master (untuk penyedia update)<br />- Child (untuk penerima update)</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Hapus Data Saat Uninstall</th>
							<td>
								<label>
									<input type="checkbox" name="delete_all_on_uninstall" value="1" <?php checked( $delete_all_on_uninstall ); ?> />
									Hapus Bersih
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Simpan Pengaturan' ); ?>
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
			<p class="rawatwp-page-subtitle">Hubungkan site Child ke Master.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'child' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>Halaman ini aktif hanya untuk mode Child.</p>
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
						<p><strong>Last connected:</strong> <?php echo esc_html( $this->format_datetime_for_display( $settings['last_connected_at'] ) ); ?></p>
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
			<p class="rawatwp-page-subtitle">Kelola item plugin/theme yang dipantau pada mode Child.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'child' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>Halaman ini aktif hanya untuk mode Child.</p>
				</div>
			<?php else : ?>
				<div class="rawatwp-card">
					<p><strong>Alur paling mudah:</strong> 1) Klik scan plugin/theme, 2) tandai item yang butuh update, 3) klik kirim report ke Master.</p>

					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_scan_installed_items' ); ?>
						<input type="hidden" name="action" value="rawatwp_scan_installed_items" />
						<?php submit_button( '1) Scan Plugin & Theme Terpasang', 'primary', 'submit', false ); ?>
					</form>

					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_scan_now' ); ?>
						<input type="hidden" name="action" value="rawatwp_scan_now" />
						<?php submit_button( '3) Kirim Report ke Master', 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="rawatwp-card">
					<h2>Daftar Monitored Items</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Type</th>
								<th>Slug</th>
								<th>Label</th>
								<th>Current Version</th>
								<th>Needs Update</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$monitored_items = $this->monitored_items->get_items();
							if ( empty( $monitored_items ) ) :
								?>
								<tr>
									<td colspan="6">Belum ada item. Klik tombol scan agar plugin/theme otomatis masuk ke daftar.</td>
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
											<button class="button" type="submit"><?php echo ! empty( $item['needs_update'] ) ? 'Sudah Aman' : 'Butuh Update'; ?></button>
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
			<p class="rawatwp-page-subtitle">Kelola daftar child site yang terhubung ke Master.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'master' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>Halaman ini aktif hanya untuk mode Master.</p>
				</div>
			<?php else : ?>
				<div class="rawatwp-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_add_site' ); ?>
						<input type="hidden" name="action" value="rawatwp_add_site" />
						<div class="rawatwp-field-stack">
							<p class="rawatwp-field-group">
								<label for="rawatwp-site-name"><strong>Nama Site Child</strong></label>
								<input id="rawatwp-site-name" class="regular-text" type="text" name="site_name" required />
							</p>
							<p class="rawatwp-field-group">
								<label for="rawatwp-site-url"><strong>Domain / URL Child</strong></label>
								<input id="rawatwp-site-url" class="regular-text" type="url" name="site_url" required />
							</p>
						</div>
						<?php submit_button( 'Tambah Child Site (Pre-register)' ); ?>
					</form>
				</div>

				<div class="rawatwp-card">
					<h2>Daftar Child Sites</h2>
					<table class="widefat striped rawatwp-sites-table">
						<thead>
							<tr>
								<th class="rawatwp-col-id">ID</th>
								<th class="rawatwp-col-site">Nama Site</th>
								<th class="rawatwp-col-domain">Domain</th>
								<th class="rawatwp-col-key">Security Key</th>
								<th class="rawatwp-col-status">Status</th>
								<th class="rawatwp-col-last-seen">Last Seen</th>
								<th class="rawatwp-col-action">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $this->master_manager->get_sites() as $site ) : ?>
								<tr>
									<td class="rawatwp-col-id"><?php echo esc_html( (string) $site['id'] ); ?></td>
									<td class="rawatwp-col-site"><?php echo esc_html( $site['site_name'] ); ?></td>
									<td class="rawatwp-domain rawatwp-col-domain"><?php echo esc_html( $site['site_url'] ); ?></td>
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
			<p class="rawatwp-page-subtitle">Upload, scan, dan kelola package update.</p>
			<?php $this->render_notices(); ?>
			<?php if ( 'master' !== $mode ) : ?>
				<div class="rawatwp-card">
					<p>Halaman ini aktif hanya untuk mode Master.</p>
				</div>
			<?php else : ?>
				<div class="rawatwp-card">
					<form id="rawatwp-package-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_upload_package' ); ?>
						<input type="hidden" name="action" value="rawatwp_upload_package" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">File zip</th>
								<td>
									<input id="rawatwp-package-zip-input" type="file" name="package_zip[]" accept=".zip" multiple required />
									<p id="rawatwp-upload-file-count" class="description"></p>
									<p id="rawatwp-upload-progress-wrap" class="rawatwp-upload-progress">
										Upload progress: <strong id="rawatwp-upload-progress-value">0%</strong>
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Upload package zip', 'primary', 'submit', false, array( 'id' => 'rawatwp-upload-submit' ) ); ?>
					</form>

					<form class="rawatwp-inline-form rawatwp-package-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_scan_updates_folder' ); ?>
						<input type="hidden" name="action" value="rawatwp_scan_updates_folder" />
						<?php submit_button( 'Scan Available Packages', 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<div class="rawatwp-card">
					<h2>Daftar Package</h2>
					<form id="rawatwp-bulk-delete-packages" class="rawatwp-package-bulk-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Hapus semua package terpilih? File zip dan data package akan dihapus permanen.');">
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
							<th>Target Slug</th>
							<th>File</th>
							<th>Uploaded At</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php $packages = $this->package_manager->get_packages(); ?>
						<?php if ( empty( $packages ) ) : ?>
							<tr>
								<td colspan="8">Belum ada package.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $packages as $package ) : ?>
								<tr>
									<td>
										<input
											type="checkbox"
											class="rawatwp-package-check"
											form="rawatwp-bulk-delete-packages"
											name="package_ids[]"
											value="<?php echo esc_attr( (string) $package['id'] ); ?>"
										/>
									</td>
									<td><?php echo esc_html( (string) $package['id'] ); ?></td>
									<td><?php echo esc_html( $package['label'] ); ?></td>
									<td><?php echo esc_html( $package['type'] ); ?></td>
									<td><?php echo esc_html( $package['target_slug'] ); ?></td>
									<td><?php echo esc_html( $package['file_name'] ); ?></td>
									<td><?php echo esc_html( $this->format_datetime_for_display( isset( $package['created_at'] ) ? $package['created_at'] : '' ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Hapus package ini? File zip dan data package akan dihapus permanen.');">
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

					if (checkAll) {
						checkAll.addEventListener('change', function() {
							rowChecks.forEach(function(item) {
								item.checked = checkAll.checked;
							});
						});
					}

					var uploadForm = document.getElementById('rawatwp-package-upload-form');
					if (!uploadForm || !window.XMLHttpRequest) {
						return;
					}

						var progressWrap = document.getElementById('rawatwp-upload-progress-wrap');
						var progressValue = document.getElementById('rawatwp-upload-progress-value');
						var fileCountInfo = document.getElementById('rawatwp-upload-file-count');
						var submitButton = document.getElementById('rawatwp-upload-submit');
						var uploadUrl = uploadForm.getAttribute('action') || '';
						var fileInput = document.getElementById('rawatwp-package-zip-input');

						if (fileInput && fileCountInfo) {
							fileInput.addEventListener('change', function() {
								var count = fileInput.files ? fileInput.files.length : 0;
								fileCountInfo.textContent = count > 0 ? (count + ' file dipilih') : '';
							});
						}

						uploadForm.addEventListener('submit', function(event) {
							if ('1' === uploadForm.getAttribute('data-uploading')) {
								event.preventDefault();
								return;
							}

							if (!fileInput || !fileInput.files || !fileInput.files.length) {
								return;
							}

						event.preventDefault();
						uploadForm.setAttribute('data-uploading', '1');

						if (submitButton) {
							submitButton.disabled = true;
						}

						if (progressWrap) {
							progressWrap.style.display = 'block';
						}

						var xhr = new XMLHttpRequest();
						xhr.open('POST', uploadUrl, true);

						xhr.upload.addEventListener('progress', function(e) {
							if (!e.lengthComputable || !progressValue) {
								return;
							}
							var percent = Math.round((e.loaded / e.total) * 100);
							progressValue.textContent = percent + '%';
						});

							xhr.onload = function() {
								if (progressValue) {
									progressValue.textContent = '100%';
								}
								var redirectTo = xhr.responseURL ? xhr.responseURL : '';
								if (redirectTo) {
									window.location.href = redirectTo;
								return;
							}
							window.location.reload();
						};

						xhr.onerror = function() {
							uploadForm.removeAttribute('data-uploading');
							if (submitButton) {
								submitButton.disabled = false;
							}
							uploadForm.submit();
						};

						xhr.send(new FormData(uploadForm));
					});
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
				<p class="rawatwp-page-subtitle">Kirim update ke child site dengan alur yang sederhana.</p>
				<?php $this->render_notices(); ?>
				<?php if ( 'master' !== $mode ) : ?>
					<div class="rawatwp-card">
						<p>Halaman ini aktif hanya untuk mode Master.</p>
					</div>
				<?php else : ?>
					<?php
					$packages          = $this->package_manager->get_packages();
					$site_summaries    = $this->master_manager->get_sites_update_summary();
					$child_sites       = $this->master_manager->get_sites();
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
					foreach ( $site_summaries as $summary ) {
						$url         = isset( $summary['site_url'] ) ? (string) $summary['site_url'] : '';
						$needs_count = ! empty( $summary['needs_update_items'] ) && is_array( $summary['needs_update_items'] ) ? count( $summary['needs_update_items'] ) : 0;
						if ( $needs_count > 0 ) {
							$needs_update_site++;
						}
						if ( '' !== $url ) {
							$needs_by_url[ $url ] = $needs_count;
						}
					}
					$queue_total_active = $on_queue_count + $processing_count;
					$status_labels      = array(
						'on_queue'   => 'Dalam Antrian',
						'processing' => 'Sedang Diproses',
						'success'    => 'Berhasil',
						'failed'     => 'Gagal',
					);
					?>
					<div class="rawatwp-card">
						<h2>Ringkasan Cepat</h2>
						<div class="rawatwp-update-kpis">
						<div class="rawatwp-update-kpi">
							<span class="label">Total Child Site</span>
							<span class="value"><?php echo esc_html( (string) count( $child_sites ) ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Site Butuh Update</span>
							<span class="value"><?php echo esc_html( (string) $needs_update_site ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Sedang/Antri Proses</span>
							<span class="value"><?php echo esc_html( (string) $queue_total_active ); ?></span>
						</div>
						<div class="rawatwp-update-kpi">
							<span class="label">Berhasil / Gagal</span>
							<span class="value"><?php echo esc_html( (string) $success_count . ' / ' . (string) $failed_count ); ?></span>
							</div>
						</div>

						<div class="rawatwp-updates-controls">
							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'rawatwp_queue_run_now' ); ?>
								<input type="hidden" name="action" value="rawatwp_queue_run_now" />
								<?php submit_button( 'Proses Queue Sekarang', 'secondary', 'submit', false ); ?>
							</form>

							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'rawatwp_queue_pause_toggle' ); ?>
								<input type="hidden" name="action" value="rawatwp_queue_pause_toggle" />
								<input type="hidden" name="pause_value" value="<?php echo $queue_paused ? '0' : '1'; ?>" />
								<?php submit_button( $queue_paused ? 'Lanjutkan Queue' : 'Pause Queue', 'secondary', 'submit', false ); ?>
							</form>
						</div>
						<p class="description">Status queue saat ini: <strong><?php echo $queue_paused ? 'Paused' : 'Active'; ?></strong></p>
					</div>

					<div class="rawatwp-card">
						<h2>Kirim Update</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'rawatwp_push_update' ); ?>
						<input type="hidden" name="action" value="rawatwp_push_update" />
						<table class="form-table" role="presentation">
							<tr>
							<th scope="row">Pilih Package</th>
							<td>
								<select name="package_id" required>
									<option value="">Pilih package</option>
									<?php foreach ( $packages as $package ) : ?>
										<option value="<?php echo esc_attr( (string) $package['id'] ); ?>"><?php echo esc_html( $package['label'] . ' [' . $package['type'] . ':' . $package['target_slug'] . ']' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							</tr>
							<tr>
								<th scope="row">Pilih Child Site</th>
								<td>
									<label class="rawatwp-select-all-sites">
										<input type="checkbox" id="rawatwp-select-all-sites" />
										Pilih semua child site
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
															'Status: %s | Butuh update: %d item',
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
						<?php submit_button( 'Kirim Update Sekarang' ); ?>
						</form>
					</div>

					<div class="rawatwp-card">
						<h2>Progress Update</h2>
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
									<td colspan="5">Belum ada proses update.</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $queue_rows as $row ) : ?>
									<?php
									$status_key   = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
									$status_label = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : ucfirst( $status_key );
									$detail_text  = '';
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
												<div class="rawatwp-progress-bar" style="width:<?php echo esc_attr( (string) max( 0, min( 100, (int) $row['progress'] ) ) ); ?>%;"></div>
											</div>
											<?php echo esc_html( (string) (int) $row['progress'] ); ?>%
										</td>
										<td><?php echo esc_html( $this->format_datetime_for_display( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
						</table>

						<details class="rawatwp-advanced-updates">
							<summary>Pengaturan Lanjutan</summary>
							<p class="rawatwp-mt-2"><label><input type="checkbox" id="rawatwp-browser-worker-enable" checked> Aktifkan worker browser saat halaman ini terbuka</label></p>
							<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'rawatwp_regenerate_runner_token' ); ?>
							<input type="hidden" name="action" value="rawatwp_regenerate_runner_token" />
							<?php submit_button( 'Regenerate Runner Token', 'secondary', 'submit', false ); ?>
							</form>
							<p class="description">URL runner untuk system cron (1 menit sekali):</p>
							<p><code class="rawatwp-break-word"><?php echo esc_html( $runner_url ); ?></code></p>
						</details>
					</div>
					<script>
					(function() {
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
			<p class="rawatwp-page-subtitle">Riwayat aktivitas update dan koneksi RawatWP.</p>
			<?php $this->render_notices(); ?>
			<div class="rawatwp-card">
				<p>Log otomatis dirawat: hapus data lebih dari 30 hari dan batasi maksimal 10.000 baris terbaru agar database tetap ringan.</p>
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
								<td><?php echo esc_html( $log['mode'] ); ?></td>
								<td><?php echo esc_html( trim( $log['item_type'] . ':' . $log['item_slug'], ':' ) ); ?></td>
								<td><?php echo esc_html( $log['action'] ); ?></td>
								<td><?php echo esc_html( $log['status'] ); ?></td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="rawatwp-log-actions">
					<form class="rawatwp-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear semua log RawatWP?');">
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

		$this->redirect_with_notice( 'rawatwp-general', 'Pengaturan berhasil disimpan.', '' );
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

		$this->redirect_with_notice( 'rawatwp-connection', 'Connected ke Master.', '' );
	}

	/**
	 * Disconnect child from master.
	 *
	 * @return void
	 */
	public function handle_disconnect_child() {
		$this->assert_admin_post( 'rawatwp_disconnect_child' );

		if ( ! $this->child_manager->disconnect() ) {
			$this->redirect_with_notice( 'rawatwp-connection', '', 'Gagal disconnect.' );
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

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Monitored item ditambahkan.', '' );
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
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'Gagal update status monitored item.' );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Status monitored item diperbarui.', '' );
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
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'Monitored item tidak ditemukan.' );
		}

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Monitored item dihapus.', '' );
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

		$this->redirect_with_notice( 'rawatwp-monitored-items', 'Report needs-update berhasil dikirim ke master.', '' );
	}

	/**
	 * Handle scan installed plugins/themes and import into monitored items.
	 *
	 * @return void
	 */
	public function handle_scan_installed_items() {
		$this->assert_admin_post( 'rawatwp_scan_installed_items' );

		if ( 'child' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-monitored-items', '', 'Fitur ini hanya untuk mode Child.' );
		}

		$result = $this->monitored_items->import_installed_items();

		$message = sprintf(
			'Scan selesai. Ditambahkan: %d, Dilewati: %d, Plugin ditemukan: %d, Theme ditemukan: %d.',
			(int) $result['added'],
			(int) $result['skipped'],
			(int) $result['plugins_found'],
			(int) $result['themes_found']
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

		$this->redirect_with_notice( 'rawatwp-sites', 'Child site berhasil ditambahkan.', '' );
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

		$this->redirect_with_notice( 'rawatwp-sites', 'Security key child berhasil diganti.', '' );
	}

	/**
	 * Handle package upload.
	 *
	 * @return void
	 */
	public function handle_upload_package() {
		$this->assert_admin_post( 'rawatwp_upload_package' );

		if ( empty( $_FILES['package_zip'] ) || ! is_array( $_FILES['package_zip'] ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'File zip tidak ditemukan.' );
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
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Tidak ada file zip yang dipilih.' );
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
				'Upload selesai dengan sebagian gagal. Berhasil: %d, Gagal: %d. %s',
				$success,
				$failed,
				implode( ' | ', array_slice( $errors, 0, 2 ) )
			);
			$this->redirect_with_notice( 'rawatwp-packages', '', $error );
		}

		$this->redirect_with_notice( 'rawatwp-packages', sprintf( '%d file zip berhasil diupload.', $success ), '' );
	}

	/**
	 * Handle folder scan: /public_html/updates.
	 *
	 * @return void
	 */
	public function handle_scan_updates_folder() {
		$this->assert_admin_post( 'rawatwp_scan_updates_folder' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Fitur ini hanya untuk mode Master.' );
		}

		$result = $this->package_manager->scan_updates_directory();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', $result->get_error_message() );
		}

		$message = sprintf(
			'Scan folder selesai. Total zip: %d, Import: %d, Skip: %d, Gagal: %d.',
			(int) $result['total'],
			(int) $result['imported'],
			(int) $result['skipped'],
			(int) $result['failed']
		);

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
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Fitur ini hanya untuk mode Master.' );
		}

		$package_id = isset( $_POST['package_id'] ) ? (int) $_POST['package_id'] : 0;
		if ( $package_id <= 0 ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Package tidak valid.' );
		}

		$result = $this->package_manager->delete_package( $package_id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', $result->get_error_message() );
		}

		$this->redirect_with_notice( 'rawatwp-packages', 'Package berhasil dihapus.', '' );
	}

	/**
	 * Handle bulk package delete.
	 *
	 * @return void
	 */
	public function handle_bulk_delete_packages() {
		$this->assert_admin_post( 'rawatwp_bulk_delete_packages' );

		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Fitur ini hanya untuk mode Master.' );
		}

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$package_ids = isset( $_POST['package_ids'] ) && is_array( $_POST['package_ids'] ) ? array_map( 'intval', $_POST['package_ids'] ) : array();

		if ( 'delete' !== $bulk_action ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Pilih bulk action terlebih dulu.' );
		}

		if ( empty( $package_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-packages', '', 'Pilih minimal satu package.' );
		}

		$result = $this->package_manager->delete_packages( $package_ids );
		if ( (int) $result['failed'] > 0 ) {
			$error = sprintf(
				'Hapus bulk selesai dengan sebagian gagal. Berhasil: %d, Gagal: %d.',
				(int) $result['deleted'],
				(int) $result['failed']
			);
			$this->redirect_with_notice( 'rawatwp-packages', '', $error );
		}

		$notice = sprintf( 'Bulk delete selesai. %d package dihapus.', (int) $result['deleted'] );
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
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Fitur ini hanya untuk mode Master.' );
		}

		$package_id = isset( $_POST['package_id'] ) ? (int) $_POST['package_id'] : 0;
		$site_ids   = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ? array_map( 'intval', $_POST['site_ids'] ) : array();

		if ( $package_id <= 0 || empty( $site_ids ) ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Pilih package dan minimal satu child site.' );
		}

		$result = $this->queue_manager->enqueue_batch( $package_id, $site_ids );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', $result->get_error_message() );
		}

		$message = sprintf(
			'Queue dibuat. Batch: %s | On queue: %d | Skip: %d',
			$result['batch_id'],
			(int) $result['queued'],
			(int) $result['skipped']
		);
		$this->redirect_with_notice( 'rawatwp-updates', $message, '' );
	}

	/**
	 * Handle clear logs action.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		$this->assert_admin_post( 'rawatwp_clear_logs' );

		if ( ! $this->logger->clear_logs() ) {
			$this->redirect_with_notice( 'rawatwp-logs', '', 'Gagal clear logs.' );
		}

		$this->redirect_with_notice( 'rawatwp-logs', 'Logs berhasil dibersihkan.', '' );
	}

	/**
	 * Handle manual run queue once.
	 *
	 * @return void
	 */
	public function handle_queue_run_now() {
		$this->assert_admin_post( 'rawatwp_queue_run_now' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Fitur queue hanya untuk mode Master.' );
		}

		$result = $this->queue_manager->run_worker( 'admin_manual', 1 );
		$notice = sprintf(
			'Queue dijalankan manual. Diproses: %d, Pending: %d, Processing: %d.',
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
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Fitur queue hanya untuk mode Master.' );
		}

		$pause_value = isset( $_POST['pause_value'] ) ? sanitize_key( wp_unslash( $_POST['pause_value'] ) ) : '0';
		if ( '1' === $pause_value ) {
			$this->queue_manager->pause_queue();
			$this->redirect_with_notice( 'rawatwp-updates', 'Queue di-pause.', '' );
		}

		$this->queue_manager->resume_queue();
		$this->redirect_with_notice( 'rawatwp-updates', 'Queue dilanjutkan.', '' );
	}

	/**
	 * Handle regenerate queue runner token.
	 *
	 * @return void
	 */
	public function handle_regenerate_runner_token() {
		$this->assert_admin_post( 'rawatwp_regenerate_runner_token' );
		if ( 'master' !== $this->mode_manager->get_mode() ) {
			$this->redirect_with_notice( 'rawatwp-updates', '', 'Fitur runner hanya untuk mode Master.' );
		}

		$this->security->regenerate_queue_runner_token();
		$this->redirect_with_notice( 'rawatwp-updates', 'Runner token berhasil diganti.', '' );
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
			wp_send_json_error( array( 'message' => 'Fitur queue hanya untuk mode Master.' ), 403 );
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
			wp_send_json_error( array( 'message' => 'Fitur queue hanya untuk mode Master.' ), 403 );
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
		$notice         = 'Cek update RawatWP selesai, versi terbaru saat ini: ' . $latest_version;

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
			wp_die( esc_html__( 'Anda tidak punya izin untuk akses halaman ini.', 'rawatwp' ) );
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
