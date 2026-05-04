<?php
/**
 * WP Analytics Uninstall Handler
 *
 * This file is called when the plugin is deleted from WordPress.
 * It removes all plugin data including the database table and options.
 *
 * @package WP_Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct access - only allow WordPress uninstall process
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the database class to access uninstall method
require_once __DIR__ . '/includes/class-wpa-database.php';

// Remove all plugin data
WPA_Database::uninstall();
