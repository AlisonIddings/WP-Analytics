<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

// Defer loading of heavy classes until actually needed
// class-sa-list-table.php and class-sa-pdf.php are loaded on demand

final class SA_Admin {
	private const MENU_SLUG = 'server-analytics';

	public static function init(): void {
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

		add_action('admin_post_sa_export_csv', array(__CLASS__, 'export_csv'));
		add_action('admin_post_sa_export_pdf', array(__CLASS__, 'export_pdf'));

		// Delete actions
		add_action('admin_post_sa_delete_by_date', array(__CLASS__, 'handle_delete_by_date'));
		add_action('admin_post_sa_delete_all', array(__CLASS__, 'handle_delete_all'));

		// Settings action
		add_action('admin_post_sa_save_settings', array(__CLASS__, 'handle_save_settings'));
	}

	public static function register_menu(): void {
		add_menu_page(
			__('Server Analytics', 'server-analytics'),
			__('Server Analytics', 'server-analytics'),
			sa_view_analytics_capability(),
			self::MENU_SLUG,
			array(__CLASS__, 'render_page'),
			'dashicons-chart-area',
			65
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Analytics Dashboard', 'server-analytics'),
			__('Dashboard', 'server-analytics'),
			sa_view_analytics_capability(),
			self::MENU_SLUG,
			array(__CLASS__, 'render_page')
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('Settings', 'server-analytics'),
			__('Settings', 'server-analytics'),
			'manage_options', // Settings require admin capability
			self::MENU_SLUG . '-settings',
			array(__CLASS__, 'render_settings_page')
		);
	}

	public static function enqueue_assets(string $hook): void {
		if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
			return;
		}

		wp_enqueue_style(
			'sa-admin',
			SA_PLUGIN_URL . 'assets/css/sa-admin.css',
			array(),
			SA_PLUGIN_VERSION
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function current_filters(): array {
		// Limit search term to prevent abuse
		$search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '';
		if (strlen($search) > 200) {
			$search = substr($search, 0, 200);
		}

		return array(
			'event_type' => isset($_GET['event_type']) ? sanitize_key((string) $_GET['event_type']) : '',
			'date_from'  => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
			'date_to'    => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
			's'          => $search,
		);
	}

	public static function render_page(): void {
		if (!current_user_can(sa_view_analytics_capability())) {
			wp_die(esc_html__('You do not have permission to view analytics.', 'server-analytics'));
		}

		// Process delete actions
		self::process_delete_actions();

		// Load list table class on demand
		self::load_list_table_class();

		$filters = self::current_filters();
		$table = new SA_List_Table($filters);
		$table->prepare_items();

		$base_url = menu_page_url(self::MENU_SLUG, false);
		$export_args = array_filter(
			array(
				'page'       => self::MENU_SLUG,
				'event_type' => $filters['event_type'],
				'date_from'  => $filters['date_from'],
				'date_to'    => $filters['date_to'],
				's'          => $filters['s'],
			),
			static fn($v): bool => $v !== '' && $v !== null
		);

		$csv_url = wp_nonce_url(
			add_query_arg($export_args, admin_url('admin-post.php?action=sa_export_csv')),
			'sa_export'
		);
		$pdf_url = wp_nonce_url(
			add_query_arg($export_args, admin_url('admin-post.php?action=sa_export_pdf')),
			'sa_export'
		);

		$total_events = SA_DB::get_total_count();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Server Analytics', 'server-analytics'); ?></h1>

			<?php self::render_admin_notices(); ?>

			<form method="get" action="<?php echo esc_url($base_url); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />

				<fieldset class="sa-filters" aria-label="<?php echo esc_attr__('Analytics filters', 'server-analytics'); ?>">
					<div class="sa-field">
						<label for="sa-event-type"><?php echo esc_html__('Event type', 'server-analytics'); ?></label>
						<select id="sa-event-type" name="event_type">
							<?php
							$types = array(
								''          => __('All', 'server-analytics'),
								'pageview'  => __('Pageview', 'server-analytics'),
								'link_click'=> __('Link click', 'server-analytics'),
							);
							foreach ($types as $value => $label) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr($value),
									selected($filters['event_type'], $value, false),
									esc_html($label)
								);
							}
							?>
						</select>
					</div>

