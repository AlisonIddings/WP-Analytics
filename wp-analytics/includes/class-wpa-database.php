<?php
/**
 * Database handler for WP Analytics.
 *
 * Manages the custom database table for storing analytics events,
 * handles all CRUD operations, and manages plugin settings.
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
 * Class WPA_Database
 *
 * Handles all database operations for the WP Analytics plugin including:
 * - Table creation and management
 * - Event storage (pageviews, clicks, conversions)
 * - Settings management (privacy, tracking, retention)
 * - Data cleanup and deletion
 *
 * @since 1.0.0
 */
final class WPA_Database {

	/*
	 * =========================================================================
	 * CONSTANTS
	 * =========================================================================
	 */

	/** @var string Database table name suffix (prefixed with $wpdb->prefix) */
	public const TABLE_SLUG = 'wpa_events';

	/** @var string Option key for database version */
	private const OPTION_DB_VERSION = 'wpa_db_version';

	/** @var string Option key for public API token */
	private const OPTION_TOKEN = 'wpa_public_token';

	/** @var string Option key for IP anonymization setting */
	private const OPTION_ANONYMIZE_IP = 'wpa_anonymize_ip';

	/** @var string Option key for data retention period */
	private const OPTION_DATA_RETENTION = 'wpa_data_retention_days';

	/** @var string Option key for tracking mode (all/whitelist) */
	private const OPTION_TRACKING_MODE = 'wpa_tracking_mode';

	/** @var string Option key for excluded post types */
	private const OPTION_EXCLUDED_POST_TYPES = 'wpa_excluded_post_types';

	/** @var string Option key for excluded URL patterns */
	private const OPTION_EXCLUDED_URLS = 'wpa_excluded_urls';

	/** @var string Option key for included URL patterns (whitelist) */
	private const OPTION_INCLUDED_URLS = 'wpa_included_urls';

	/** @var string Option key for conversion button configurations */
	private const OPTION_CONVERSION_BUTTONS = 'wpa_conversion_buttons';

	/** @var string Current database schema version */
	private const DB_VERSION = '1.1.0';

	/*
	 * =========================================================================
	 * STATIC CACHE
	 * =========================================================================
	 * Stores frequently accessed options to avoid repeated database queries.
	 */

	/** @var array<string, mixed> In-memory cache for options */
	private static array $cache = array();

	/*
	 * =========================================================================
	 * TABLE MANAGEMENT
	 * =========================================================================
	 */

	/**
	 * Get the full database table name with WordPress prefix.
	 *
	 * @return string Full table name (e.g., 'wp_wpa_events')
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/**
	 * Activate the plugin - create database table and set defaults.
	 *
	 * Called during plugin activation via register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::maybe_create_table();
		self::maybe_seed_public_token();
		self::maybe_set_default_options();
	}

	/**
	 * Uninstall the plugin - remove all data and options.
	 *
	 * Called during plugin deletion via register_uninstall_hook().
	 * This permanently removes all analytics data.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		// Drop the events table
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// Remove all plugin options
		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_TOKEN );
		delete_option( self::OPTION_ANONYMIZE_IP );
		delete_option( self::OPTION_DATA_RETENTION );
		delete_option( self::OPTION_TRACKING_MODE );
		delete_option( self::OPTION_EXCLUDED_POST_TYPES );
		delete_option( self::OPTION_EXCLUDED_URLS );
		delete_option( self::OPTION_INCLUDED_URLS );
		delete_option( self::OPTION_CONVERSION_BUTTONS );
	}

	/*
	 * =========================================================================
	 * TOKEN MANAGEMENT
	 * =========================================================================
	 */

	/**
	 * Get the public API token used for frontend tracking requests.
	 *
	 * This token validates that tracking requests originate from
	 * the legitimate tracking script on this site.
	 *
	 * @return string The public token (24 alphanumeric characters)
	 */
	public static function get_public_token(): string {
		// Return cached value if available
		if ( isset( self::$cache['token'] ) ) {
			return self::$cache['token'];
		}

		$token                 = get_option( self::OPTION_TOKEN, '' );
		$token                 = is_string( $token ) ? $token : '';
		self::$cache['token'] = $token;

		return $token;
	}

