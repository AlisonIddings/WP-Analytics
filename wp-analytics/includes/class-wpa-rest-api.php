<?php
/**
 * REST API handler for WP Analytics.
 *
 * Provides endpoints for receiving analytics data from the frontend
 * tracking script. Includes security measures like token validation,
 * rate limiting, and input sanitization.
 *
 * @package WP_Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_REST_API
 *
 * Registers and handles REST API endpoints for analytics tracking:
 * - POST /wp-analytics/v1/pageview - Record a page view
 * - POST /wp-analytics/v1/engagement - Update engagement metrics
 * - POST /wp-analytics/v1/link-click - Record a link click
 * - POST /wp-analytics/v1/conversion - Record a conversion event
 * - GET  /wp-analytics/v1/audit-export - Export monthly analytics report
 * - GET  /wp-analytics/v1/gsc-test - Test Google Search Console connection
 *
 * @since 1.0.0
 */
final class WPA_REST_API {

	/*
	 * =========================================================================
	 * CONSTANTS
	 * =========================================================================
	 */

	/** @var string REST API namespace */
	private const NAMESPACE = 'wp-analytics/v1';

	/** @var int Rate limit window in seconds */
	private const RATE_LIMIT_WINDOW = 60;

	/** @var int Maximum requests per IP per window */
	private const RATE_LIMIT_MAX_REQUESTS = 30;

	/*
	 * =========================================================================
	 * INITIALIZATION
	 * =========================================================================
	 */

	/**
	 * Initialize the REST API endpoints.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST API routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {

		// Pageview endpoint - records when a user visits a page
		register_rest_route(
			self::NAMESPACE,
			'/pageview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_pageview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'page_url' => array(
						'type'     => 'string',
						'required' => true,
					),
					'referrer' => array(
						'type'     => 'string',
						'required' => false,
					),
					'session'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		// Engagement endpoint - updates time on page and scroll depth
		register_rest_route(
			self::NAMESPACE,
			'/engagement',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_engagement' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'pageview_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'session'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'time_on_page' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'scroll_depth' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		// Link click endpoint - records when a user clicks a link
		register_rest_route(
			self::NAMESPACE,
			'/link-click',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_link_click' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'pageview_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'page_url'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'link_url'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'referrer'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'session'     => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		// Conversion endpoint - records button clicks for conversion tracking
		register_rest_route(
			self::NAMESPACE,
			'/conversion',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_conversion' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'pageview_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'button_id'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'page_url'    => array(
						'type'     => 'string',
						'required' => true,
					),
				'session'     => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);

		// Audit export endpoint - returns JSON analytics report for a month
		register_rest_route(
			self::NAMESPACE,
			'/audit-export',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_audit_export' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
					'month' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		// GSC connection test endpoint
		register_rest_route(
			self::NAMESPACE,
			'/gsc-test',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_gsc_test' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/*
	 * =========================================================================
	 * REQUEST HANDLERS
	 * =========================================================================
	 */

