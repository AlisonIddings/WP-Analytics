<?php
/**
 * Plugin Name: WP Analytics
 * Plugin URI: https://alisoniddings.com/wp-analytics
 * Description: Privacy-focused analytics for WordPress. Track pageviews, engagement, link clicks, and conversions without external services. GDPR-friendly with IP anonymization.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Alison Iddings
 * Author URI: https://alisoniddings.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-analytics
 * Domain Path: /languages
 *
 * @package WP_Analytics
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * =============================================================================
 * PLUGIN CONSTANTS
 * =============================================================================
 * These constants are used throughout the plugin for versioning, file paths,
 * and URLs. They should not be modified during runtime.
 */

define( 'WPA_PLUGIN_VERSION', '1.2.0' );
define( 'WPA_PLUGIN_FILE', __FILE__ );
define( 'WPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * =============================================================================
 * LOAD CORE CLASSES
 * =============================================================================
 * Load the essential classes needed for the plugin to function.
 * Admin classes are loaded conditionally to save memory on frontend.
 */

require_once WPA_PLUGIN_DIR . 'includes/class-wpa-database.php';
require_once WPA_PLUGIN_DIR . 'includes/class-wpa-rest-api.php';

// Only load admin classes when in the WordPress admin area
if ( is_admin() ) {
	require_once WPA_PLUGIN_DIR . 'includes/class-wpa-admin.php';
}

/*
 * =============================================================================
 * CAPABILITY FILTER
 * =============================================================================
 * Defines the capability required to view and export analytics data.
 * By default, users with 'edit_pages' (Editors+) can access analytics.
 *
 * To restrict to administrators only, add this to your theme's functions.php:
 * add_filter( 'wpa_view_analytics_capability', function() { return 'manage_options'; } );
 */

/**
 * Get the capability required to view analytics.
 *
 * @return string The WordPress capability slug.
 */
function wpa_view_analytics_capability(): string {
	$capability = 'edit_pages';

	/**
	 * Filter the capability required to view analytics.
	 *
	 * @param string $capability The capability slug. Default 'edit_pages'.
	 */
	$capability = apply_filters( 'wpa_view_analytics_capability', $capability );

	// Ensure we always return a valid capability string
	return is_string( $capability ) && $capability !== '' ? $capability : 'edit_pages';
}

/*
 * =============================================================================
 * PLUGIN LIFECYCLE HOOKS
 * =============================================================================
 * These functions handle plugin activation, deactivation, and uninstallation.
 */

register_activation_hook( WPA_PLUGIN_FILE, 'wpa_activate' );
register_deactivation_hook( WPA_PLUGIN_FILE, 'wpa_deactivate' );
register_uninstall_hook( WPA_PLUGIN_FILE, 'wpa_uninstall' );

/**
 * Plugin activation callback.
 *
 * Creates the database table and schedules the daily cleanup event
 * for removing old analytics data based on retention settings.
 *
 * @return void
 */
function wpa_activate(): void {
	WPA_Database::activate();

	// Schedule daily cleanup if not already scheduled
	if ( ! wp_next_scheduled( 'wpa_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'wpa_daily_cleanup' );
	}
}

/**
 * Plugin deactivation callback.
 *
 * Clears the scheduled cleanup event but preserves all data.
 * Data is only removed on uninstall.
 *
 * @return void
 */
function wpa_deactivate(): void {
	wp_clear_scheduled_hook( 'wpa_daily_cleanup' );
}

/**
 * Plugin uninstall callback.
 *
 * Removes all plugin data including the database table and options.
 * This is only called when the plugin is deleted, not deactivated.
 *
 * @return void
 */
function wpa_uninstall(): void {
	require_once WPA_PLUGIN_DIR . 'includes/class-wpa-database.php';
	WPA_Database::uninstall();
}

// Hook the daily cleanup and aggregation functions to our scheduled event
add_action( 'wpa_daily_cleanup', 'wpa_run_daily_tasks' );

/**
 * Run daily maintenance tasks.
 *
 * Aggregates yesterday's data into efficient stats table,
 * then cleans up old events and stats based on retention settings.
 *
 * @return void
 */
function wpa_run_daily_tasks(): void {
	// First, aggregate yesterday's events into daily stats
	WPA_Database::aggregate_daily_stats();

	// Then cleanup old event data
	WPA_Database::cleanup_old_data();

	// Finally cleanup old aggregated stats
	WPA_Database::cleanup_old_stats();
}

/*
 * =============================================================================
 * PLUGIN INITIALIZATION
 * =============================================================================
 * Initialize plugin components after WordPress has fully loaded.
 */

add_action(
	'plugins_loaded',
	static function (): void {
		// Load translations for internationalization
		load_plugin_textdomain(
			'wp-analytics',
			false,
			dirname( plugin_basename( WPA_PLUGIN_FILE ) ) . '/languages'
		);

		// Initialize the REST API endpoints (needed for tracking)
		WPA_REST_API::init();

		// Initialize admin interface only when in admin area
		if ( is_admin() ) {
			WPA_Admin::init();
		}
	}
);

/*
 * =============================================================================
 * PRIVACY POLICY INTEGRATION
 * =============================================================================
 * Adds suggested privacy policy text to help site owners comply with GDPR
 * and other privacy regulations.
 */

add_action(
	'admin_init',
	static function (): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$retention_days = WPA_Database::get_data_retention_days();

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__( 'Analytics Data Collection', 'wp-analytics' ),
			__( 'This website uses WP Analytics to collect anonymous usage data to improve user experience. The following data may be collected:', 'wp-analytics' ),
			__(
				'- Page URLs visited<br>
				- Referrer URLs<br>
				- Links clicked<br>
				- Button clicks (for conversion tracking)<br>
				- Time spent on pages<br>
				- Scroll depth<br>
				- IP address (anonymized by default)<br>
				- Browser user agent',
				'wp-analytics'
			),
			sprintf(
				/* translators: %d: number of days data is retained */
				__( 'This data is stored locally on this website and automatically deleted after %d days. No data is shared with third parties.', 'wp-analytics' ),
				$retention_days
			)
		);

		wp_add_privacy_policy_content(
			'WP Analytics',
			wp_kses_post( $content )
		);
	}
);