					<div class="sa-field">
						<label for="sa-date-from"><?php echo esc_html__('From (YYYY-MM-DD)', 'server-analytics'); ?></label>
						<input id="sa-date-from" name="date_from" type="date" value="<?php echo esc_attr($filters['date_from']); ?>" />
					</div>

					<div class="sa-field">
						<label for="sa-date-to"><?php echo esc_html__('To (YYYY-MM-DD)', 'server-analytics'); ?></label>
						<input id="sa-date-to" name="date_to" type="date" value="<?php echo esc_attr($filters['date_to']); ?>" />
					</div>

					<div class="sa-field" style="flex: 1 1 260px;">
						<label for="sa-search"><?php echo esc_html__('Search', 'server-analytics'); ?></label>
						<input id="sa-search" name="s" type="search" value="<?php echo esc_attr($filters['s']); ?>" placeholder="<?php echo esc_attr__('URL or IP…', 'server-analytics'); ?>" />
					</div>

					<div class="sa-field">
						<label class="screen-reader-text" for="sa-submit"><?php echo esc_html__('Apply filters', 'server-analytics'); ?></label>
						<button id="sa-submit" class="button button-primary" type="submit"><?php echo esc_html__('Apply', 'server-analytics'); ?></button>
					</div>
				</fieldset>
			</form>

			<div class="sa-actions" aria-label="<?php echo esc_attr__('Export actions', 'server-analytics'); ?>">
				<a class="button" href="<?php echo esc_url($csv_url); ?>"><?php echo esc_html__('Export CSV', 'server-analytics'); ?></a>
				<a class="button" href="<?php echo esc_url($pdf_url); ?>"><?php echo esc_html__('Export PDF', 'server-analytics'); ?></a>
			</div>

			<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
				<?php wp_nonce_field('sa_bulk_action', 'sa_bulk_nonce'); ?>
				<input type="hidden" name="sa_bulk_action" value="1" />
				<?php $table->display(); ?>
			</form>

			<hr style="margin: 30px 0;" />

