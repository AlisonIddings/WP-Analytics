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

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Server Analytics', 'server-analytics'); ?></h1>

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

			<?php $table->display(); ?>
		</div>
		<?php
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

