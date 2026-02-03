<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class SA_REST {
	private const NAMESPACE = 'server-analytics/v1';
	private const RATE_LIMIT_WINDOW = 60; // seconds
	private const RATE_LIMIT_MAX_REQUESTS = 30; // max requests per window per IP

	public static function init(): void {
		add_action('rest_api_init', array(__CLASS__, 'register_routes'));
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/pageview',
			array(
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_pageview'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => array('type' => 'string', 'required' => true),
					'page_url' => array('type' => 'string', 'required' => true),
					'referrer' => array('type' => 'string', 'required' => false),
					'session'  => array('type' => 'string', 'required' => false),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/engagement',
			array(
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_engagement'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'        => array('type' => 'string', 'required' => true),
					'pageview_id'  => array('type' => 'integer', 'required' => true),
					'session'      => array('type' => 'string', 'required' => true), // Required for ownership validation
					'time_on_page' => array('type' => 'integer', 'required' => false),
					'scroll_depth' => array('type' => 'integer', 'required' => false),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/link-click',
			array(
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_link_click'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array('type' => 'string', 'required' => true),
					'pageview_id' => array('type' => 'integer', 'required' => true),
					'page_url'    => array('type' => 'string', 'required' => true),
					'link_url'    => array('type' => 'string', 'required' => true),
					'referrer'    => array('type' => 'string', 'required' => false),
					'session'     => array('type' => 'string', 'required' => false),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/conversion',
			array(
				'methods'             => 'POST',
				'callback'            => array(__CLASS__, 'handle_conversion'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array('type' => 'string', 'required' => true),
					'pageview_id' => array('type' => 'integer', 'required' => true),
					'button_id'   => array('type' => 'string', 'required' => true),
					'page_url'    => array('type' => 'string', 'required' => true),
					'session'     => array('type' => 'string', 'required' => true),
				),
			)
		);
	}

	/**
	 * Basic same-origin + token check to reduce off-site spam.
	 * Note: analytics endpoints are public by nature.
	 */
	private static function validate_request(WP_REST_Request $request): true|WP_Error {
		// Validate Content-Type is JSON (helps prevent CSRF via form submission)
		$content_type = $request->get_content_type();
		if (!isset($content_type['value']) || strpos($content_type['value'], 'application/json') === false) {
			// Also check raw header for Beacon API which may send text/plain
			$raw_content_type = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
			if (strpos($raw_content_type, 'application/json') === false && strpos($raw_content_type, 'text/plain') === false) {
				return new WP_Error('sa_invalid_content_type', __('Invalid content type.', 'server-analytics'), array('status' => 400));
			}
		}

		$token = (string) $request->get_param('token');
		$stored_token = SA_DB::get_public_token();

		// Ensure stored token exists and is non-empty to prevent bypass with empty token
		if ($stored_token === '' || $token === '' || !hash_equals($stored_token, $token)) {
			return new WP_Error('sa_invalid_token', __('Invalid token.', 'server-analytics'), array('status' => 403));
		}

		$origin_ok = self::is_same_origin();
		if (!$origin_ok) {
			return new WP_Error('sa_invalid_origin', __('Invalid origin.', 'server-analytics'), array('status' => 403));
		}

		// Rate limiting to prevent abuse
		$rate_check = self::check_rate_limit();
		if (is_wp_error($rate_check)) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Simple rate limiting using transients with optimized DB access.
	 * Uses a single atomic operation when possible.
	 */
	private static function check_rate_limit(): true|WP_Error {
		$ip = self::client_ip_raw();
		if ($ip === '') {
			return true; // Can't rate limit without IP
		}

		$key = 'sa_rate_' . md5($ip);

		// Use direct options for better performance than transients
		// Transients can trigger multiple DB queries due to autoload checks
		global $wpdb;

		$option_name = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;

		// Single query to get both value and timeout
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.option_value, t.option_value as timeout 
				FROM {$wpdb->options} o 
				LEFT JOIN {$wpdb->options} t ON t.option_name = %s 
				WHERE o.option_name = %s",
				$timeout_name,
				$option_name
			)
		);

		$count = 0;
		$now = time();

		if ($row && $row->timeout && (int) $row->timeout > $now) {
			$count = (int) $row->option_value;
		}

		if ($count >= self::RATE_LIMIT_MAX_REQUESTS) {
			return new WP_Error(
				'sa_rate_limited',
				__('Too many requests. Please try again later.', 'server-analytics'),
				array('status' => 429)
			);
		}

		// Update or create the transient
		set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
		return true;
	}

	private static function is_same_origin(): bool {
		$home = wp_parse_url(home_url());
		$home_host = isset($home['host']) ? strtolower((string) $home['host']) : '';
		if ($home_host === '') {
			return true;
		}

		$ref = '';
		if (!empty($_SERVER['HTTP_ORIGIN'])) {
			$ref = (string) $_SERVER['HTTP_ORIGIN'];
		} elseif (!empty($_SERVER['HTTP_REFERER'])) {
			$ref = (string) $_SERVER['HTTP_REFERER'];
		}

		// SECURITY: Be more strict about missing Origin/Referer headers
		// Only allow if it's a same-origin request (check for XMLHttpRequest or fetch)
		if ($ref === '') {
			// Allow requests that appear to be legitimate browser requests
			// This catches Beacon API and some AJAX requests
			$content_type = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
			if (strpos($content_type, 'application/json') !== false) {
				// JSON requests without Origin/Referer are suspicious in a browser context
				// but we'll allow them with rate limiting as protection
				// Some privacy browsers/extensions strip these headers
				return true;
			}
			// Non-JSON requests without Origin are rejected
			return false;
		}

		$ref_url = wp_parse_url($ref);
		$ref_host = isset($ref_url['host']) ? strtolower((string) $ref_url['host']) : '';
		return $ref_host === $home_host;
	}

	public static function handle_pageview(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$valid = self::validate_request($request);
		if (is_wp_error($valid)) {
			return $valid;
		}

		global $wpdb;
		$table = SA_DB::table_name();

		$page_url = self::validate_url((string) $request->get_param('page_url'));
		if ($page_url === '') {
			return new WP_Error('sa_invalid_page_url', __('Invalid page URL.', 'server-analytics'), array('status' => 400));
		}

		$referrer = self::validate_url((string) $request->get_param('referrer'));
		$session  = self::validate_session_id((string) $request->get_param('session'));
		$ip       = self::client_ip();
		$ua       = self::sanitize_user_agent();

		$sql = "INSERT INTO {$table}
			(event_type, session_id, page_url, referrer_url, ip_address, user_agent, time_on_page, scroll_depth, created_at)
			VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %s)";

		$args = array(
			'pageview',
			$session,
			$page_url,
			$referrer,
			$ip,
			$ua,
			0,
			0,
			gmdate('Y-m-d H:i:s'),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->query($wpdb->prepare($sql, $args));
		if ($ok === false) {
			return new WP_Error('sa_db_error', __('Database insert failed.', 'server-analytics'), array('status' => 500));
		}
		$pageview_id = (int) $wpdb->insert_id;

		return new WP_REST_Response(array('pageview_id' => $pageview_id), 200);
	}

	public static function handle_engagement(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$valid = self::validate_request($request);
		if (is_wp_error($valid)) {
			return $valid;
		}

		$pageview_id = absint($request->get_param('pageview_id'));
		if ($pageview_id <= 0) {
			return new WP_Error('sa_invalid_pageview', __('Invalid pageview id.', 'server-analytics'), array('status' => 400));
		}

		// Validate and sanitize session ID
		$session = self::validate_session_id((string) $request->get_param('session'));
		if ($session === '') {
			return new WP_Error('sa_invalid_session', __('Invalid session.', 'server-analytics'), array('status' => 400));
		}

		$time_on_page = $request->get_param('time_on_page');
		$scroll_depth = $request->get_param('scroll_depth');

		// Limit time_on_page to reasonable maximum (24 hours = 86400 seconds)
		$time = is_numeric($time_on_page) ? min(86400, max(0, (int) $time_on_page)) : null;
		$scroll = is_numeric($scroll_depth) ? min(100, max(0, (int) $scroll_depth)) : null;

		if ($time === null && $scroll === null) {
			return new WP_REST_Response(array('updated' => false), 200);
		}

		global $wpdb;
		$table = SA_DB::table_name();

		$set = array();
		$formats = array();
		if ($time !== null) {
			$set['time_on_page'] = $time;
			$formats[] = '%d';
		}
		if ($scroll !== null) {
			$set['scroll_depth'] = $scroll;
			$formats[] = '%d';
		}

		// SECURITY: Validate session ownership to prevent IDOR attacks
		// Only allow updating pageviews that belong to the same session
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			$set,
			array(
				'id'         => $pageview_id,
				'event_type' => 'pageview',
				'session_id' => $session, // Ownership check
			),
			$formats,
			array('%d', '%s', '%s')
		);

		return new WP_REST_Response(array('updated' => (bool) $updated), 200);
	}

	public static function handle_link_click(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$valid = self::validate_request($request);
		if (is_wp_error($valid)) {
			return $valid;
		}

		global $wpdb;
		$table = SA_DB::table_name();

		$pageview_id = absint($request->get_param('pageview_id'));
		$page_url = self::validate_url((string) $request->get_param('page_url'));
		$link_url = self::validate_url((string) $request->get_param('link_url'));
		$referrer = self::validate_url((string) $request->get_param('referrer'));
		$session  = self::validate_session_id((string) $request->get_param('session'));

		if ($pageview_id <= 0 || $page_url === '' || $link_url === '' || $session === '') {
			return new WP_Error('sa_invalid_params', __('Invalid parameters.', 'server-analytics'), array('status' => 400));
		}

		// SECURITY: Verify pageview belongs to this session to prevent IDOR attacks
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageview_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d AND session_id = %s AND event_type = %s",
				$pageview_id,
				$session,
				'pageview'
			)
		);

		if ((int) $pageview_exists === 0) {
			return new WP_Error('sa_invalid_pageview', __('Invalid pageview.', 'server-analytics'), array('status' => 400));
		}

		$ip = self::client_ip();
		$ua = self::sanitize_user_agent();

		$sql = "INSERT INTO {$table}
			(event_type, pageview_id, session_id, page_url, referrer_url, link_url, ip_address, user_agent, time_on_page, scroll_depth, created_at)
			VALUES (%s, %d, %s, %s, %s, %s, %s, %s, NULL, NULL, %s)";

		$args = array(
			'link_click',
			$pageview_id,
			$session,
			$page_url,
			$referrer,
			$link_url,
			$ip,
			$ua,
			gmdate('Y-m-d H:i:s'),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->query($wpdb->prepare($sql, $args));
		if ($ok === false) {
			return new WP_Error('sa_db_error', __('Database insert failed.', 'server-analytics'), array('status' => 500));
		}

		return new WP_REST_Response(array('ok' => true), 200);
	}

	public static function handle_conversion(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$valid = self::validate_request($request);
		if (is_wp_error($valid)) {
			return $valid;
		}

		global $wpdb;
		$table = SA_DB::table_name();

		$pageview_id = absint($request->get_param('pageview_id'));
		$raw_button_id = (string) $request->get_param('button_id');
		
		// Limit button ID length before sanitization to prevent abuse
		if (strlen($raw_button_id) > 100) {
			return new WP_Error('sa_invalid_button', __('Button ID too long.', 'server-analytics'), array('status' => 400));
		}
		
		$button_id = sanitize_html_class($raw_button_id);
		$page_url = self::validate_url((string) $request->get_param('page_url'));
		$session = self::validate_session_id((string) $request->get_param('session'));

		if ($pageview_id <= 0 || $button_id === '' || $page_url === '' || $session === '') {
			return new WP_Error('sa_invalid_params', __('Invalid parameters.', 'server-analytics'), array('status' => 400));
		}

		// Verify this is a tracked button
		$enabled_buttons = SA_DB::get_enabled_conversion_button_ids();
		if (!in_array($button_id, $enabled_buttons, true)) {
			return new WP_Error('sa_invalid_button', __('Button not configured for tracking.', 'server-analytics'), array('status' => 400));
		}

		// Verify pageview belongs to session
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageview_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d AND session_id = %s AND event_type = %s",
				$pageview_id,
				$session,
				'pageview'
			)
		);

		if ((int) $pageview_exists === 0) {
			return new WP_Error('sa_invalid_pageview', __('Invalid pageview.', 'server-analytics'), array('status' => 400));
		}

		$ip = self::client_ip();
		$ua = self::sanitize_user_agent();

		// Get the friendly name for the button
		$button_name = SA_DB::get_conversion_button_name($button_id);

		$sql = "INSERT INTO {$table}
			(event_type, pageview_id, session_id, page_url, link_url, ip_address, user_agent, time_on_page, scroll_depth, created_at)
			VALUES (%s, %d, %s, %s, %s, %s, %s, NULL, NULL, %s)";

		$args = array(
			'conversion',
			$pageview_id,
			$session,
			$page_url,
			$button_id . '|' . $button_name, // Store button ID and name in link_url field
			$ip,
			$ua,
			gmdate('Y-m-d H:i:s'),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->query($wpdb->prepare($sql, $args));
		if ($ok === false) {
			return new WP_Error('sa_db_error', __('Database insert failed.', 'server-analytics'), array('status' => 500));
		}

		return new WP_REST_Response(array('ok' => true, 'button' => $button_name), 200);
	}

	/**
	 * Validate and sanitize session ID.
	 * Session IDs should be 32-character hex strings.
	 */
	private static function validate_session_id(string $session): string {
		$session = sanitize_text_field($session);

		// Session ID should be a hex string (32 chars from 16 bytes)
		// Allow some flexibility in length (16-64 chars)
		if ($session === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $session)) {
			return '';
		}

		return $session;
	}

	/**
	 * Validate URL and ensure it uses safe schemes.
	 * Blocks javascript:, data:, vbscript:, and other dangerous schemes.
	 */
	private static function validate_url(string $url): string {
		// Limit URL length to prevent storage abuse (2048 is common browser limit)
		if (strlen($url) > 2048) {
			$url = substr($url, 0, 2048);
		}

		$url = esc_url_raw($url);

		if ($url === '') {
			return '';
		}

		// Parse the URL and check the scheme
		$parsed = wp_parse_url($url);
		$scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';

		// Only allow safe schemes
		$allowed_schemes = array('http', 'https', 'mailto', 'tel', 'ftp');
		if ($scheme !== '' && !in_array($scheme, $allowed_schemes, true)) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize user agent string.
	 */
	private static function sanitize_user_agent(): string {
		if (empty($_SERVER['HTTP_USER_AGENT'])) {
			return '';
		}

		$ua = (string) $_SERVER['HTTP_USER_AGENT'];
		// Limit length
		$ua = substr($ua, 0, 500);
		// Remove any potentially dangerous characters
		$ua = wp_strip_all_tags($ua);
		// Remove control characters
		$ua = preg_replace('/[\x00-\x1F\x7F]/', '', $ua);

		return is_string($ua) ? $ua : '';
	}

	/**
	 * Get raw client IP for rate limiting.
	 */
	private static function client_ip_raw(): string {
		$ip = '';
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = (string) $_SERVER['REMOTE_ADDR'];
		}
		$ip = preg_replace('/[^0-9a-fA-F:\.]/', '', $ip);
		return is_string($ip) ? substr($ip, 0, 45) : '';
	}

	/**
	 * Get client IP for storage (respects anonymization setting).
	 */
	private static function client_ip(): string {
		$ip = self::client_ip_raw();

		// Anonymize IP if enabled (GDPR compliance)
		if (SA_DB::is_ip_anonymization_enabled()) {
			$ip = SA_DB::anonymize_ip($ip);
		}

		return $ip;
	}
}

