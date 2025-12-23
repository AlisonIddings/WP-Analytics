<?php
/**
 * Plugin Name: Server Analytics (Pageviews + Engagement)
 * Description: Collects server-side analytics for pageviews, referrers, link clicks, time on page, and scroll depth. Includes a dashboard report with filtering, sorting, and CSV/PDF exports.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: (Generated)
 * License: GPLv2 or later
 * Text Domain: server-analytics
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('SA_PLUGIN_VERSION', '1.0.0');
define('SA_PLUGIN_FILE', __FILE__);
define('SA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SA_PLUGIN_DIR . 'includes/class-sa-db.php';
require_once SA_PLUGIN_DIR . 'includes/class-sa-rest.php';
require_once SA_PLUGIN_DIR . 'includes/class-sa-admin.php';

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

register_activation_hook(SA_PLUGIN_FILE, array('SA_DB', 'activate'));
register_uninstall_hook(SA_PLUGIN_FILE, 'sa_uninstall');

function sa_uninstall(): void {
	require_once SA_PLUGIN_DIR . 'includes/class-sa-db.php';
	SA_DB::uninstall();
}

add_action(
	'plugins_loaded',
	static function (): void {
		SA_REST::init();
		SA_Admin::init();
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if (is_admin()) {
			return;
		}

		$handle = 'sa-tracker';
		wp_register_script(
			$handle,
			SA_PLUGIN_URL . 'assets/js/sa-tracker.js',
			array(),
			SA_PLUGIN_VERSION,
			true
		);

		$settings = array(
			'restUrl' => esc_url_raw(rest_url('server-analytics/v1')),
			'token'   => SA_DB::get_public_token(),
		);

		wp_add_inline_script(
			$handle,
			'window.saTrackerSettings = ' . wp_json_encode($settings) . ';',
			'before'
		);

		wp_enqueue_script($handle);
	}
);