/*
 * =============================================================================
 * FRONTEND TRACKING SCRIPT
 * =============================================================================
 * Enqueues the tracking JavaScript on the frontend for collecting analytics data.
 * Respects post type exclusions and URL filtering settings.
 */

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		// Don't load tracking script in admin area
		if ( is_admin() ) {
			return;
		}

		// Check if current post type is excluded from tracking
		$excluded_post_types = WPA_Database::get_excluded_post_types();
		if ( ! empty( $excluded_post_types ) ) {
			$current_post_type = get_post_type();
			if ( $current_post_type && in_array( $current_post_type, $excluded_post_types, true ) ) {
				return; // Don't track this post type
			}
		}

		$handle = 'wpa-tracker';

		// Register script with defer strategy for non-blocking page load (WP 6.3+)
		$script_args = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);

		wp_register_script(
			$handle,
			WPA_PLUGIN_URL . 'assets/js/wpa-tracker.js',
			array(),
			WPA_PLUGIN_VERSION,
			$script_args
		);

		// Build configuration object for the tracking script
		$tracking_mode = WPA_Database::get_tracking_mode();
		$settings      = array(
			'restUrl'      => esc_url_raw( rest_url( 'wp-analytics/v1' ) ),
			'token'        => WPA_Database::get_public_token(),
			'trackingMode' => $tracking_mode,
		);

		// Add URL patterns based on tracking mode
		if ( $tracking_mode === 'whitelist' ) {
			$settings['includedUrls'] = WPA_Database::get_included_urls();
		} else {
			$settings['excludedUrls'] = WPA_Database::get_excluded_urls();
		}

		// Add conversion button IDs if any are configured
		$conversion_buttons = WPA_Database::get_enabled_conversion_button_ids();
		if ( ! empty( $conversion_buttons ) ) {
			$settings['conversionButtons'] = $conversion_buttons;
		}

		// Inject settings as inline script before the tracker loads
		wp_add_inline_script(
			$handle,
			'window.wpaTrackerSettings = ' . wp_json_encode( $settings ) . ';',
			'before'
		);

		wp_enqueue_script( $handle );
	}
);
