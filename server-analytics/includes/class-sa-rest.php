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
	}

	/**
	 * Basic same-origin + token check to reduce off-site spam.
	 * Note: analytics endpoints are public by nature.
	 */
	private static function validate_request(WP_REST_Request $request): true|WP_Error {
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
	 * Simple rate limiting using transients.
	 * Limits requests per IP to prevent abuse/DoS.
	 */
	private static function check_rate_limit(): true|WP_Error {
		$ip = self::client_ip_raw();
		if ($ip === '') {
			return true; // Can't rate limit without IP
		}

		$key = 'sa_rate_' . md5($ip);
		$count = (int) get_transient($key);

		if ($count >= self::RATE_LIMIT_MAX_REQUESTS) {
			return new WP_Error(
				'sa_rate_limited',
				__('Too many requests. Please try again later.', 'server-analytics'),
				array('status' => 429)
			);
		}

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
		if ($ref === '') {
			return true; // Some browsers/extensions strip Origin/Referer.
		}
		$ref_url = wp_parse_url($ref);
		$ref_host = isset($ref_url['host']) ? strtolower((string) $ref_url['host']) : '';
		return $ref_host === '' || $ref_host === $home_host;
	}

	public static function handle_pageview(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$valid = self::validate_request($request);
		if (is_wp_error($valid)) {
			return $valid;
		}

		global $wpdb;
		$table = SA_DB::table_name();

		$page_url = esc_url_raw((string) $request->get_param('page_url'));
		if ($page_url === '') {
			return new WP_Error('sa_invalid_page_url', __('Invalid page URL.', 'server-analytics'), array('status' => 400));
		}

		$referrer = esc_url_raw((string) $request->get_param('referrer'));
		$session  = sanitize_text_field((string) $request->get_param('session'));
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

		$time_on_page = $request->get_param('time_on_page');
		$scroll_depth = $request->get_param('scroll_depth');

		$time = is_numeric($time_on_page) ? max(0, (int) $time_on_page) : null;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update($table, $set, array('id' => $pageview_id, 'event_type' => 'pageview'), $formats, array('%d', '%s'));

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
		$page_url = esc_url_raw((string) $request->get_param('page_url'));
		$link_url = esc_url_raw((string) $request->get_param('link_url'));
		$referrer = esc_url_raw((string) $request->get_param('referrer'));
		$session  = sanitize_text_field((string) $request->get_param('session'));

		if ($pageview_id <= 0 || $page_url === '' || $link_url === '') {
			return new WP_Error('sa_invalid_params', __('Invalid parameters.', 'server-analytics'), array('status' => 400));
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