	/*
	 * =========================================================================
	 * PRIVACY SETTINGS
	 * =========================================================================
	 */

	/**
	 * Check if IP anonymization is enabled.
	 *
	 * When enabled, IP addresses are anonymized before storage:
	 * - IPv4: Last octet set to 0 (e.g., 192.168.1.100 → 192.168.1.0)
	 * - IPv6: Last 80 bits zeroed out
	 *
	 * @return bool True if IP anonymization is enabled (default: true)
	 */
	public static function is_ip_anonymization_enabled(): bool {
		if ( isset( self::$cache['anonymize_ip'] ) ) {
			return self::$cache['anonymize_ip'];
		}

		$enabled                      = (bool) get_option( self::OPTION_ANONYMIZE_IP, true );
		self::$cache['anonymize_ip'] = $enabled;

		return $enabled;
	}

	/**
	 * Set IP anonymization setting.
	 *
	 * @param bool $enabled Whether to anonymize IP addresses.
	 * @return void
	 */
	public static function set_ip_anonymization( bool $enabled ): void {
		update_option( self::OPTION_ANONYMIZE_IP, $enabled, true );
		self::$cache['anonymize_ip'] = $enabled;
	}

	/**
	 * Get data retention period in days.
	 *
	 * Analytics data older than this many days is automatically deleted
	 * by the daily cleanup task.
	 *
	 * @return int Number of days (0 = keep indefinitely)
	 */
	public static function get_data_retention_days(): int {
		$days = get_option( self::OPTION_DATA_RETENTION, 90 );
		return max( 0, (int) $days );
	}

	/**
	 * Set data retention period.
	 *
	 * @param int $days Number of days to retain data (0 = indefinitely).
	 * @return void
	 */
	public static function set_data_retention_days( int $days ): void {
		update_option( self::OPTION_DATA_RETENTION, max( 0, $days ), true );
	}

	/**
	 * Anonymize an IP address for privacy compliance.
	 *
	 * @param string $ip The IP address to anonymize.
	 * @return string Anonymized IP address, or empty string if invalid.
	 */
	public static function anonymize_ip( string $ip ): string {
		if ( $ip === '' ) {
			return '';
		}

		// Handle IPv4 addresses
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0'; // Zero out last octet
			return implode( '.', $parts );
		}