			<h2><?php echo esc_html__('Data Management', 'server-analytics'); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of events */
					esc_html__('Total events in database: %s', 'server-analytics'),
					'<strong>' . esc_html(number_format_i18n($total_events)) . '</strong>'
				);
				?>
			</p>

			<div class="sa-data-management" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
				<!-- Delete by Date Range -->
				<div class="sa-delete-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 300px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__('Delete by Date Range', 'server-analytics'); ?></h3>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('sa_delete_by_date', 'sa_delete_date_nonce'); ?>
						<input type="hidden" name="action" value="sa_delete_by_date" />
						<p>
							<label for="sa-delete-from"><?php echo esc_html__('From:', 'server-analytics'); ?></label><br />
							<input type="date" id="sa-delete-from" name="delete_from" required />
						</p>
						<p>
							<label for="sa-delete-to"><?php echo esc_html__('To:', 'server-analytics'); ?></label><br />
							<input type="date" id="sa-delete-to" name="delete_to" required />
						</p>
						<p>
							<button type="submit" class="button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete events in this date range? This cannot be undone.', 'server-analytics')); ?>');">
								<?php echo esc_html__('Delete by Date Range', 'server-analytics'); ?>
							</button>
						</p>
					</form>
				</div>

				<!-- Delete All Data -->
				<div class="sa-delete-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 300px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__('Delete All Data', 'server-analytics'); ?></h3>
					<p class="description" style="color: #d63638;">
						<?php echo esc_html__('Warning: This will permanently delete all analytics data. This action cannot be undone.', 'server-analytics'); ?>
					</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('sa_delete_all', 'sa_delete_all_nonce'); ?>
						<input type="hidden" name="action" value="sa_delete_all" />
						<p>
							<label>
								<input type="checkbox" name="confirm_delete_all" value="1" required />
								<?php echo esc_html__('I understand this will delete all data', 'server-analytics'); ?>
							</label>
						</p>
						<p>
							<button type="submit" class="button" style="background: #d63638; border-color: #d63638; color: #fff;" onclick="return confirm('<?php echo esc_js(__('Are you ABSOLUTELY sure? ALL analytics data will be permanently deleted!', 'server-analytics')); ?>');">
								<?php echo esc_html__('Delete All Data', 'server-analytics'); ?>
							</button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Process single and bulk delete actions.
	 */
	private static function process_delete_actions(): void {
		// Single delete
		if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['event'])) {
			$event_id = absint($_GET['event']);
			if ($event_id > 0 && check_admin_referer('sa_delete_event_' . $event_id)) {
				if (SA_DB::delete_event($event_id)) {
					set_transient('sa_admin_notice', array(
						'type'    => 'success',
						'message' => __('Event deleted successfully.', 'server-analytics'),
					), 30);
				} else {
					set_transient('sa_admin_notice', array(
						'type'    => 'error',
						'message' => __('Failed to delete event.', 'server-analytics'),
					), 30);
				}
				wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
				exit;
			}
		}

		// Bulk delete
		if (isset($_POST['sa_bulk_action']) && isset($_POST['sa_bulk_nonce'])) {
			if (!wp_verify_nonce($_POST['sa_bulk_nonce'], 'sa_bulk_action')) {
				wp_die(esc_html__('Security check failed.', 'server-analytics'));
			}

			$action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';
			if ($action === '-1' && isset($_POST['action2'])) {
				$action = sanitize_key($_POST['action2']);
			}

			if ($action === 'bulk_delete' && !empty($_POST['event_ids'])) {
				$ids = array_map('absint', (array) $_POST['event_ids']);
				$deleted = SA_DB::delete_events($ids);

				if ($deleted > 0) {
					set_transient('sa_admin_notice', array(
						'type'    => 'success',
						'message' => sprintf(
							/* translators: %d: number of deleted events */
							_n('%d event deleted successfully.', '%d events deleted successfully.', $deleted, 'server-analytics'),
							$deleted
						),
					), 30);
				} else {
					set_transient('sa_admin_notice', array(
						'type'    => 'error',
						'message' => __('No events were deleted.', 'server-analytics'),
					), 30);
				}

				wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
				exit;
			}
		}
	}

	/**
	 * Handle delete by date range action.
	 */
	public static function handle_delete_by_date(): void {
		if (!current_user_can(sa_view_analytics_capability())) {
			wp_die(esc_html__('You do not have permission to delete analytics.', 'server-analytics'));
		}

		check_admin_referer('sa_delete_by_date', 'sa_delete_date_nonce');

		$date_from = isset($_POST['delete_from']) ? sanitize_text_field($_POST['delete_from']) : '';
		$date_to = isset($_POST['delete_to']) ? sanitize_text_field($_POST['delete_to']) : '';

		if ($date_from === '' || $date_to === '') {
			set_transient('sa_admin_notice', array(
				'type'    => 'error',
				'message' => __('Please provide both start and end dates.', 'server-analytics'),
			), 30);
			wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
			exit;
		}

		$deleted = SA_DB::delete_events_by_date($date_from, $date_to);

		if ($deleted > 0) {
			set_transient('sa_admin_notice', array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: number of deleted events */
					_n('%d event deleted successfully.', '%d events deleted successfully.', $deleted, 'server-analytics'),
					$deleted
				),
			), 30);
		} else {
			set_transient('sa_admin_notice', array(
				'type'    => 'info',
				'message' => __('No events found in the specified date range.', 'server-analytics'),
			), 30);
		}

		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
		exit;
	}

	/**
	 * Handle delete all data action.
	 */
	public static function handle_delete_all(): void {
		if (!current_user_can(sa_view_analytics_capability())) {
			wp_die(esc_html__('You do not have permission to delete analytics.', 'server-analytics'));
		}

		check_admin_referer('sa_delete_all', 'sa_delete_all_nonce');

		if (empty($_POST['confirm_delete_all'])) {
			set_transient('sa_admin_notice', array(
				'type'    => 'error',
				'message' => __('You must confirm that you want to delete all data.', 'server-analytics'),
			), 30);
			wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
			exit;
		}

		$deleted = SA_DB::delete_all_data();

		set_transient('sa_admin_notice', array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: number of deleted events */
				_n('%d event deleted. All analytics data has been removed.', '%d events deleted. All analytics data has been removed.', $deleted, 'server-analytics'),
				$deleted
			),
		), 30);

		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'server-analytics'));
		}

		// Get current settings
		$tracking_mode = SA_DB::get_tracking_mode();
		$excluded_post_types = SA_DB::get_excluded_post_types();
		$excluded_urls = SA_DB::get_excluded_urls_raw();
		$included_urls = SA_DB::get_included_urls_raw();
		$anonymize_ip = SA_DB::is_ip_anonymization_enabled();
		$retention_days = SA_DB::get_data_retention_days();

		// Get all public post types
		$post_types = get_post_types(array('public' => true), 'objects');

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Server Analytics Settings', 'server-analytics'); ?></h1>

			<?php self::render_admin_notices(); ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('sa_save_settings', 'sa_settings_nonce'); ?>
				<input type="hidden" name="action" value="sa_save_settings" />

				<h2><?php echo esc_html__('Tracking Settings', 'server-analytics'); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__('Tracking Mode', 'server-analytics'); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="tracking_mode" value="all" <?php checked($tracking_mode, 'all'); ?> />
									<?php echo esc_html__('Track all pages (exclude specified)', 'server-analytics'); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="tracking_mode" value="whitelist" <?php checked($tracking_mode, 'whitelist'); ?> />
									<?php echo esc_html__('Only track specified pages (whitelist mode)', 'server-analytics'); ?>
								</label>
								<p class="description">
									<?php echo esc_html__('Choose whether to track all pages with exclusions, or only track specific pages.', 'server-analytics'); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="excluded_post_types"><?php echo esc_html__('Exclude Post Types', 'server-analytics'); ?></label>
						</th>
						<td>
							<select name="excluded_post_types[]" id="excluded_post_types" multiple size="8" style="min-width: 300px; height: auto;">
								<?php foreach ($post_types as $post_type) : ?>
									<option value="<?php echo esc_attr($post_type->name); ?>" <?php selected(in_array($post_type->name, $excluded_post_types, true)); ?>>
										<?php echo esc_html($post_type->labels->name . ' (' . $post_type->name . ')'); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__('Hold Ctrl/Cmd to select multiple. Pages of these post types will not be tracked.', 'server-analytics'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="excluded_urls"><?php echo esc_html__('Exclude URLs', 'server-analytics'); ?></label>
						</th>
						<td>
							<textarea name="excluded_urls" id="excluded_urls" rows="6" cols="50" class="large-text code"><?php echo esc_textarea($excluded_urls); ?></textarea>
							<p class="description">
								<?php echo esc_html__('Enter one URL pattern per line. Use * as wildcard.', 'server-analytics'); ?><br />
								<?php echo esc_html__('Examples:', 'server-analytics'); ?><br />
								<code>/wp-admin/*</code> - <?php echo esc_html__('Excludes all admin pages', 'server-analytics'); ?><br />
								<code>/cart/</code> - <?php echo esc_html__('Excludes URLs containing /cart/', 'server-analytics'); ?><br />
								<code>*/checkout*</code> - <?php echo esc_html__('Excludes any URL with "checkout"', 'server-analytics'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="included_urls"><?php echo esc_html__('Only Track URLs (Whitelist)', 'server-analytics'); ?></label>
						</th>
						<td>
							<textarea name="included_urls" id="included_urls" rows="6" cols="50" class="large-text code"><?php echo esc_textarea($included_urls); ?></textarea>
							<p class="description">
								<?php echo esc_html__('Only used when "Whitelist mode" is selected above.', 'server-analytics'); ?><br />
								<?php echo esc_html__('Enter one URL pattern per line. Use * as wildcard.', 'server-analytics'); ?><br />
								<?php echo esc_html__('Examples:', 'server-analytics'); ?><br />
								<code>/blog/*</code> - <?php echo esc_html__('Only track blog pages', 'server-analytics'); ?><br />
								<code>/products/*</code> - <?php echo esc_html__('Only track product pages', 'server-analytics'); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__('Privacy Settings', 'server-analytics'); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__('IP Anonymization', 'server-analytics'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="anonymize_ip" value="1" <?php checked($anonymize_ip); ?> />
								<?php echo esc_html__('Anonymize IP addresses (recommended for GDPR compliance)', 'server-analytics'); ?>
							</label>
							<p class="description">
								<?php echo esc_html__('When enabled, the last octet of IPv4 addresses and last 80 bits of IPv6 addresses are removed.', 'server-analytics'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="retention_days"><?php echo esc_html__('Data Retention', 'server-analytics'); ?></label>
						</th>
						<td>
							<input type="number" name="retention_days" id="retention_days" value="<?php echo esc_attr($retention_days); ?>" min="0" max="3650" class="small-text" />
							<?php echo esc_html__('days', 'server-analytics'); ?>
							<p class="description">
								<?php echo esc_html__('Automatically delete data older than this many days. Set to 0 to keep data indefinitely.', 'server-analytics'); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(__('Save Settings', 'server-analytics')); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle settings form submission.
	 */
	public static function handle_save_settings(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to change settings.', 'server-analytics'));
		}

		check_admin_referer('sa_save_settings', 'sa_settings_nonce');

		// Tracking mode
		$tracking_mode = isset($_POST['tracking_mode']) ? sanitize_key($_POST['tracking_mode']) : 'all';
		SA_DB::set_tracking_mode($tracking_mode);

		// Excluded post types
		$excluded_post_types = isset($_POST['excluded_post_types']) && is_array($_POST['excluded_post_types'])
			? array_map('sanitize_key', $_POST['excluded_post_types'])
			: array();
		SA_DB::set_excluded_post_types($excluded_post_types);

		// Excluded URLs
		$excluded_urls = isset($_POST['excluded_urls']) ? sanitize_textarea_field($_POST['excluded_urls']) : '';
		SA_DB::set_excluded_urls($excluded_urls);

		// Included URLs
		$included_urls = isset($_POST['included_urls']) ? sanitize_textarea_field($_POST['included_urls']) : '';
		SA_DB::set_included_urls($included_urls);

		// IP anonymization
		$anonymize_ip = isset($_POST['anonymize_ip']) && $_POST['anonymize_ip'] === '1';
		SA_DB::set_ip_anonymization($anonymize_ip);

		// Data retention
		$retention_days = isset($_POST['retention_days']) ? absint($_POST['retention_days']) : 90;
		SA_DB::set_data_retention_days($retention_days);

		set_transient('sa_admin_notice', array(
			'type'    => 'success',
			'message' => __('Settings saved successfully.', 'server-analytics'),
		), 30);

		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '-settings'));
		exit;
	}

	/**
	 * Render admin notices.
	 */
	private static function render_admin_notices(): void {
		$notice = get_transient('sa_admin_notice');
		if (!$notice || !is_array($notice)) {
			return;
		}

		delete_transient('sa_admin_notice');

		$type = in_array($notice['type'] ?? '', array('success', 'error', 'warning', 'info'), true)
			? $notice['type']
			: 'info';
		$message = $notice['message'] ?? '';

		if ($message === '') {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr($type),
			esc_html($message)
		);
	}

	private static function assert_export_access(): void {
		if (!current_user_can(sa_view_analytics_capability())) {
			wp_die(esc_html__('You do not have permission to export analytics.', 'server-analytics'));
		}
		check_admin_referer('sa_export');
	}

	/**
	 * Load list table class on demand to reduce memory on other admin pages.
	 */
	private static function load_list_table_class(): void {
		if (!class_exists('SA_List_Table')) {
			require_once SA_PLUGIN_DIR . 'includes/class-sa-list-table.php';
		}
	}

	/**
	 * Load PDF class on demand.
	 */
	private static function load_pdf_class(): void {
		if (!class_exists('SA_PDF')) {
			require_once SA_PLUGIN_DIR . 'includes/class-sa-pdf.php';
		}
	}

	public static function export_csv(): void {
		self::assert_export_access();

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=server-analytics-' . gmdate('Ymd-His') . '.csv');

		$out = fopen('php://output', 'w');
		if ($out === false) {
			wp_die(esc_html__('Unable to generate export.', 'server-analytics'));
		}

		// Write header
		fputcsv($out, array('created_at', 'event_type', 'page_url', 'referrer_url', 'link_url', 'ip_address', 'time_on_page', 'scroll_depth'));

		// Stream rows in batches to reduce memory usage
		$filters = self::current_filters();
		$batch_size = 1000;
		$offset = 0;
		$max_rows = 50000; // Safety limit

		while ($offset < $max_rows) {
			$rows = self::query_export_batch($filters, $batch_size, $offset);

			if (empty($rows)) {
				break;
			}

			foreach ($rows as $row) {
				fputcsv(
					$out,
					array(
						(string) ($row['created_at'] ?? ''),
						(string) ($row['event_type'] ?? ''),
						(string) ($row['page_url'] ?? ''),
						(string) ($row['referrer_url'] ?? ''),
						(string) ($row['link_url'] ?? ''),
						(string) ($row['ip_address'] ?? ''),
						(string) ($row['time_on_page'] ?? ''),
						(string) ($row['scroll_depth'] ?? ''),
					)
				);
			}

			// Flush output buffer periodically
			if (ob_get_level() > 0) {
				ob_flush();
			}
			flush();

			$offset += $batch_size;

			// Free memory
			unset($rows);

			// If we got less than batch size, we're done
			if (count($rows ?? array()) < $batch_size) {
				break;
			}
		}

		fclose($out);
		exit;
	}

	/**
	 * Query export data in batches for memory efficiency.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private static function query_export_batch(array $filters, int $limit, int $offset): array {
		global $wpdb;
		$table = SA_DB::table_name();

		$where = array();
		$params = array();

		$event_type = isset($filters['event_type']) ? sanitize_key((string) $filters['event_type']) : '';
		if ($event_type !== '') {
			$where[] = 'event_type = %s';
			$params[] = $event_type;
		}

		$date_from = isset($filters['date_from']) ? sanitize_text_field((string) $filters['date_from']) : '';
		$date_to   = isset($filters['date_to']) ? sanitize_text_field((string) $filters['date_to']) : '';

		if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
			$where[] = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
			$where[] = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset($filters['s']) ? sanitize_text_field((string) $filters['s']) : '';
		// Limit search term length to prevent abuse
		if (strlen($search) > 200) {
			$search = substr($search, 0, 200);
		}
		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(page_url LIKE %s OR referrer_url LIKE %s OR link_url LIKE %s OR ip_address LIKE %s)';
			array_push($params, $like, $like, $like, $like);
		}

		$where_sql = $where !== array() ? 'WHERE ' . implode(' AND ', $where) : '';

		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT created_at, event_type, page_url, referrer_url, link_url, ip_address, time_on_page, scroll_depth
			FROM {$table}
			{$where_sql}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public static function export_pdf(): void {
		self::assert_export_access();

		// Load classes on demand
		self::load_list_table_class();
		self::load_pdf_class();

		$filters = self::current_filters();
		$table = new SA_List_Table($filters);
		$rows = $table->get_items_for_export(500); // Reduced for memory efficiency

		$headers = array('created_at', 'event_type', 'page_url', 'referrer_url', 'link_url', 'ip_address', 'time_on_page', 'scroll_depth');
		$pdf_rows = array();
		foreach ($rows as $row) {
			$pdf_rows[] = array(
				(string) ($row['created_at'] ?? ''),
				(string) ($row['event_type'] ?? ''),
				(string) ($row['page_url'] ?? ''),
				(string) ($row['referrer_url'] ?? ''),
				(string) ($row['link_url'] ?? ''),
				(string) ($row['ip_address'] ?? ''),
				(string) ($row['time_on_page'] ?? ''),
				(string) ($row['scroll_depth'] ?? ''),
			);
		}

		$title = 'Server Analytics export (UTC) - ' . gmdate('Y-m-d H:i:s');
		$pdf = SA_PDF::render($title, $headers, $pdf_rows);

		nocache_headers();
		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename=server-analytics-' . gmdate('Ymd-His') . '.pdf');
		header('Content-Length: ' . strlen($pdf));
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

