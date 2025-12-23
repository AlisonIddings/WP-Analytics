<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class SA_List_Table extends WP_List_Table {
	/** @var array<string, mixed> */
	private array $filters = array();

	/**
	 * @param array<string, mixed> $filters
	 */
	public function __construct(array $filters = array()) {
		parent::__construct(
			array(
				'singular' => 'sa_event',
				'plural'   => 'sa_events',
				'ajax'     => false,
			)
		);
		$this->filters = $filters;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at'   => __('Date / Time (UTC)', 'server-analytics'),
			'event_type'   => __('Event', 'server-analytics'),
			'page_url'     => __('Page URL', 'server-analytics'),
			'referrer_url' => __('Referrer', 'server-analytics'),
			'link_url'     => __('Link clicked', 'server-analytics'),
			'ip_address'   => __('IP address', 'server-analytics'),
			'time_on_page' => __('Time on page (s)', 'server-analytics'),
			'scroll_depth' => __('Scroll depth (%)', 'server-analytics'),
		);
	}

	/**
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at'   => array('created_at', true),
			'event_type'   => array('event_type', false),
			'ip_address'   => array('ip_address', false),
			'time_on_page' => array('time_on_page', false),
			'scroll_depth' => array('scroll_depth', false),
		);
	}

	public function no_items(): void {
		esc_html_e('No analytics events found for the selected filters.', 'server-analytics');
	}

	/**
	 * @param array<string, mixed> $item
	 * @param string $column_name
	 */
	public function column_default($item, $column_name): string {
		$value = $item[$column_name] ?? '';

		if (in_array($column_name, array('page_url', 'referrer_url', 'link_url'), true)) {
			$url = is_string($value) ? $value : '';
			if ($url === '') {
				return '&mdash;';
			}
			$label = esc_html(self::truncate($url, 70));
			$href  = esc_url($url);
			return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
		}

		if (in_array($column_name, array('time_on_page', 'scroll_depth'), true)) {
			$num = is_numeric($value) ? (int) $value : null;
			return $num === null ? '&mdash;' : esc_html((string) $num);
		}

		return $value === '' ? '&mdash;' : esc_html((string) $value);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_items_for_export(int $limit = 20000): array {
		return $this->query_items($limit, 1, false);
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page('sa_events_per_page', 20);
		$current_page = $this->get_pagenum();

		$this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

		$this->items = $this->query_items($per_page, $current_page, true);
		$total_items = $this->count_items();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function query_items(int $per_page, int $page_number, bool $paginate): array {
		global $wpdb;
		$table = SA_DB::table_name();

		$where = array();
		$params = array();

		$event_type = isset($this->filters['event_type']) ? sanitize_key((string) $this->filters['event_type']) : '';
		if ($event_type !== '') {
			$where[] = 'event_type = %s';
			$params[] = $event_type;
		}

		$date_from = isset($this->filters['date_from']) ? sanitize_text_field((string) $this->filters['date_from']) : '';
		$date_to   = isset($this->filters['date_to']) ? sanitize_text_field((string) $this->filters['date_to']) : '';

		if ($date_from !== '') {
			$where[] = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ($date_to !== '') {
			$where[] = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset($this->filters['s']) ? sanitize_text_field((string) $this->filters['s']) : '';
		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(page_url LIKE %s OR referrer_url LIKE %s OR link_url LIKE %s OR ip_address LIKE %s)';
			array_push($params, $like, $like, $like, $like);
		}

		$where_sql = '';
		if ($where !== array()) {
			$where_sql = 'WHERE ' . implode(' AND ', $where);
		}

		$orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'created_at';
		$order   = isset($_GET['order']) ? strtoupper((string) $_GET['order']) : 'DESC';

		$allowed_orderby = array('created_at', 'event_type', 'ip_address', 'time_on_page', 'scroll_depth', 'id');
		if (!in_array($orderby, $allowed_orderby, true)) {
			$orderby = 'created_at';
		}
		if (!in_array($order, array('ASC', 'DESC'), true)) {
			$order = 'DESC';
		}

		$limit_sql = '';
		if ($paginate) {
			$offset = ($page_number - 1) * $per_page;
			$limit_sql = $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, $offset);
		} else {
			$limit_sql = $wpdb->prepare('LIMIT %d', $per_page);
		}

		$sql = "SELECT id, created_at, event_type, page_url, referrer_url, link_url, ip_address, time_on_page, scroll_depth
			FROM {$table}
			{$where_sql}
			ORDER BY {$orderby} {$order}
			{$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $params === array() ? $wpdb->get_results($sql, ARRAY_A) : $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	private function count_items(): int {
		global $wpdb;
		$table = SA_DB::table_name();

		$where = array();
		$params = array();

		$event_type = isset($this->filters['event_type']) ? sanitize_key((string) $this->filters['event_type']) : '';
		if ($event_type !== '') {
			$where[] = 'event_type = %s';
			$params[] = $event_type;
		}

		$date_from = isset($this->filters['date_from']) ? sanitize_text_field((string) $this->filters['date_from']) : '';
		$date_to   = isset($this->filters['date_to']) ? sanitize_text_field((string) $this->filters['date_to']) : '';

		if ($date_from !== '') {
			$where[] = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ($date_to !== '') {
			$where[] = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset($this->filters['s']) ? sanitize_text_field((string) $this->filters['s']) : '';
		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(page_url LIKE %s OR referrer_url LIKE %s OR link_url LIKE %s OR ip_address LIKE %s)';
			array_push($params, $like, $like, $like, $like);
		}

		$where_sql = $where !== array() ? 'WHERE ' . implode(' AND ', $where) : '';
		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $params === array() ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $params));
		return (int) $count;
	}

	private static function truncate(string $s, int $max): string {
		if (strlen($s) <= $max) {
			return $s;
		}
		return substr($s, 0, max(0, $max - 1)) . '…';
	}
}

