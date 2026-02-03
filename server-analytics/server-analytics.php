<?php
/**
 * Plugin Name: Server Analytics
 * Plugin URI: https://alisoniddings.com/server-analytics
 * Description: Collects server-side analytics for pageviews, referrers, link clicks, time on page, and scroll depth. Includes a dashboard report with filtering, sorting, and CSV/PDF exports. GDPR-friendly with IP anonymization option.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Alison Iddings
 * Author URI: https://alisoniddings.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: server-analytics
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SA_PLUGIN_VERSION', '1.1.0');
define('SA_PLUGIN_FILE', __FILE__);
define('SA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SA_PLUGIN_DIR . 'includes/class-sa-db.php';
require_once SA_PLUGIN_DIR . 'includes/class-sa-rest.php';

// Defer admin class loading to reduce memory on frontend
if (is_admin()) {
	require_once SA_PLUGIN_DIR . 'includes/class-sa-admin.php';
}

/**
 * Capability required to view/export analytics.
 *
 * Editors typically have edit_pages. You can override via:
 * add_filter('sa_view_analytics_capability', fn() => 'manage_options');
 */
function sa_view_analytics_capability(): string {
	$cap = 'edit_pages';
	/** @var string $cap */
	$cap = apply_filters('sa_view_analytics_capability', $cap);
	return is_string($cap) && $cap !== '' ? $cap : 'edit_pages';
}

register_activation_hook(SA_PLUGIN_FILE, 'sa_activate');
register_deactivation_hook(SA_PLUGIN_FILE, 'sa_deactivate');
register_uninstall_hook(SA_PLUGIN_FILE, 'sa_uninstall');

function sa_activate(): void {
	SA_DB::activate();
	// Schedule daily cleanup of old data
	if (!wp_next_scheduled('sa_daily_cleanup')) {
		wp_schedule_event(time(), 'daily', 'sa_daily_cleanup');
	}
}

function sa_deactivate(): void {
	wp_clear_scheduled_hook('sa_daily_cleanup');
}

function sa_uninstall(): void {
	require_once SA_PLUGIN_DIR . 'includes/class-sa-db.php';
	SA_DB::uninstall();
}

add_action('sa_daily_cleanup', array('SA_DB', 'cleanup_old_data'));

add_action(
	'plugins_loaded',
	static function (): void {
		// Load plugin text domain for translations
		load_plugin_textdomain(
			'server-analytics',
			false,
			dirname(plugin_basename(SA_PLUGIN_FILE)) . '/languages'
		);

		SA_REST::init();

		// Only initialize admin on admin pages to save resources
		if (is_admin()) {
			SA_Admin::init();
		}
	}
);

/**
 * Add privacy policy content for GDPR compliance.
 */
add_action(
	'admin_init',
	static function (): void {
		if (!function_exists('wp_add_privacy_policy_content')) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__('Analytics Data Collection', 'server-analytics'),
			__('This website uses Server Analytics to collect anonymous usage data to improve user experience. The following data may be collected:', 'server-analytics'),
			__('- Page URLs visited<br>- Referrer URLs<br>- Links clicked<br>- Button clicks (for conversion tracking)<br>- Time spent on pages<br>- Scroll depth<br>- IP address (anonymized by default)<br>- Browser user agent', 'server-analytics'),
			sprintf(
				/* translators: %d: number of days */
				__('This data is stored locally on this website and automatically deleted after %d days. No data is shared with third parties.', 'server-analytics'),
				SA_DB::get_data_retention_days()
			)
		);

		wp_add_privacy_policy_content(
			'Server Analytics',
			wp_kses_post($content)
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if (is_admin()) {
			return;
		}

		// Check if current post type is excluded
		$excluded_post_types = SA_DB::get_excluded_post_types();
		if (!empty($excluded_post_types)) {
			$current_post_type = get_post_type();
			if ($current_post_type && in_array($current_post_type, $excluded_post_types, true)) {
				return; // Don't track this post type
			}
		}

		$handle = 'sa-tracker';

		// Register script with defer strategy for non-blocking load (WP 6.3+)
		$script_args = array(
			'in_footer' => true,
			'strategy'  => 'defer', // Non-blocking script loading
		);

		wp_register_script(
			$handle,
			SA_PLUGIN_URL . 'assets/js/sa-tracker.js',
			array(),
			SA_PLUGIN_VERSION,
			$script_args
		);

		// Build settings including URL filtering rules
		$tracking_mode = SA_DB::get_tracking_mode();
		$settings = array(
			'restUrl'      => esc_url_raw(rest_url('server-analytics/v1')),
			'token'        => SA_DB::get_public_token(),
			'trackingMode' => $tracking_mode,
		);

		// Add URL patterns based on tracking mode
		if ($tracking_mode === 'whitelist') {
			$settings['includedUrls'] = SA_DB::get_included_urls();
		} else {
			$settings['excludedUrls'] = SA_DB::get_excluded_urls();
		}

		// Add conversion button IDs
		$conversion_buttons = SA_DB::get_enabled_conversion_button_ids();
		if (!empty($conversion_buttons)) {
			$settings['conversionButtons'] = $conversion_buttons;
		}

		wp_add_inline_script(
			$handle,
			'window.saTrackerSettings = ' . wp_json_encode($settings) . ';',
			'before'
		);

		wp_enqueue_script($handle);
	}
);

