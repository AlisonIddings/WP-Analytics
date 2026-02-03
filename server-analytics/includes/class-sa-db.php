<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class SA_DB {
	public const TABLE_SLUG = 'sa_events';
	private const OPTION_VERSION = 'sa_db_version';
	private const OPTION_TOKEN = 'sa_public_token';
	private const OPTION_ANONYMIZE_IP = 'sa_anonymize_ip';
	private const OPTION_DATA_RETENTION = 'sa_data_retention_days';
	private const DB_VERSION = '1.0.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	public static function activate(): void {
		self::maybe_create_table();
		self::maybe_seed_public_token();
		self::maybe_set_default_options();
	}

	public static function uninstall(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DROP TABLE IF EXISTS {$table}");

		delete_option(self::OPTION_VERSION);
		delete_option(self::OPTION_TOKEN);
		delete_option(self::OPTION_ANONYMIZE_IP);
		delete_option(self::OPTION_DATA_RETENTION);
	}

	public static function get_public_token(): string {
		$token = get_option(self::OPTION_TOKEN, '');
		return is_string($token) ? $token : '';
	}

	/**
	 * Check if IP anonymization is enabled.
	 */
	public static function is_ip_anonymization_enabled(): bool {
		return (bool) get_option(self::OPTION_ANONYMIZE_IP, true);
	}

	/**
	 * Set IP anonymization setting.
	 */
	public static function set_ip_anonymization(bool $enabled): void {
		update_option(self::OPTION_ANONYMIZE_IP, $enabled, true);
	}

	/**
	 * Get data retention period in days (0 = keep forever).
	 */
	public static function get_data_retention_days(): int {
		$days = get_option(self::OPTION_DATA_RETENTION, 90);
		return max(0, (int) $days);
	}

	/**
	 * Set data retention period in days.
	 */
	public static function set_data_retention_days(int $days): void {
		update_option(self::OPTION_DATA_RETENTION, max(0, $days), true);
	}

	/**
	 * Delete old analytics data based on retention setting.
	 */
	public static function cleanup_old_data(): int {
		$days = self::get_data_retention_days();
		if ($days <= 0) {
			return 0;
		}

		global $wpdb;
		$table = self::table_name();
		$cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
		);

		return is_int($deleted) ? $deleted : 0;
	}

	/**
	 * Anonymize an IP address (remove last octet for IPv4, last 80 bits for IPv6).
	 */
	public static function anonymize_ip(string $ip): string {
		if ($ip === '') {
			return '';
		}

		// IPv4
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$parts = explode('.', $ip);
			$parts[3] = '0';
			return implode('.', $parts);
		}

		// IPv6
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			// Expand IPv6 to full form, then mask last 80 bits (5 groups)
			$expanded = inet_ntop(inet_pton($ip));
			if ($expanded === false) {
				return '';
			}
			$parts = explode(':', $expanded);
			// Zero out last 5 groups for privacy
			for ($i = 3; $i < 8; $i++) {
				$parts[$i] = '0';
			}
			return implode(':', $parts);
		}

		return '';
	}

	private static function maybe_seed_public_token(): void {
		$token = self::get_public_token();
		if ($token !== '') {
			return;
		}
		$token = wp_generate_password(24, false, false);
		update_option(self::OPTION_TOKEN, $token, true);
	}

	private static function maybe_set_default_options(): void {
		// Set IP anonymization enabled by default for GDPR compliance
		if (get_option(self::OPTION_ANONYMIZE_IP) === false) {
			update_option(self::OPTION_ANONYMIZE_IP, true, true);
		}
		// Default data retention: 90 days
		if (get_option(self::OPTION_DATA_RETENTION) === false) {
			update_option(self::OPTION_DATA_RETENTION, 90, true);
		}
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