	/**
	 * Handle pageview requests.
	 *
	 * Creates a new pageview record when a user visits a page.
	 * Returns the pageview ID for subsequent engagement/click tracking.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_pageview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Validate token, origin, rate limit
		$valid = self::validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		global $wpdb;
		$table = WPA_Database::table_name();

		// Validate and sanitize input
		$page_url = self::validate_url( (string) $request->get_param( 'page_url' ) );
		if ( $page_url === '' ) {
			return new WP_Error(
				'wpa_invalid_page_url',
				__( 'Invalid page URL.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		$referrer = self::validate_url( (string) $request->get_param( 'referrer' ) );
		$session  = self::validate_session_id( (string) $request->get_param( 'session' ) );
		$ip       = self::get_client_ip();
		$ua       = self::sanitize_user_agent();

		// Insert the pageview record
		$sql  = "INSERT INTO {$table}
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
			gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( $result === false ) {
			return new WP_Error(
				'wpa_db_error',
				__( 'Database insert failed.', 'wp-analytics' ),
				array( 'status' => 500 )
			);
		}

		$pageview_id = (int) $wpdb->insert_id;

		return new WP_REST_Response( array( 'pageview_id' => $pageview_id ), 200 );
	}

	/**
	 * Handle engagement updates.
	 *
	 * Updates the time on page and scroll depth for an existing pageview.
	 * Called when the user leaves the page or periodically during their visit.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_engagement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$valid = self::validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Validate pageview ID
		$pageview_id = absint( $request->get_param( 'pageview_id' ) );
		if ( $pageview_id <= 0 ) {
			return new WP_Error(
				'wpa_invalid_pageview',
				__( 'Invalid pageview ID.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Validate session ID (required for ownership verification)
		$session = self::validate_session_id( (string) $request->get_param( 'session' ) );
		if ( $session === '' ) {
			return new WP_Error(
				'wpa_invalid_session',
				__( 'Invalid session.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Get and validate engagement metrics
		$time_on_page = $request->get_param( 'time_on_page' );
		$scroll_depth = $request->get_param( 'scroll_depth' );

		// Clamp values to reasonable limits
		// Max time: 24 hours (86400 seconds) - prevents unrealistic values
		$time   = is_numeric( $time_on_page ) ? min( 86400, max( 0, (int) $time_on_page ) ) : null;
		$scroll = is_numeric( $scroll_depth ) ? min( 100, max( 0, (int) $scroll_depth ) ) : null;

		// Nothing to update
		if ( $time === null && $scroll === null ) {
			return new WP_REST_Response( array( 'updated' => false ), 200 );
		}

		global $wpdb;
		$table = WPA_Database::table_name();

		// Build update data
		$set     = array();
		$formats = array();

		if ( $time !== null ) {
			$set['time_on_page'] = $time;
			$formats[]           = '%d';
		}
		if ( $scroll !== null ) {
			$set['scroll_depth'] = $scroll;
			$formats[]           = '%d';
		}

		/*
		 * Security: Only allow updating pageviews that belong to the same session.
		 * This prevents IDOR attacks where an attacker could modify someone else's data.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			$set,
			array(
				'id'         => $pageview_id,
				'event_type' => 'pageview',
				'session_id' => $session,
			),
			$formats,
			array( '%d', '%s', '%s' )
		);

		return new WP_REST_Response( array( 'updated' => (bool) $updated ), 200 );
	}

	/**
	 * Handle link click tracking.
	 *
	 * Records when a user clicks a link on a tracked page.
	 * Links the click to the parent pageview for analysis.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_link_click( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$valid = self::validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		global $wpdb;
		$table = WPA_Database::table_name();

		// Validate and sanitize all inputs
		$pageview_id = absint( $request->get_param( 'pageview_id' ) );
		$page_url    = self::validate_url( (string) $request->get_param( 'page_url' ) );
		$link_url    = self::validate_url( (string) $request->get_param( 'link_url' ) );
		$referrer    = self::validate_url( (string) $request->get_param( 'referrer' ) );
		$session     = self::validate_session_id( (string) $request->get_param( 'session' ) );

		if ( $pageview_id <= 0 || $page_url === '' || $link_url === '' || $session === '' ) {
			return new WP_Error(
				'wpa_invalid_params',
				__( 'Invalid parameters.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Security: Verify that the pageview belongs to this session.
		 * Prevents IDOR attacks where clicks could be associated with other sessions.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageview_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d AND session_id = %s AND event_type = %s",
				$pageview_id,
				$session,
				'pageview'
			)
		);

		if ( (int) $pageview_exists === 0 ) {
			return new WP_Error(
				'wpa_invalid_pageview',
				__( 'Invalid pageview.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		$ip = self::get_client_ip();
		$ua = self::sanitize_user_agent();

		// Insert the link click record
		$sql  = "INSERT INTO {$table}
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
			gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( $result === false ) {
			return new WP_Error(
				'wpa_db_error',
				__( 'Database insert failed.', 'wp-analytics' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Handle conversion tracking.
	 *
	 * Records conversions from:
	 * - Button clicks (by ID or class)
	 * - Thank you page URL visits
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_conversion( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$valid = self::validate_request( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		global $wpdb;
		$table = WPA_Database::table_name();

		// Validate pageview ID
		$pageview_id = absint( $request->get_param( 'pageview_id' ) );

		// Get conversion type and value
		$conversion_type  = sanitize_key( (string) $request->get_param( 'conversion_type' ) );
		$conversion_value = (string) $request->get_param( 'conversion_value' );

		// Support legacy button_id parameter
		if ( $conversion_type === '' && $request->get_param( 'button_id' ) !== null ) {
			$conversion_type  = 'id';
			$conversion_value = (string) $request->get_param( 'button_id' );
		}

		// Validate conversion value length
		if ( strlen( $conversion_value ) > 500 ) {
			return new WP_Error(
				'wpa_invalid_conversion',
				__( 'Conversion value too long.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		$page_url = self::validate_url( (string) $request->get_param( 'page_url' ) );
		$session  = self::validate_session_id( (string) $request->get_param( 'session' ) );

		if ( $pageview_id <= 0 || $conversion_value === '' || $page_url === '' || $session === '' ) {
			return new WP_Error(
				'wpa_invalid_params',
				__( 'Invalid parameters.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Validate conversion type is allowed
		$allowed_types = array( 'id', 'class', 'url' );
		if ( ! in_array( $conversion_type, $allowed_types, true ) ) {
			return new WP_Error(
				'wpa_invalid_conversion_type',
				__( 'Invalid conversion type.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize based on type
		if ( $conversion_type === 'url' ) {
			$conversion_value = sanitize_text_field( $conversion_value );
		} else {
			$conversion_value = sanitize_html_class( $conversion_value );
		}

		if ( $conversion_value === '' ) {
			return new WP_Error(
				'wpa_invalid_conversion',
				__( 'Invalid conversion value.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Verify conversion is configured for tracking
		$is_valid_conversion = false;
		$conversion_name     = $conversion_value;

		if ( $conversion_type === 'url' ) {
			// Check if URL matches any configured conversion URL
			$url_name = WPA_Database::check_conversion_url( $conversion_value );
			if ( $url_name !== false ) {
				$is_valid_conversion = true;
				$conversion_name     = $url_name;
			}
		} else {
			// Check button IDs or classes
			$selectors = WPA_Database::get_enabled_conversion_selectors();
			if ( $conversion_type === 'id' && in_array( $conversion_value, $selectors['ids'], true ) ) {
				$is_valid_conversion = true;
				$conversion_name     = WPA_Database::get_conversion_button_name( $conversion_value );
			} elseif ( $conversion_type === 'class' && in_array( $conversion_value, $selectors['classes'], true ) ) {
				$is_valid_conversion = true;
				$conversion_name     = WPA_Database::get_conversion_button_name( $conversion_value );
			}
		}

		if ( ! $is_valid_conversion ) {
			return new WP_Error(
				'wpa_invalid_conversion',
				__( 'Conversion not configured for tracking.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Security: Verify pageview belongs to this session
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageview_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d AND session_id = %s AND event_type = %s",
				$pageview_id,
				$session,
				'pageview'
			)
		);

		if ( (int) $pageview_exists === 0 ) {
			return new WP_Error(
				'wpa_invalid_pageview',
				__( 'Invalid pageview.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		$ip = self::get_client_ip();
		$ua = self::sanitize_user_agent();

		// Insert conversion record (format: type|value|name)
		$link_url_value = $conversion_type . '|' . $conversion_value . '|' . $conversion_name;

		$sql  = "INSERT INTO {$table}
			(event_type, pageview_id, session_id, page_url, link_url, ip_address, user_agent, time_on_page, scroll_depth, created_at)
			VALUES (%s, %d, %s, %s, %s, %s, %s, NULL, NULL, %s)";
		$args = array(
			'conversion',
			$pageview_id,
			$session,
			$page_url,
			$link_url_value,
			$ip,
			$ua,
			gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		if ( $result === false ) {
			return new WP_Error(
				'wpa_db_error',
				__( 'Database insert failed.', 'wp-analytics' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'type' => $conversion_type,
				'name' => $conversion_name,
			),
			200
		);
	}

	/**
	 * Handle audit export requests.
	 *
	 * Returns a comprehensive JSON analytics report for a specified month.
	 * Uses aggregated data from wpa_daily_stats where available, falls back
	 * to wpa_events for the current/recent month if not yet aggregated.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_audit_export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Validate token from query params
		$valid = self::validate_export_token( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Parse month parameter (YYYY-MM format), default to previous complete month
		$month_param = (string) $request->get_param( 'month' );
		$month_info  = self::parse_month_param( $month_param );

		if ( is_wp_error( $month_info ) ) {
			return $month_info;
		}

		$period     = $month_info['period'];
		$start_date = $month_info['start_date'];
		$end_date   = $month_info['end_date'];

		// Determine if we should use aggregated data or real-time
		$use_realtime = self::should_use_realtime( $start_date, $end_date );

		// Fetch all data
		$summary              = self::get_audit_summary( $start_date, $end_date, $use_realtime );
		$top_pages            = self::get_audit_top_pages( $start_date, $end_date, $use_realtime );
		$traffic_trend        = self::get_audit_traffic_trend( $start_date, $end_date, $use_realtime );
		$top_entry_pages      = WPA_Database::get_top_entry_pages( $start_date, $end_date, 10 );
		$top_outbound_links   = WPA_Database::get_top_outbound_links( $start_date, $end_date, 10 );
		$conversions_by_goal  = WPA_Database::get_conversions_by_goal( $start_date, $end_date );

		// Fetch GSC and PageSpeed data
		require_once WPA_PLUGIN_DIR . 'includes/class-wpa-gsc.php';
		$gsc_data  = WPA_GSC::get_gsc_data( $start_date, $end_date );
		$pagespeed = WPA_GSC::get_pagespeed_data( home_url() );

		// Remove error key from GSC data if present (don't expose internal errors)
		if ( isset( $gsc_data['error'] ) ) {
			$gsc_data = array();
		}

		// Calculate conversion rate
		$total_sessions    = (int) $summary['total_sessions'];
		$total_conversions = (int) $summary['total_conversions'];
		$conversion_rate   = $total_sessions > 0
			? round( ( $total_conversions / $total_sessions ) * 100, 2 )
			: 0.0;

		// Build response
		$response = array(
			'site_url'             => home_url(),
			'period'               => $period,
			'generated_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'summary'              => array(
				'total_pageviews'   => (int) $summary['total_pageviews'],
				'total_sessions'    => $total_sessions,
				'avg_time_on_page'  => (int) $summary['avg_time'],
				'avg_scroll_depth'  => (int) $summary['avg_scroll'],
				'total_conversions' => $total_conversions,
				'conversion_rate'   => $conversion_rate,
			),
			'top_pages'            => $top_pages,
			'traffic_trend'        => $traffic_trend,
			'top_entry_pages'      => $top_entry_pages,
			'top_outbound_links'   => $top_outbound_links,
			'conversions_by_goal'  => $conversions_by_goal,
			'gsc'                  => $gsc_data,
			'pagespeed'            => $pagespeed,
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Validate token for export endpoint (GET request).
	 *
	 * Simplified validation for read-only export - only checks token.
	 * No Content-Type, same-origin, or rate limiting checks needed.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private static function validate_export_token( WP_REST_Request $request ): true|WP_Error {
		$token        = (string) $request->get_param( 'token' );
		$stored_token = WPA_Database::get_public_token();

		if ( $stored_token === '' || $token === '' || ! hash_equals( $stored_token, $token ) ) {
			return new WP_Error(
				'wpa_invalid_token',
				__( 'Invalid token.', 'wp-analytics' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Parse month parameter into date range.
	 *
	 * @param string $month_param Month in YYYY-MM format, or empty for previous month.
	 * @return array{period: string, start_date: string, end_date: string}|WP_Error
	 */
	private static function parse_month_param( string $month_param ): array|WP_Error {
		if ( $month_param === '' ) {
			// Default to previous complete month
			$prev_month = strtotime( 'first day of last month' );
			$year       = (int) gmdate( 'Y', $prev_month );
			$month      = (int) gmdate( 'm', $prev_month );
		} else {
			// Validate YYYY-MM format
			if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month_param, $matches ) ) {
				return new WP_Error(
					'wpa_invalid_month',
					__( 'Invalid month format. Use YYYY-MM.', 'wp-analytics' ),
					array( 'status' => 400 )
				);
			}

