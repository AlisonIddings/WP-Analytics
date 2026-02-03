<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class SA_DB {
	public const TABLE_SLUG = 'sa_events';
	private const OPTION_VERSION = 'sa_db_version';
	private const OPTION_TOKEN = 'sa_public_token';
	private const DB_VERSION = '1.0.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	public static function activate(): void {
		self::maybe_create_table();
		self::maybe_seed_public_token();
	}

	public static function uninstall(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DROP TABLE IF EXISTS {$table}");

		delete_option(self::OPTION_VERSION);
		delete_option(self::OPTION_TOKEN);
	}

	public static function get_public_token(): string {
		$token = get_option(self::OPTION_TOKEN, '');
		return is_string($token) ? $token : '';
	}

	private static function maybe_seed_public_token(): void {
		$token = self::get_public_token();
		if ($token !== '') {
			return;
		}
		$token = wp_generate_password(24, false, false);
		update_option(self::OPTION_TOKEN, $token, true);
	}

	private static function maybe_create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

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
			KEY session_id (session_id)
		) {$charset_collate};";

		dbDelta($sql);
		update_option(self::OPTION_VERSION, self::DB_VERSION, true);
	}
}

