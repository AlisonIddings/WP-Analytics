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

	/** @var string Database table name suffix for events */
	public const TABLE_SLUG = 'wpa_events';

	/** @var string Database table name suffix for daily aggregates */
	public const TABLE_STATS_SLUG = 'wpa_daily_stats';

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

	/** @var string Option key for conversion URL configurations */
	private const OPTION_CONVERSION_URLS = 'wpa_conversion_urls';

	/** @var string Option key for excluded IP addresses */
	private const OPTION_EXCLUDED_IPS = 'wpa_excluded_ips';

	/** @var string Current database schema version */
	private const DB_VERSION = '1.2.1';

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
	 * Get the daily stats table name with WordPress prefix.
	 *
	 * @return string Full table name (e.g., 'wp_wpa_daily_stats')
	 */
	public static function stats_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_STATS_SLUG;
	}

	/**
	 * Activate the plugin - create database tables and set defaults.
	 *
	 * Called during plugin activation via register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::maybe_create_table();
		self::maybe_create_stats_table();
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

		// Drop the daily stats table
		$stats_table = self::stats_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$stats_table}" );

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
		delete_option( self::OPTION_CONVERSION_URLS );
		delete_option( self::OPTION_EXCLUDED_IPS );
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
	 * Get all configured conversion buttons (IDs and classes).
	 *
	 * Each button configuration includes:
	 * - selector: The HTML element ID or class to track (without # or .)
	 * - type: 'id' or 'class'
	 * - name: A friendly display name
	 * - enabled: Whether tracking is active
	 *
	 * @return array<int, array{selector: string, type: string, name: string, enabled: bool}>
	 */
	public static function get_conversion_buttons(): array {
		if ( isset( self::$cache['conversion_buttons'] ) ) {
			return self::$cache['conversion_buttons'];
		}

		$buttons = get_option( self::OPTION_CONVERSION_BUTTONS, array() );
		if ( ! is_array( $buttons ) ) {
			$buttons = array();
		}

		$max_selector_length = 100;
		$max_name_length     = 100;

		// Validate and sanitize each button configuration
		$valid_buttons = array();
		foreach ( $buttons as $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			// Support legacy 'id' field or new 'selector' field
			$raw_selector = $button['selector'] ?? $button['id'] ?? '';
			if ( $raw_selector === '' ) {
				continue;
			}

			$raw_selector = substr( (string) $raw_selector, 0, $max_selector_length );
			$selector     = sanitize_html_class( $raw_selector );

			if ( $selector === '' ) {
				continue;
			}

			$type     = isset( $button['type'] ) && $button['type'] === 'class' ? 'class' : 'id';
			$raw_name = substr( (string) ( $button['name'] ?? $selector ), 0, $max_name_length );
			$name     = sanitize_text_field( $raw_name );

			$valid_buttons[] = array(
				'selector' => $selector,
				'type'     => $type,
				'name'     => $name !== '' ? $name : $selector,
				'enabled'  => ! empty( $button['enabled'] ),
			);
		}

		self::$cache['conversion_buttons'] = $valid_buttons;
		return $valid_buttons;
	}

	/**
	 * Get enabled conversion buttons grouped by type.
	 *
	 * @return array{ids: string[], classes: string[]}
	 */
	public static function get_enabled_conversion_selectors(): array {
		$buttons = self::get_conversion_buttons();
		$ids     = array();
		$classes = array();

		foreach ( $buttons as $button ) {
			if ( $button['enabled'] ) {
				if ( $button['type'] === 'class' ) {
					$classes[] = $button['selector'];
				} else {
					$ids[] = $button['selector'];
				}
			}
		}

		return array(
			'ids'     => $ids,
			'classes' => $classes,
		);
	}

	/**
	 * Get IDs of enabled conversion buttons only (legacy support).
	 *
	 * @return string[] Array of button element IDs
	 */
	public static function get_enabled_conversion_button_ids(): array {
		$selectors = self::get_enabled_conversion_selectors();
		return $selectors['ids'];
	}

	/**
	 * Save conversion button configurations.
	 *
	 * Limited to 50 buttons maximum to prevent abuse.
	 *
	 * @param array<int, array{selector: string, type: string, name: string, enabled: bool}> $buttons Button configurations.
	 * @return void
	 */
	public static function set_conversion_buttons( array $buttons ): void {
		$valid_buttons       = array();
		$max_buttons         = 50;
		$max_selector_length = 100;
		$max_name_length     = 100;

		foreach ( $buttons as $button ) {
			// Stop if we've reached the limit
			if ( count( $valid_buttons ) >= $max_buttons ) {
				break;
			}

			if ( ! is_array( $button ) ) {
				continue;
			}

			// Support legacy 'id' field or new 'selector' field
			$raw_selector = $button['selector'] ?? $button['id'] ?? '';
			if ( $raw_selector === '' ) {
				continue;
			}

			$raw_selector = substr( (string) $raw_selector, 0, $max_selector_length );
			$selector     = sanitize_html_class( $raw_selector );

			if ( $selector === '' ) {
				continue;
			}

			$type     = isset( $button['type'] ) && $button['type'] === 'class' ? 'class' : 'id';
			$raw_name = substr( (string) ( $button['name'] ?? $selector ), 0, $max_name_length );
			$name     = sanitize_text_field( $raw_name );

			$valid_buttons[] = array(
				'selector' => $selector,
				'type'     => $type,
				'name'     => $name !== '' ? $name : $selector,
				'enabled'  => ! empty( $button['enabled'] ),
			);
		}

		update_option( self::OPTION_CONVERSION_BUTTONS, $valid_buttons, true );
		unset( self::$cache['conversion_buttons'] );
	}

	/**
	 * Get the friendly name for a conversion button by its selector.
	 *
	 * @param string $selector The button selector (ID or class).
	 * @return string The friendly name, or the selector if not found.
	 */
	public static function get_conversion_button_name( string $selector ): string {
		$buttons = self::get_conversion_buttons();

		foreach ( $buttons as $button ) {
			if ( $button['selector'] === $selector ) {
				return $button['name'];
			}
		}

		return $selector;
	}

	/**
	 * Get all configured conversion URLs (thank you pages).
	 *
	 * @return array<int, array{url: string, name: string, enabled: bool}>
	 */
	public static function get_conversion_urls(): array {
		if ( isset( self::$cache['conversion_urls'] ) ) {
			return self::$cache['conversion_urls'];
		}

		$urls = get_option( self::OPTION_CONVERSION_URLS, array() );
		if ( ! is_array( $urls ) ) {
			$urls = array();
		}

		$max_url_length  = 500;
		$max_name_length = 100;

		$valid_urls = array();
		foreach ( $urls as $url_config ) {
			if ( ! is_array( $url_config ) || empty( $url_config['url'] ) ) {
				continue;
			}

			$raw_url = substr( (string) $url_config['url'], 0, $max_url_length );
			$url     = sanitize_text_field( $raw_url );

			if ( $url === '' ) {
				continue;
			}

			$raw_name = substr( (string) ( $url_config['name'] ?? $url ), 0, $max_name_length );
			$name     = sanitize_text_field( $raw_name );

			$valid_urls[] = array(
				'url'     => $url,
				'name'    => $name !== '' ? $name : $url,
				'enabled' => ! empty( $url_config['enabled'] ),
			);
		}

		self::$cache['conversion_urls'] = $valid_urls;
		return $valid_urls;
	}

	/**
	 * Get enabled conversion URLs only.
	 *
	 * @return string[] Array of URL patterns
	 */
	public static function get_enabled_conversion_urls(): array {
		$urls    = self::get_conversion_urls();
		$enabled = array();

		foreach ( $urls as $url_config ) {
			if ( $url_config['enabled'] ) {
				$enabled[] = $url_config['url'];
			}
		}

		return $enabled;
	}

	/**
	 * Save conversion URL configurations.
	 *
	 * @param array<int, array{url: string, name: string, enabled: bool}> $urls URL configurations.
	 * @return void
	 */
	public static function set_conversion_urls( array $urls ): void {
		$valid_urls      = array();
		$max_urls        = 50;
		$max_url_length  = 500;
		$max_name_length = 100;

		foreach ( $urls as $url_config ) {
			if ( count( $valid_urls ) >= $max_urls ) {
				break;
			}

			if ( ! is_array( $url_config ) || empty( $url_config['url'] ) ) {
				continue;
			}

			$raw_url = substr( (string) $url_config['url'], 0, $max_url_length );
			$url     = sanitize_text_field( $raw_url );

			if ( $url === '' ) {
				continue;
			}

			$raw_name = substr( (string) ( $url_config['name'] ?? $url ), 0, $max_name_length );
			$name     = sanitize_text_field( $raw_name );

			$valid_urls[] = array(
				'url'     => $url,
				'name'    => $name !== '' ? $name : $url,
				'enabled' => ! empty( $url_config['enabled'] ),
			);
		}

		update_option( self::OPTION_CONVERSION_URLS, $valid_urls, true );
		unset( self::$cache['conversion_urls'] );
	}

	/**
	 * Get the friendly name for a conversion URL.
	 *
	 * @param string $url The URL pattern.
	 * @return string The friendly name, or the URL if not found.
	 */
	public static function get_conversion_url_name( string $url ): string {
		$urls = self::get_conversion_urls();

		foreach ( $urls as $url_config ) {
			if ( $url_config['url'] === $url ) {
				return $url_config['name'];
			}
		}

		return $url;
	}

	/**
	 * Check if a URL matches any conversion URL pattern.
	 *
	 * @param string $url The URL to check.
	 * @return string|false The matched pattern name or false.
	 */
	public static function check_conversion_url( string $url ): string|false {
		$conversion_urls = self::get_conversion_urls();

		foreach ( $conversion_urls as $config ) {
			if ( ! $config['enabled'] ) {
				continue;
			}

			$pattern = $config['url'];

			// Exact match
			if ( $url === $pattern ) {
				return $config['name'];
			}

			// Check if URL contains the pattern
			if ( strpos( $url, $pattern ) !== false ) {
				return $config['name'];
			}

			// Wildcard matching
			if ( strpos( $pattern, '*' ) !== false ) {
				$regex = preg_quote( $pattern, '/' );
				$regex = str_replace( '\\*', '.*', $regex );
				if ( preg_match( '/^' . $regex . '$/i', $url ) ) {
					return $config['name'];
				}
			}
		}

		return false;
	}

	/**
	 * Get conversion statistics for reporting.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_conversion_stats( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					link_url as conversion_info,
					COUNT(*) as conversion_count,
					COUNT(DISTINCT session_id) as unique_sessions,
					MIN(created_at) as first_conversion,
					MAX(created_at) as last_conversion
				FROM {$table}
				WHERE event_type = 'conversion'
					AND DATE(created_at) BETWEEN %s AND %s
				GROUP BY link_url
				ORDER BY conversion_count DESC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get recent conversions for display.
	 *
	 * @param int $limit Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_conversions( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					id,
					link_url as conversion_info,
					page_url,
					session_id,
					ip_address,
					created_at
				FROM {$table}
				WHERE event_type = 'conversion'
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/*
	 * =========================================================================
	 * IP EXCLUSION
	 * =========================================================================
	 */

	/**
	 * Get IP addresses excluded from tracking.
	 *
	 * @return string[] Array of IP addresses or CIDR ranges
	 */
	public static function get_excluded_ips(): array {
		if ( isset( self::$cache['excluded_ips'] ) ) {
			return self::$cache['excluded_ips'];
		}

		$ips = get_option( self::OPTION_EXCLUDED_IPS, '' );
		$ips = is_string( $ips ) ? self::parse_ip_list( $ips ) : array();

		self::$cache['excluded_ips'] = $ips;
		return $ips;
	}

	/**
	 * Set IP addresses to exclude from tracking.
	 *
	 * @param string $ips Newline-separated IP addresses or CIDR ranges.
	 * @return void
	 */
	public static function set_excluded_ips( string $ips ): void {
		$ips = sanitize_textarea_field( $ips );
		update_option( self::OPTION_EXCLUDED_IPS, $ips, true );
		unset( self::$cache['excluded_ips'] );
	}

	/**
	 * Get raw excluded IPs string for textarea display.
	 *
	 * @return string Newline-separated IP addresses
	 */
	public static function get_excluded_ips_raw(): string {
		$value = get_option( self::OPTION_EXCLUDED_IPS, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Parse IP list from newline-separated text.
	 *
	 * @param string $text Newline-separated IPs.
	 * @return string[] Array of trimmed, non-empty IPs
	 */
	private static function parse_ip_list( string $text ): array {
		$lines = explode( "\n", $text );
		$ips   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Skip empty lines and comments
			if ( $line === '' || strpos( $line, '#' ) === 0 ) {
				continue;
			}
			// Basic validation - limit length and remove dangerous chars
			if ( strlen( $line ) <= 45 ) {
				// Only allow valid IP characters (digits, dots, colons for IPv6, slash for CIDR)
				$clean = preg_replace( '/[^0-9a-fA-F:.\\/]/', '', $line );
				if ( $clean !== '' ) {
					$ips[] = $clean;
				}
			}
		}

		return $ips;
	}

	/**
	 * Check if an IP address should be excluded from tracking.
	 *
	 * Supports exact IP matching and CIDR notation for ranges.
	 *
	 * @param string $ip The IP address to check.
	 * @return bool True if the IP should be excluded.
	 */
	public static function is_ip_excluded( string $ip ): bool {
		if ( $ip === '' ) {
			return false;
		}

		$excluded = self::get_excluded_ips();
		if ( empty( $excluded ) ) {
			return false;
		}

		foreach ( $excluded as $pattern ) {
			// Check for CIDR notation
			if ( strpos( $pattern, '/' ) !== false ) {
				if ( self::ip_in_cidr( $ip, $pattern ) ) {
					return true;
				}
			} elseif ( $ip === $pattern ) {
				// Exact match
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if an IP address is within a CIDR range.
	 *
	 * @param string $ip   The IP address to check.
	 * @param string $cidr The CIDR range (e.g., 192.168.1.0/24).
	 * @return bool True if IP is within the range.
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}

		$range_ip = $parts[0];
		$netmask  = (int) $parts[1];

		// Handle IPv4
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) &&
			 filter_var( $range_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {

			if ( $netmask < 0 || $netmask > 32 ) {
				return false;
			}

			$ip_long    = ip2long( $ip );
			$range_long = ip2long( $range_ip );

			if ( $ip_long === false || $range_long === false ) {
				return false;
			}

			$mask = -1 << ( 32 - $netmask );
			return ( $ip_long & $mask ) === ( $range_long & $mask );
		}

		// Handle IPv6
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) &&
			 filter_var( $range_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {

			if ( $netmask < 0 || $netmask > 128 ) {
				return false;
			}

			$ip_bin    = inet_pton( $ip );
			$range_bin = inet_pton( $range_ip );

			if ( $ip_bin === false || $range_bin === false ) {
				return false;
			}

			// Build netmask
			$mask = str_repeat( "\xff", (int) floor( $netmask / 8 ) );
			if ( $netmask % 8 !== 0 ) {
				$mask .= chr( 256 - ( 1 << ( 8 - ( $netmask % 8 ) ) ) );
			}
			$mask = str_pad( $mask, 16, "\x00" );

			return ( $ip_bin & $mask ) === ( $range_bin & $mask );
		}

		return false;
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

		// Validate and add date conditions with strict validation
		if ( self::is_valid_date( $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( self::is_valid_date( $date_to ) ) {
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
	 * Validate a date string is a valid YYYY-MM-DD format and represents a real date.
	 *
	 * @param string $date The date string to validate.
	 * @return bool True if valid date, false otherwise.
	 */
	private static function is_valid_date( string $date ): bool {
		// Must match YYYY-MM-DD format
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}

		// Verify it's a real date (e.g., not 2024-13-45)
		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];

		// Reasonable year range (1970-2100)
		if ( $year < 1970 || $year > 2100 ) {
			return false;
		}

		return checkdate( $month, $day, $year );
	}

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

	/**
	 * Create the daily stats aggregation table.
	 *
	 * This table stores pre-computed daily statistics per page URL,
	 * enabling efficient long-term reporting without querying the
	 * full events table.
	 *
	 * @return void
	 */
	private static function maybe_create_stats_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::stats_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * Daily stats table schema:
		 * - id: Unique record identifier
		 * - stat_date: The date for this aggregate (YYYY-MM-DD)
		 * - page_path: Relative URL path (without domain)
		 * - pageviews: Total pageview count
		 * - unique_sessions: Count of distinct sessions
		 * - avg_time_on_page: Average seconds on page
		 * - avg_scroll_depth: Average scroll percentage
		 * - link_clicks: Total link clicks
		 * - conversions: Total conversion events
		 */
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date DATE NOT NULL,
			page_path VARCHAR(500) NOT NULL,
			pageviews INT UNSIGNED NOT NULL DEFAULT 0,
			unique_sessions INT UNSIGNED NOT NULL DEFAULT 0,
			avg_time_on_page INT UNSIGNED NOT NULL DEFAULT 0,
			avg_scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
			link_clicks INT UNSIGNED NOT NULL DEFAULT 0,
			conversions INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY date_path (stat_date, page_path(191)),
			KEY stat_date (stat_date),
			KEY page_path (page_path(191))
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/*
	 * =========================================================================
	 * ANALYTICS AGGREGATION
	 * =========================================================================
	 */

	/**
	 * Aggregate yesterday's events into daily stats.
	 *
	 * Called by the daily cleanup cron job. Summarizes detailed events
	 * into efficient per-page daily statistics.
	 *
	 * @return int Number of pages aggregated
	 */
	public static function aggregate_daily_stats(): int {
		global $wpdb;

		$events_table = self::table_name();
		$stats_table  = self::stats_table_name();

		// Get yesterday's date
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Check if already aggregated
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$stats_table} WHERE stat_date = %s",
				$yesterday
			)
		);

		if ( (int) $exists > 0 ) {
			return 0; // Already aggregated
		}

		// Aggregate pageview stats
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageview_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					page_url,
					COUNT(*) as pageviews,
					COUNT(DISTINCT session_id) as unique_sessions,
					AVG(NULLIF(time_on_page, 0)) as avg_time,
					AVG(NULLIF(scroll_depth, 0)) as avg_scroll
				FROM {$events_table}
				WHERE event_type = 'pageview'
					AND DATE(created_at) = %s
				GROUP BY page_url",
				$yesterday
			),
			ARRAY_A
		);

		if ( empty( $pageview_stats ) ) {
			return 0;
		}

		// Get click and conversion counts
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$click_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					page_url,
					SUM(CASE WHEN event_type = 'link_click' THEN 1 ELSE 0 END) as clicks,
					SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) as conversions
				FROM {$events_table}
				WHERE event_type IN ('link_click', 'conversion')
					AND DATE(created_at) = %s
				GROUP BY page_url",
				$yesterday
			),
			ARRAY_A
		);

		// Index click stats by URL for easy lookup
		$click_index = array();
		foreach ( $click_stats as $stat ) {
			$click_index[ $stat['page_url'] ] = $stat;
		}

		// Insert aggregated stats
		$count = 0;
		foreach ( $pageview_stats as $stat ) {
			$page_path = self::extract_path( $stat['page_url'] );
			$clicks    = $click_index[ $stat['page_url'] ]['clicks'] ?? 0;
			$convs     = $click_index[ $stat['page_url'] ]['conversions'] ?? 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$stats_table,
				array(
					'stat_date'        => $yesterday,
					'page_path'        => $page_path,
					'pageviews'        => (int) $stat['pageviews'],
					'unique_sessions'  => (int) $stat['unique_sessions'],
					'avg_time_on_page' => (int) round( (float) $stat['avg_time'] ),
					'avg_scroll_depth' => (int) round( (float) $stat['avg_scroll'] ),
					'link_clicks'      => (int) $clicks,
					'conversions'      => (int) $convs,
				),
				array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Extract the path portion from a full URL.
	 *
	 * @param string $url Full URL.
	 * @return string Path portion (e.g., /blog/my-post/)
	 */
	public static function extract_path( string $url ): string {
		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '/';

		// Ensure path starts with /
		if ( strpos( $path, '/' ) !== 0 ) {
			$path = '/' . $path;
		}

		// Limit length
		if ( strlen( $path ) > 500 ) {
			$path = substr( $path, 0, 500 );
		}

		return $path;
	}

	/**
	 * Get top pages by pageviews for a date range.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @param int    $limit      Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_top_pages( string $start_date, string $end_date, int $limit = 20 ): array {
		global $wpdb;
		$stats_table = self::stats_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					page_path,
					SUM(pageviews) as total_pageviews,
					SUM(unique_sessions) as total_sessions,
					ROUND(AVG(avg_time_on_page)) as avg_time,
					ROUND(AVG(avg_scroll_depth)) as avg_scroll,
					SUM(link_clicks) as total_clicks,
					SUM(conversions) as total_conversions
				FROM {$stats_table}
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY page_path
				ORDER BY total_pageviews DESC
				LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get pageview trends over time (monthly or yearly).
	 *
	 * @param string $period   'month' or 'year'.
	 * @param int    $count    Number of periods to return.
	 * @return array<int, array<string, mixed>>
	 * @deprecated Use get_daily_trends_for_range() instead.
	 */
	public static function get_pageview_trends( string $period = 'month', int $count = 12 ): array {
		global $wpdb;
		$stats_table = self::stats_table_name();

		// Strictly validate period type (only 'month' or 'year')
		$period = in_array( $period, array( 'month', 'year' ), true ) ? $period : 'month';

		// Strictly bound count to prevent abuse (1-100 periods max)
		$count = max( 1, min( 100, absint( $count ) ) );

		if ( $period === 'year' ) {
			$date_format = '%Y';
			$interval_type = 'YEAR';
		} else {
			$date_format = '%Y-%m';
			$interval_type = 'MONTH';
		}

		// Build query with INTERVAL using only sanitized/validated values
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE_FORMAT(stat_date, %s) as period,
					SUM(pageviews) as pageviews,
					SUM(unique_sessions) as sessions,
					SUM(link_clicks) as clicks,
					SUM(conversions) as conversions
				FROM {$stats_table}
				WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL %d {$interval_type})
				GROUP BY period
				ORDER BY period ASC",
				$date_format,
				$count
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get daily trends for a specific date range from aggregated stats.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_daily_trends_for_range( string $start_date, string $end_date ): array {
		global $wpdb;
		$stats_table = self::stats_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					stat_date as period,
					SUM(pageviews) as pageviews,
					SUM(unique_sessions) as sessions,
					SUM(link_clicks) as clicks,
					SUM(conversions) as conversions
				FROM {$stats_table}
				WHERE stat_date BETWEEN %s AND %s
				GROUP BY stat_date
				ORDER BY stat_date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get summary statistics for a date range.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array<string, int>
	 */
	public static function get_summary_stats( string $start_date, string $end_date ): array {
		global $wpdb;
		$stats_table = self::stats_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COALESCE(SUM(pageviews), 0) as total_pageviews,
					COALESCE(SUM(unique_sessions), 0) as total_sessions,
					COALESCE(SUM(link_clicks), 0) as total_clicks,
					COALESCE(SUM(conversions), 0) as total_conversions,
					COALESCE(ROUND(AVG(avg_time_on_page)), 0) as avg_time,
					COALESCE(ROUND(AVG(avg_scroll_depth)), 0) as avg_scroll
				FROM {$stats_table}
				WHERE stat_date BETWEEN %s AND %s",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		return is_array( $result ) ? array_map( 'intval', $result ) : array(
			'total_pageviews'   => 0,
			'total_sessions'    => 0,
			'total_clicks'      => 0,
			'total_conversions' => 0,
			'avg_time'          => 0,
			'avg_scroll'        => 0,
		);
	}

	/**
	 * Check if the daily stats table has any data.
	 *
	 * @return bool True if stats table has data.
	 */
	public static function has_aggregated_stats(): bool {
		global $wpdb;
		$stats_table = self::stats_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$stats_table} LIMIT 1" );

		return (int) $count > 0;
	}

	/**
	 * Get real-time summary stats directly from the events table.
	 *
	 * This is a fallback for when aggregated stats aren't available yet.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array<string, int>
	 */
	public static function get_realtime_summary_stats( string $start_date, string $end_date ): array {
		global $wpdb;
		$events_table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COALESCE(SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END), 0) as total_pageviews,
					COALESCE(COUNT(DISTINCT session_id), 0) as total_sessions,
					COALESCE(SUM(CASE WHEN event_type = 'link_click' THEN 1 ELSE 0 END), 0) as total_clicks,
					COALESCE(SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END), 0) as total_conversions,
					COALESCE(ROUND(AVG(CASE WHEN event_type = 'pageview' AND time_on_page > 0 THEN time_on_page ELSE NULL END)), 0) as avg_time,
					COALESCE(ROUND(AVG(CASE WHEN event_type = 'pageview' AND scroll_depth > 0 THEN scroll_depth ELSE NULL END)), 0) as avg_scroll
				FROM {$events_table}
				WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		return is_array( $result ) ? array_map( 'intval', $result ) : array(
			'total_pageviews'   => 0,
			'total_sessions'    => 0,
			'total_clicks'      => 0,
			'total_conversions' => 0,
			'avg_time'          => 0,
			'avg_scroll'        => 0,
		);
	}

	/**
	 * Get real-time top pages directly from the events table.
	 *
	 * This is a fallback for when aggregated stats aren't available yet.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @param int    $limit      Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_realtime_top_pages( string $start_date, string $end_date, int $limit = 20 ): array {
		global $wpdb;
		$events_table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					page_url,
					COUNT(*) as total_pageviews,
					COUNT(DISTINCT session_id) as total_sessions,
					ROUND(AVG(NULLIF(time_on_page, 0))) as avg_time,
					ROUND(AVG(NULLIF(scroll_depth, 0))) as avg_scroll,
					0 as total_conversions
				FROM {$events_table}
				WHERE event_type = 'pageview'
					AND DATE(created_at) BETWEEN %s AND %s
				GROUP BY page_url
				ORDER BY total_pageviews DESC
				LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		// Convert page_url to page_path for consistency
		foreach ( $results as &$row ) {
			$row['page_path'] = self::extract_path( $row['page_url'] );
			unset( $row['page_url'] );
		}

		return $results;
	}

	/**
	 * Get real-time daily pageview trends from the events table.
	 *
	 * This is a fallback for when aggregated stats aren't available yet.
	 *
	 * @param int $days Number of days to show.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_realtime_daily_trends( int $days = 30 ): array {
		global $wpdb;
		$events_table = self::table_name();

		$days = max( 1, min( 365, $days ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(created_at) as period,
					SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) as pageviews,
					COUNT(DISTINCT session_id) as sessions,
					SUM(CASE WHEN event_type = 'link_click' THEN 1 ELSE 0 END) as clicks,
					SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) as conversions
				FROM {$events_table}
				WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY DATE(created_at)
				ORDER BY period ASC",
				$days
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/*
	 * =========================================================================
	 * PAGE ANALYTICS
	 * =========================================================================
	 */

	/**
	 * Get analytics for a specific page URL.
	 *
	 * @param string $page_path The page path to analyze.
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date End date (YYYY-MM-DD).
	 * @return array<string, mixed>
	 */
	public static function get_page_analytics( string $page_path, string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::table_name();

		// Build URL pattern for matching (path can be part of full URL)
		$path_pattern = '%' . $wpdb->esc_like( $page_path ) . '%';

		// Get summary stats for this page
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					COUNT(CASE WHEN event_type = 'pageview' THEN 1 END) as pageviews,
					COUNT(DISTINCT session_id) as unique_sessions,
					ROUND(AVG(CASE WHEN event_type = 'pageview' AND time_on_page > 0 THEN time_on_page END)) as avg_time,
					ROUND(AVG(CASE WHEN event_type = 'pageview' AND scroll_depth > 0 THEN scroll_depth END)) as avg_scroll,
					COUNT(CASE WHEN event_type = 'link_click' THEN 1 END) as link_clicks,
					COUNT(CASE WHEN event_type = 'conversion' THEN 1 END) as conversions
				FROM {$table}
				WHERE page_url LIKE %s
					AND DATE(created_at) BETWEEN %s AND %s",
				$path_pattern,
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Get daily trends for a specific page.
	 *
	 * @param string $page_path The page path to analyze.
	 * @param int $days Number of days.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_page_daily_trends( string $page_path, int $days = 30 ): array {
		global $wpdb;
		$table = self::table_name();

		$path_pattern = '%' . $wpdb->esc_like( $page_path ) . '%';
		$days = max( 1, min( 365, $days ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(created_at) as period,
					COUNT(CASE WHEN event_type = 'pageview' THEN 1 END) as pageviews,
					COUNT(DISTINCT session_id) as sessions
				FROM {$table}
				WHERE page_url LIKE %s
					AND created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY DATE(created_at)
				ORDER BY period ASC",
				$path_pattern,
				$days
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get sessions that visited a specific page.
	 *
	 * @param string $page_path The page path.
	 * @param int $limit Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_page_sessions( string $page_path, int $limit = 50 ): array {
		global $wpdb;
		$table = self::table_name();

		$path_pattern = '%' . $wpdb->esc_like( $page_path ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					session_id,
					MIN(created_at) as first_visit,
					MAX(created_at) as last_activity,
					MAX(time_on_page) as time_on_page,
					MAX(scroll_depth) as scroll_depth,
					ip_address
				FROM {$table}
				WHERE page_url LIKE %s
					AND session_id IS NOT NULL
					AND session_id != ''
				GROUP BY session_id, ip_address
				ORDER BY first_visit DESC
				LIMIT %d",
				$path_pattern,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get links clicked from a specific page.
	 *
	 * @param string $page_path The page path.
	 * @param int $limit Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_page_outbound_links( string $page_path, int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();

		$path_pattern = '%' . $wpdb->esc_like( $page_path ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					link_url,
					COUNT(*) as click_count
				FROM {$table}
				WHERE page_url LIKE %s
					AND event_type = 'link_click'
					AND link_url IS NOT NULL
					AND link_url != ''
				GROUP BY link_url
				ORDER BY click_count DESC
				LIMIT %d",
				$path_pattern,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/*
	 * =========================================================================
	 * SESSION/USER JOURNEY
	 * =========================================================================
	 */

	/**
	 * Get all events for a specific session (user journey).
	 *
	 * @param string $session_id The session ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_session_journey( string $session_id ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					id,
					event_type,
					page_url,
					referrer_url,
					link_url,
					time_on_page,
					scroll_depth,
					created_at
				FROM {$table}
				WHERE session_id = %s
				ORDER BY created_at ASC, id ASC",
				$session_id
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get session summary info.
	 *
	 * @param string $session_id The session ID.
	 * @return array<string, mixed>
	 */
	public static function get_session_summary( string $session_id ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					MIN(created_at) as session_start,
					MAX(created_at) as session_end,
					COUNT(CASE WHEN event_type = 'pageview' THEN 1 END) as pages_viewed,
					COUNT(CASE WHEN event_type = 'link_click' THEN 1 END) as links_clicked,
					COUNT(CASE WHEN event_type = 'conversion' THEN 1 END) as conversions,
					SUM(CASE WHEN event_type = 'pageview' THEN time_on_page ELSE 0 END) as total_time,
					ROUND(AVG(CASE WHEN event_type = 'pageview' AND scroll_depth > 0 THEN scroll_depth END)) as avg_scroll,
					MAX(ip_address) as ip_address
				FROM {$table}
				WHERE session_id = %s",
				$session_id
			),
			ARRAY_A
		);

		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Get recent sessions with summary info.
	 *
	 * @param int $limit Maximum results.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_sessions( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					session_id,
					MIN(created_at) as session_start,
					MAX(created_at) as session_end,
					COUNT(CASE WHEN event_type = 'pageview' THEN 1 END) as pages_viewed,
					COUNT(CASE WHEN event_type = 'conversion' THEN 1 END) as conversions,
					MAX(ip_address) as ip_address,
					MIN(page_url) as entry_page
				FROM {$table}
				WHERE session_id IS NOT NULL
					AND session_id != ''
				GROUP BY session_id
				ORDER BY session_start DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Cleanup old aggregated stats based on retention setting.
	 *
	 * Keeps aggregated data 3x longer than detailed events for
	 * long-term trend analysis.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function cleanup_old_stats(): int {
		$retention_days = self::get_data_retention_days();

		// Keep aggregated stats 3x longer than events (max 3 years)
		if ( $retention_days <= 0 ) {
			return 0;
		}

		$stats_retention = min( $retention_days * 3, 1095 );

		global $wpdb;
		$stats_table = self::stats_table_name();
		$cutoff      = gmdate( 'Y-m-d', strtotime( "-{$stats_retention} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$stats_table} WHERE stat_date < %s",
				$cutoff
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