			$year  = (int) $matches[1];
			$month = (int) $matches[2];

			// Validate month is reasonable
			if ( $month < 1 || $month > 12 || $year < 2000 || $year > 2100 ) {
				return new WP_Error(
					'wpa_invalid_month',
					__( 'Invalid month value.', 'wp-analytics' ),
					array( 'status' => 400 )
				);
			}
		}

		$start_date = sprintf( '%04d-%02d-01', $year, $month );
		$end_date   = gmdate( 'Y-m-t', strtotime( $start_date ) );
		$period     = sprintf( '%04d-%02d', $year, $month );

		return array(
			'period'     => $period,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
	}

	/**
	 * Determine if real-time data should be used instead of aggregated.
	 *
	 * Uses real-time if:
	 * - No aggregated stats exist at all
	 * - The requested period includes the current month (not yet fully aggregated)
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return bool True if real-time data should be used.
	 */
	private static function should_use_realtime( string $start_date, string $end_date ): bool {
		// Check if aggregated stats exist
		if ( ! WPA_Database::has_aggregated_stats() ) {
			return true;
		}

		// Check if period includes current month (which may not be aggregated yet)
		$current_month_start = gmdate( 'Y-m-01' );
		if ( $end_date >= $current_month_start ) {
			return true;
		}

		return false;
	}

	/**
	 * Get summary stats for audit export.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param bool   $use_realtime Whether to use real-time data.
	 * @return array<string, int>
	 */
	private static function get_audit_summary( string $start_date, string $end_date, bool $use_realtime ): array {
		if ( $use_realtime ) {
			return WPA_Database::get_realtime_summary_stats( $start_date, $end_date );
		}
		return WPA_Database::get_summary_stats( $start_date, $end_date );
	}

	/**
	 * Get top pages for audit export with bounce indicator.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param bool   $use_realtime Whether to use real-time data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_audit_top_pages( string $start_date, string $end_date, bool $use_realtime ): array {
		// Get base top pages data
		if ( $use_realtime ) {
			$pages = WPA_Database::get_realtime_top_pages( $start_date, $end_date, 20 );
		} else {
			$pages = WPA_Database::get_top_pages( $start_date, $end_date, 20 );
		}

		// Get bounce data for the period
		$bounces = WPA_Database::get_bounce_counts_by_page( $start_date, $end_date );

		// Format response
		$result = array();
		foreach ( $pages as $page ) {
			$page_path = $page['page_path'] ?? '';
			$result[]  = array(
				'page_path'        => $page_path,
				'pageviews'        => (int) ( $page['total_pageviews'] ?? 0 ),
				'sessions'         => (int) ( $page['total_sessions'] ?? 0 ),
				'avg_time'         => (int) ( $page['avg_time'] ?? 0 ),
				'avg_scroll'       => (int) ( $page['avg_scroll'] ?? 0 ),
				'conversions'      => (int) ( $page['total_conversions'] ?? 0 ),
				'bounce_indicator' => (int) ( $bounces[ $page_path ] ?? 0 ),
			);
		}

		return $result;
	}

	/**
	 * Get traffic trend for audit export.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param bool   $use_realtime Whether to use real-time data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_audit_traffic_trend( string $start_date, string $end_date, bool $use_realtime ): array {
		if ( $use_realtime ) {
			$trends = WPA_Database::get_realtime_daily_trends_for_range( $start_date, $end_date );
		} else {
			$trends = WPA_Database::get_daily_trends_for_range( $start_date, $end_date );
		}

		$result = array();
		foreach ( $trends as $trend ) {
			$result[] = array(
				'date'        => $trend['period'] ?? $trend['date'] ?? '',
				'pageviews'   => (int) ( $trend['pageviews'] ?? 0 ),
				'sessions'    => (int) ( $trend['sessions'] ?? 0 ),
				'conversions' => (int) ( $trend['conversions'] ?? 0 ),
			);
		}

		return $result;
	}

	/*
	 * =========================================================================
	 * SECURITY & VALIDATION
	 * =========================================================================
	 */

	/**
	 * Validate an incoming tracking request.
	 *
	 * Performs multiple security checks:
	 * 1. IP exclusion check (skip tracking for excluded IPs)
	 * 2. Content-Type validation (prevents CSRF via form submission)
	 * 3. Token validation (ensures request comes from our tracking script)
	 * 4. Same-origin validation (blocks cross-site requests)
	 * 5. Rate limiting (prevents abuse)
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private static function validate_request( WP_REST_Request $request ): true|WP_Error {
		// Check if this IP is excluded from tracking (e.g., site owner's IP)
		// Return a "silent success" error that the client will handle gracefully
		$client_ip = self::get_client_ip_raw();
		if ( WPA_Database::is_ip_excluded( $client_ip ) ) {
			return new WP_Error(
				'wpa_ip_excluded',
				__( 'Tracking disabled for this IP.', 'wp-analytics' ),
				array( 'status' => 204 ) // No Content - silent success
			);
		}

		// Check Content-Type to help prevent CSRF attacks
		$content_type = $request->get_content_type();
		if ( ! isset( $content_type['value'] ) || strpos( $content_type['value'], 'application/json' ) === false ) {
			// Also allow text/plain for Beacon API
			$raw_content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
			if ( strpos( $raw_content_type, 'application/json' ) === false && strpos( $raw_content_type, 'text/plain' ) === false ) {
				return new WP_Error(
					'wpa_invalid_content_type',
					__( 'Invalid content type.', 'wp-analytics' ),
					array( 'status' => 400 )
				);
			}
		}

		// Validate the API token
		$token        = (string) $request->get_param( 'token' );
		$stored_token = WPA_Database::get_public_token();

		// Ensure both tokens exist and match (timing-safe comparison)
		if ( $stored_token === '' || $token === '' || ! hash_equals( $stored_token, $token ) ) {
			return new WP_Error(
				'wpa_invalid_token',
				__( 'Invalid token.', 'wp-analytics' ),
				array( 'status' => 403 )
			);
		}

		// Verify same-origin request
		if ( ! self::is_same_origin() ) {
			return new WP_Error(
				'wpa_invalid_origin',
				__( 'Invalid origin.', 'wp-analytics' ),
				array( 'status' => 403 )
			);
		}

		// Check rate limit
		$rate_check = self::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Check if request appears to be same-origin.
	 *
	 * Compares the Origin or Referer header against the site's home URL.
	 *
	 * @return bool True if same-origin, false otherwise.
	 */
	private static function is_same_origin(): bool {
		$home      = wp_parse_url( home_url() );
		$home_host = isset( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';

		if ( $home_host === '' ) {
			return true; // Can't validate without home host
		}

		// Get origin from headers
		$ref = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$ref = (string) $_SERVER['HTTP_ORIGIN'];
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$ref = (string) $_SERVER['HTTP_REFERER'];
		}

		// Handle missing headers (some privacy browsers strip them)
		if ( $ref === '' ) {
			$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
			// Allow JSON requests without headers (with rate limiting as protection)
			if ( strpos( $content_type, 'application/json' ) !== false ) {
				return true;
			}
			// Reject non-JSON requests without origin headers
			return false;
		}

		// Compare host names
		$ref_url  = wp_parse_url( $ref );
		$ref_host = isset( $ref_url['host'] ) ? strtolower( (string) $ref_url['host'] ) : '';

		return $ref_host === $home_host;
	}

	/**
	 * Check and enforce rate limiting.
	 *
	 * Limits requests per IP address to prevent abuse.
	 * Uses WordPress transients for storage.
	 *
	 * @return true|WP_Error True if within limit, WP_Error if exceeded.
	 */
	private static function check_rate_limit(): true|WP_Error {
		$ip = self::get_client_ip_raw();
		if ( $ip === '' ) {
			return true; // Can't rate limit without IP
		}

		$key = 'wpa_rate_' . md5( $ip );

		// Get current count with optimized query
		global $wpdb;
		$option_name  = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;

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
		$now   = time();

		if ( $row && $row->timeout && (int) $row->timeout > $now ) {
			$count = (int) $row->option_value;
		}

		// Check if rate limit exceeded
		if ( $count >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return new WP_Error(
				'wpa_rate_limited',
				__( 'Too many requests. Please try again later.', 'wp-analytics' ),
				array( 'status' => 429 )
			);
		}

		// Increment counter
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Validate and sanitize a session ID.
	 *
	 * Session IDs should be 16-64 character hexadecimal strings.
	 *
	 * @param string $session The session ID to validate.
	 * @return string Validated session ID, or empty string if invalid.
	 */
	private static function validate_session_id( string $session ): string {
		$session = sanitize_text_field( $session );

		// Validate format: 16-64 hex characters
		if ( $session === '' || ! preg_match( '/^[a-f0-9]{16,64}$/i', $session ) ) {
			return '';
		}

		return $session;
	}

	/**
	 * Validate and sanitize a URL.
	 *
	 * Blocks dangerous URL schemes (javascript:, data:, etc.)
	 * and limits URL length.
	 *
	 * @param string $url The URL to validate.
	 * @return string Validated URL, or empty string if invalid.
	 */
	private static function validate_url( string $url ): string {
		// Limit URL length (2048 is common browser limit)
		if ( strlen( $url ) > 2048 ) {
			$url = substr( $url, 0, 2048 );
		}

		$url = esc_url_raw( $url );
		if ( $url === '' ) {
			return '';
		}

		// Verify URL uses a safe scheme
		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		$allowed_schemes = array( 'http', 'https', 'mailto', 'tel', 'ftp' );
		if ( $scheme !== '' && ! in_array( $scheme, $allowed_schemes, true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize the user agent string.
	 *
	 * Removes potentially dangerous characters and limits length.
	 *
	 * @return string Sanitized user agent.
	 */
	private static function sanitize_user_agent(): string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		$ua = (string) $_SERVER['HTTP_USER_AGENT'];

		// Limit length
		$ua = substr( $ua, 0, 500 );

		// Remove HTML tags
		$ua = wp_strip_all_tags( $ua );

		// Remove control characters
		$ua = preg_replace( '/[\x00-\x1F\x7F]/', '', $ua );

		return is_string( $ua ) ? $ua : '';
	}

	/**
	 * Get raw client IP address for rate limiting.
	 *
	 * Does not anonymize - used only for rate limit tracking.
	 *
	 * @return string IP address or empty string.
	 */
	private static function get_client_ip_raw(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) $_SERVER['REMOTE_ADDR'];
		}

		// Remove any non-IP characters
		$ip = preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip );

		return is_string( $ip ) ? substr( $ip, 0, 45 ) : '';
	}

	/**
	 * Get client IP address for storage.
	 *
	 * Respects the IP anonymization setting.
	 *
	 * @return string IP address (possibly anonymized).
	 */
	private static function get_client_ip(): string {
		$ip = self::get_client_ip_raw();

		// Apply anonymization if enabled
		if ( WPA_Database::is_ip_anonymization_enabled() ) {
			$ip = WPA_Database::anonymize_ip( $ip );
		}

		return $ip;
	}

	/**
	 * Handle GSC connection test request.
	 *
	 * Tests the GSC API connection using stored credentials.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public static function handle_gsc_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Validate token
		$valid = self::validate_export_token( $request );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Load GSC class
		require_once WPA_PLUGIN_DIR . 'includes/class-wpa-gsc.php';

		// Test with last 7 days
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

		$gsc_data = WPA_GSC::get_gsc_data( $start_date, $end_date );

		// Check for error or empty result
		if ( empty( $gsc_data ) ) {
			return new WP_Error(
				'wpa_gsc_not_configured',
				__( 'GSC credentials are not configured. Please enter your property URL, client ID, client secret, and refresh token.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $gsc_data['error'] ) ) {
			return new WP_Error(
				'wpa_gsc_auth_failed',
				__( 'GSC authentication failed. Please verify your client credentials and refresh token are correct.', 'wp-analytics' ),
				array( 'status' => 400 )
			);
		}

		// Return success with basic stats (never include credentials)
		return new WP_REST_Response(
			array(
				'success'       => true,
				'property_url'  => $gsc_data['property_url'] ?? '',
				'queries_found' => count( $gsc_data['top_queries'] ?? array() ),
				'pages_found'   => count( $gsc_data['top_pages_gsc'] ?? array() ),
			),
			200
		);
	}
}
