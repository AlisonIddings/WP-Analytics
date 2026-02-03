<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

require_once SA_PLUGIN_DIR . 'includes/class-sa-list-table.php';
require_once SA_PLUGIN_DIR . 'includes/class-sa-pdf.php';

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
		return array(
			'event_type' => isset($_GET['event_type']) ? sanitize_key((string) $_GET['event_type']) : '',
			'date_from'  => isset($_GET['date_from']) ? sanitize_text_field((string) $_GET['date_from']) : '',
			'date_to'    => isset($_GET['date_to']) ? sanitize_text_field((string) $_GET['date_to']) : '',
			's'          => isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '',
		);
	}

	public static function render_page(): void {
		if (!current_user_can(sa_view_analytics_capability())) {
			wp_die(esc_html__('You do not have permission to view analytics.', 'server-analytics'));
		}

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

	public static function export_csv(): void {
		self::assert_export_access();

		$filters = self::current_filters();
		$table = new SA_List_Table($filters);
		$rows = $table->get_items_for_export(20000);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=server-analytics-' . gmdate('Ymd-His') . '.csv');

		$out = fopen('php://output', 'w');
		if ($out === false) {
			wp_die(esc_html__('Unable to generate export.', 'server-analytics'));
		}

		fputcsv($out, array('created_at', 'event_type', 'page_url', 'referrer_url', 'link_url', 'ip_address', 'time_on_page', 'scroll_depth'));
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
		fclose($out);
		exit;
	}

	public static function export_pdf(): void {
		self::assert_export_access();

		$filters = self::current_filters();
		$table = new SA_List_Table($filters);
		$rows = $table->get_items_for_export(800); // keep a single-page PDF reasonable

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