		// Handle IPv6 addresses
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$expanded = inet_ntop( inet_pton( $ip ) );
			if ( $expanded === false ) {
				return '';
			}
			$parts = explode( ':', $expanded );
			// Zero out last 5 groups (80 bits) for privacy
			for ( $i = 3; $i < 8; $i++ ) {
				$parts[ $i ] = '0';
			}
			return implode( ':', $parts );
		}

		return '';
	}

	/*
	 * =========================================================================
	 * TRACKING SETTINGS
	 * =========================================================================
	 */

	/**
	 * Get the current tracking mode.
	 *
	 * - 'all': Track all pages except those matching exclusion patterns
	 * - 'whitelist': Only track pages matching inclusion patterns
	 *
	 * @return string Either 'all' or 'whitelist'
	 */
	public static function get_tracking_mode(): string {
		if ( isset( self::$cache['tracking_mode'] ) ) {
			return self::$cache['tracking_mode'];
		}

		$mode = get_option( self::OPTION_TRACKING_MODE, 'all' );
		$mode = in_array( $mode, array( 'all', 'whitelist' ), true ) ? $mode : 'all';

		self::$cache['tracking_mode'] = $mode;
		return $mode;
	}

	/**
	 * Set the tracking mode.
	 *
	 * @param string $mode Either 'all' or 'whitelist'.
	 * @return void
	 */
	public static function set_tracking_mode( string $mode ): void {
		$mode = in_array( $mode, array( 'all', 'whitelist' ), true ) ? $mode : 'all';
		update_option( self::OPTION_TRACKING_MODE, $mode, true );
		self::$cache['tracking_mode'] = $mode;
	}

	/**
	 * Get post types excluded from tracking.
	 *
	 * @return string[] Array of post type slugs
	 */
	public static function get_excluded_post_types(): array {
		if ( isset( self::$cache['excluded_post_types'] ) ) {
			return self::$cache['excluded_post_types'];
		}

		$types = get_option( self::OPTION_EXCLUDED_POST_TYPES, array() );
		$types = is_array( $types ) ? array_map( 'sanitize_key', $types ) : array();

		self::$cache['excluded_post_types'] = $types;
		return $types;
	}

	/**
	 * Set post types to exclude from tracking.
	 *
	 * @param string[] $types Array of post type slugs.
	 * @return void
	 */
	public static function set_excluded_post_types( array $types ): void {
		$types = array_map( 'sanitize_key', $types );
		$types = array_filter( $types );
		update_option( self::OPTION_EXCLUDED_POST_TYPES, $types, true );
		self::$cache['excluded_post_types'] = $types;
	}

	/**
	 * Get URL patterns to exclude from tracking.
	 *
	 * @return string[] Array of URL patterns (supports * wildcard)
	 */
	public static function get_excluded_urls(): array {
		if ( isset( self::$cache['excluded_urls'] ) ) {
			return self::$cache['excluded_urls'];
		}

		$urls                          = get_option( self::OPTION_EXCLUDED_URLS, '' );
		$urls                          = is_string( $urls ) ? self::parse_url_patterns( $urls ) : array();
		self::$cache['excluded_urls'] = $urls;

		return $urls;
	}

	/**
	 * Set URL patterns to exclude from tracking.
	 *
	 * @param string $urls Newline-separated URL patterns.
	 * @return void
	 */
	public static function set_excluded_urls( string $urls ): void {
		$urls = sanitize_textarea_field( $urls );
		update_option( self::OPTION_EXCLUDED_URLS, $urls, true );
		unset( self::$cache['excluded_urls'] );
	}

	/**
	 * Get raw excluded URLs string for textarea display.
	 *
	 * @return string Newline-separated URL patterns
	 */
	public static function get_excluded_urls_raw(): string {
		return get_option( self::OPTION_EXCLUDED_URLS, '' );
	}

	/**
	 * Get URL patterns for whitelist mode.
	 *
	 * @return string[] Array of URL patterns to track
	 */
	public static function get_included_urls(): array {
		if ( isset( self::$cache['included_urls'] ) ) {
			return self::$cache['included_urls'];
		}

		$urls                          = get_option( self::OPTION_INCLUDED_URLS, '' );
		$urls                          = is_string( $urls ) ? self::parse_url_patterns( $urls ) : array();
		self::$cache['included_urls'] = $urls;

		return $urls;
	}

	/**
	 * Set URL patterns for whitelist mode.
	 *
	 * @param string $urls Newline-separated URL patterns.
	 * @return void
	 */
	public static function set_included_urls( string $urls ): void {
		$urls = sanitize_textarea_field( $urls );
		update_option( self::OPTION_INCLUDED_URLS, $urls, true );
		unset( self::$cache['included_urls'] );
	}

	/**
	 * Get raw included URLs string for textarea display.
	 *
	 * @return string Newline-separated URL patterns
	 */
	public static function get_included_urls_raw(): string {
		return get_option( self::OPTION_INCLUDED_URLS, '' );
	}

	/**
	 * Parse URL patterns from newline-separated text.
	 *
	 * @param string $text Newline-separated URL patterns.
	 * @return string[] Array of trimmed, non-empty patterns
	 */
	private static function parse_url_patterns( string $text ): array {
		$lines    = explode( "\n", $text );
		$patterns = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Skip empty lines and limit pattern length for security
			if ( $line !== '' && strlen( $line ) <= 500 ) {
				$patterns[] = $line;
			}
		}

		return $patterns;
	}

	/**
	 * Check if a URL should be tracked based on current settings.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL should be tracked.
	 */
	public static function should_track_url( string $url ): bool {
		$mode = self::get_tracking_mode();

		if ( $mode === 'whitelist' ) {
			// Whitelist mode: only track matching URLs
			$included = self::get_included_urls();
			if ( empty( $included ) ) {
				return false; // No patterns = track nothing
			}
			return self::url_matches_patterns( $url, $included );
		}

		// Default mode: track all except excluded
		$excluded = self::get_excluded_urls();
		if ( empty( $excluded ) ) {
			return true; // No exclusions = track everything
		}
		return ! self::url_matches_patterns( $url, $excluded );
	}

	/**
	 * Check if URL matches any of the given patterns.
	 *
	 * @param string   $url      The URL to check.
	 * @param string[] $patterns Array of patterns (supports * wildcard).
	 * @return bool True if URL matches any pattern.
	 */
	private static function url_matches_patterns( string $url, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::url_matches_pattern( $url, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if URL matches a single pattern.
	 *
	 * Supports * wildcard for matching any characters.
	 *
	 * @param string $url     The URL to check.
	 * @param string $pattern The pattern to match against.
	 * @return bool True if URL matches the pattern.
	 */
	private static function url_matches_pattern( string $url, string $pattern ): bool {
		// Exact match
		if ( $url === $pattern ) {
			return true;
		}

		// Wildcard matching
		if ( strpos( $pattern, '*' ) !== false ) {
			// Convert pattern to regex (escape special chars, replace * with .*)
			$regex = preg_quote( $pattern, '/' );
			$regex = str_replace( '\\*', '.*', $regex );
			$regex = '/^' . $regex . '$/i';
			return (bool) preg_match( $regex, $url );
		}

		// Partial/substring match
		return strpos( $url, $pattern ) !== false;
	}

	/*
	 * =========================================================================
	 * CONVERSION TRACKING
	 * =========================================================================
	 */

	/**
	 * Get all configured conversion buttons.
	 *
	 * Each button configuration includes:
	 * - id: The HTML element ID to track
	 * - name: A friendly display name
	 * - enabled: Whether tracking is active
	 *
	 * @return array<int, array{id: string, name: string, enabled: bool}>
	 */
	public static function get_conversion_buttons(): array {
		if ( isset( self::$cache['conversion_buttons'] ) ) {
			return self::$cache['conversion_buttons'];
		}

		$buttons = get_option( self::OPTION_CONVERSION_BUTTONS, array() );
		if ( ! is_array( $buttons ) ) {
			$buttons = array();
		}

		$max_id_length   = 100;
		$max_name_length = 100;

		// Validate and sanitize each button configuration
		$valid_buttons = array();
		foreach ( $buttons as $button ) {
			if ( is_array( $button ) && ! empty( $button['id'] ) ) {
				$raw_id = substr( (string) $button['id'], 0, $max_id_length );
				$id     = sanitize_html_class( $raw_id );

				if ( $id !== '' ) {
					$raw_name = substr( (string) ( $button['name'] ?? $id ), 0, $max_name_length );
					$name     = sanitize_text_field( $raw_name );

					$valid_buttons[] = array(
						'id'      => $id,
						'name'    => $name !== '' ? $name : $id,
						'enabled' => ! empty( $button['enabled'] ),
					);
				}
			}
		}

		self::$cache['conversion_buttons'] = $valid_buttons;
		return $valid_buttons;
	}

	/**
	 * Get IDs of enabled conversion buttons only.
	 *
	 * @return string[] Array of button element IDs
	 */
	public static function get_enabled_conversion_button_ids(): array {
		$buttons = self::get_conversion_buttons();
		$ids     = array();

		foreach ( $buttons as $button ) {
			if ( $button['enabled'] ) {
				$ids[] = $button['id'];
			}
		}

		return $ids;
	}

	/**
	 * Save conversion button configurations.
	 *
	 * Limited to 50 buttons maximum to prevent abuse.
	 *
	 * @param array<int, array{id: string, name: string, enabled: bool}> $buttons Button configurations.
	 * @return void
	 */
	public static function set_conversion_buttons( array $buttons ): void {
		$valid_buttons   = array();
		$max_buttons     = 50;
		$max_id_length   = 100;
		$max_name_length = 100;

		foreach ( $buttons as $button ) {
			// Stop if we've reached the limit
			if ( count( $valid_buttons ) >= $max_buttons ) {
				break;
			}

			if ( is_array( $button ) && ! empty( $button['id'] ) ) {
				$raw_id = substr( (string) $button['id'], 0, $max_id_length );
				$id     = sanitize_html_class( $raw_id );

				if ( $id !== '' ) {
					$raw_name = substr( (string) ( $button['name'] ?? $id ), 0, $max_name_length );
					$name     = sanitize_text_field( $raw_name );

					$valid_buttons[] = array(
						'id'      => $id,
						'name'    => $name !== '' ? $name : $id,
						'enabled' => ! empty( $button['enabled'] ),
					);
				}
			}
		}

		update_option( self::OPTION_CONVERSION_BUTTONS, $valid_buttons, true );
		unset( self::$cache['conversion_buttons'] );
	}

	/**
	 * Get the friendly name for a conversion button by its ID.
	 *
	 * @param string $button_id The button element ID.
	 * @return string The friendly name, or the ID if not found.
	 */
	public static function get_conversion_button_name( string $button_id ): string {
		$buttons = self::get_conversion_buttons();

		foreach ( $buttons as $button ) {
			if ( $button['id'] === $button_id ) {
				return $button['name'];
			}
		}

		return $button_id;
	}

	/*
	 * =========================================================================
	 * DATA MANAGEMENT
	 * =========================================================================
	 */

	/**
	 * Delete a single analytics event by ID.
	 *
	 * @param int $id The event ID to delete.
	 * @return bool True if deleted, false otherwise.
	 */
	public static function delete_event( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return $deleted !== false && $deleted > 0;
	}

	/**
	 * Delete multiple analytics events by ID.
	 *
	 * @param int[] $ids Array of event IDs to delete.
	 * @return int Number of events deleted.
	 */
	public static function delete_events( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::table_name();

		// Sanitize and filter IDs
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids, static fn( $id ) => $id > 0 );

		if ( empty( $ids ) ) {
			return 0;
		}

		// Build safe placeholder list
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Delete analytics events within a date range.
	 *
	 * @param string $date_from Start date (YYYY-MM-DD format).
	 * @param string $date_to   End date (YYYY-MM-DD format).
	 * @return int Number of events deleted.
	 */
	public static function delete_events_by_date( string $date_from, string $date_to ): int {
		global $wpdb;
		$table = self::table_name();

		$where  = array();
		$params = array();

		// Validate and add date conditions
		if ( $date_from !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		if ( empty( $where ) ) {
			return 0;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Delete all analytics data.
	 *
	 * Uses batched deletion to prevent table locks on large datasets.
	 *
	 * @return int Total number of events deleted.
	 */
	public static function delete_all_data(): int {
		global $wpdb;
		$table         = self::table_name();
		$total_deleted = 0;
		$batch_size    = 1000;
		$max_batches   = 1000; // Safety limit (up to 1 million rows)

		for ( $i = 0; $i < $max_batches; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} LIMIT %d", $batch_size )
			);

			if ( ! is_int( $deleted ) || $deleted === 0 ) {
				break;
			}

			$total_deleted += $deleted;

			// If we deleted less than batch size, we're done
			if ( $deleted < $batch_size ) {
				break;
			}

			// Small delay between batches to reduce DB load
			usleep( 10000 ); // 10ms
		}

		return $total_deleted;
	}

	/**
	 * Get total count of all analytics events.
	 *
	 * @return int Total number of events in the database.
	 */
	public static function get_total_count(): int {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return (int) $count;
	}

	/**
	 * Clean up old analytics data based on retention settings.
	 *
	 * Called by the daily scheduled cleanup task.
	 * Uses batched deletion to prevent table locks.
	 *
	 * @return int Number of events deleted.
	 */
	public static function cleanup_old_data(): int {
		$days = self::get_data_retention_days();

		// If retention is 0, keep data indefinitely
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table         = self::table_name();
		$cutoff        = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$total_deleted = 0;
		$batch_size    = 1000;
		$max_batches   = 100; // Safety limit

		for ( $i = 0; $i < $max_batches; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
					$cutoff,
					$batch_size
				)
			);

			if ( ! is_int( $deleted ) || $deleted === 0 ) {
				break;
			}

			$total_deleted += $deleted;

			// If we deleted less than batch size, we're done
			if ( $deleted < $batch_size ) {
				break;
			}

			// Small delay between batches to reduce DB load
			usleep( 10000 ); // 10ms
		}

		return $total_deleted;
	}

	/*
	 * =========================================================================
	 * PRIVATE HELPERS
	 * =========================================================================
	 */

	/**
	 * Generate and save a public token if one doesn't exist.
	 *
	 * @return void
	 */
	private static function maybe_seed_public_token(): void {
		$token = self::get_public_token();

		if ( $token !== '' ) {
			return;
		}

		// Generate a 24-character alphanumeric token
		$token = wp_generate_password( 24, false, false );
		update_option( self::OPTION_TOKEN, $token, true );
	}

	/**
	 * Set default option values on first activation.
	 *
	 * @return void
	 */
	private static function maybe_set_default_options(): void {
		// Enable IP anonymization by default for GDPR compliance
		if ( get_option( self::OPTION_ANONYMIZE_IP ) === false ) {
			update_option( self::OPTION_ANONYMIZE_IP, true, true );
		}

		// Default data retention: 90 days
		if ( get_option( self::OPTION_DATA_RETENTION ) === false ) {
			update_option( self::OPTION_DATA_RETENTION, 90, true );
		}
	}

	/**
	 * Create the analytics events table if it doesn't exist.
	 *
	 * @return void
	 */
	private static function maybe_create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * Table schema:
		 * - id: Unique event identifier
		 * - event_type: Type of event (pageview, link_click, conversion)
		 * - pageview_id: Links clicks/conversions to their parent pageview
		 * - session_id: Groups events by browser session
		 * - page_url: The page where the event occurred
		 * - referrer_url: The referring page (for pageviews)
		 * - link_url: The clicked link URL or conversion button info
		 * - ip_address: Visitor IP (anonymized by default)
		 * - user_agent: Browser user agent string
		 * - time_on_page: Seconds spent on page (for pageviews)
		 * - scroll_depth: Maximum scroll percentage (for pageviews)
		 * - created_at: Event timestamp in UTC
		 */
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(20) NOT NULL,
			pageview_id BIGINT UNSIGNED NULL,
			session_id VARCHAR(64) NULL,
			page_url TEXT NOT NULL,
			referrer_url TEXT NULL,
			link_url TEXT NULL,
			ip_address VARCHAR(45) NULL,
			user_agent TEXT NULL,
			time_on_page INT UNSIGNED NULL,
			scroll_depth TINYINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event_type (event_type),
			KEY pageview_id (pageview_id),
			KEY session_id (session_id),
			KEY ip_address (ip_address)
		) {$charset_collate};";

		dbDelta( $sql );

		// Ensure index exists for existing installations
		self::maybe_add_ip_index();

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Add IP address index if it doesn't exist.
	 *
	 * This handles upgrades from older versions that didn't have this index.
	 *
	 * @return void
	 */
	private static function maybe_add_ip_index(): void {
		global $wpdb;
		$table = self::table_name();

		// Check if index already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
				WHERE table_schema = %s AND table_name = %s AND index_name = %s',
				DB_NAME,
				$table,
				'ip_address'
			)
		);

		if ( (int) $index_exists === 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX ip_address (ip_address)" );
		}
	}
}
