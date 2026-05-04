<?php
/**
 * Conversions management page for WP Analytics.
 *
 * Displays conversion statistics and allows configuration
 * of conversion tracking via buttons and URLs.
 *
 * @package WP_Analytics
 * @since 1.2.2
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_Conversions
 *
 * Renders the Conversions page with:
 * - Conversion statistics
 * - Recent conversions list
 * - Conversion configuration (buttons and URLs)
 *
 * @since 1.2.2
 */
final class WPA_Conversions {

	/**
	 * Render the conversions page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view conversions.', 'wp-analytics' ) );
		}

		// Handle form submissions
		self::handle_save_settings();

		// Get date range (default last 30 days)
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// Get conversion data
		$conversion_stats   = WPA_Database::get_conversion_stats( $start_date, $end_date );
		$recent_conversions = WPA_Database::get_recent_conversions( 25 );

		// Get current configuration
		$conversion_buttons = WPA_Database::get_conversion_buttons();
		$conversion_urls    = WPA_Database::get_conversion_urls();

		// Calculate totals
		$total_conversions   = 0;
		$total_unique        = 0;
		foreach ( $conversion_stats as $stat ) {
			$total_conversions += (int) $stat['conversion_count'];
			$total_unique      += (int) $stat['unique_sessions'];
		}

		?>
		<div class="wrap wpa-conversions-wrap">
			<h1><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></h1>

			<?php self::render_admin_notices(); ?>

			<!-- Summary Stats -->
			<div class="wpa-stats-cards" style="margin-bottom: 30px;">
				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-flag"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( $total_conversions ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Total Conversions (30 days)', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-groups"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( $total_unique ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Unique Sessions', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-admin-settings"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( count( $conversion_buttons ) + count( $conversion_urls ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Configured Goals', 'wp-analytics' ); ?></span>
					</div>
				</div>
			</div>

			<div class="wpa-conversions-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
				<!-- Conversion Stats by Goal -->
				<div class="wpa-conversion-stats" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
					<h2 style="margin-top: 0;"><?php echo esc_html__( 'Conversions by Goal (Last 30 Days)', 'wp-analytics' ); ?></h2>
					<?php if ( empty( $conversion_stats ) ) : ?>
						<p class="wpa-no-data"><?php echo esc_html__( 'No conversions recorded yet.', 'wp-analytics' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Goal', 'wp-analytics' ); ?></th>
									<th style="width: 80px;"><?php echo esc_html__( 'Type', 'wp-analytics' ); ?></th>
									<th style="width: 100px;"><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></th>
									<th style="width: 100px;"><?php echo esc_html__( 'Unique', 'wp-analytics' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $conversion_stats as $stat ) : ?>
									<?php
									$info  = self::parse_conversion_info( $stat['conversion_info'] );
									$type  = $info['type'];
									$value = $info['value'];
									$name  = $info['name'];
									?>
									<tr>
										<td>
											<strong><?php echo esc_html( $name ); ?></strong>
											<?php if ( $value !== $name ) : ?>
												<br><small style="color: #666;"><?php echo esc_html( $type === 'url' ? $value : ( $type === 'class' ? '.' : '#' ) . $value ); ?></small>
											<?php endif; ?>
										</td>
										<td>
											<?php
											$type_labels = array(
												'id'    => __( 'Button ID', 'wp-analytics' ),
												'class' => __( 'Button Class', 'wp-analytics' ),
												'url'   => __( 'Page URL', 'wp-analytics' ),
											);
											echo esc_html( $type_labels[ $type ] ?? $type );
											?>
										</td>
										<td><strong><?php echo esc_html( number_format_i18n( (int) $stat['conversion_count'] ) ); ?></strong></td>
										<td><?php echo esc_html( number_format_i18n( (int) $stat['unique_sessions'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Recent Conversions -->
				<div class="wpa-recent-conversions" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
					<h2 style="margin-top: 0;"><?php echo esc_html__( 'Recent Conversions', 'wp-analytics' ); ?></h2>
					<?php if ( empty( $recent_conversions ) ) : ?>
						<p class="wpa-no-data"><?php echo esc_html__( 'No conversions recorded yet.', 'wp-analytics' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Goal', 'wp-analytics' ); ?></th>
									<th><?php echo esc_html__( 'Page', 'wp-analytics' ); ?></th>
									<th style="width: 120px;"><?php echo esc_html__( 'Time', 'wp-analytics' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_conversions as $conv ) : ?>
									<?php
									$info      = self::parse_conversion_info( $conv['conversion_info'] );
									$page_path = WPA_Database::extract_path( $conv['page_url'] ?? '' );
									?>
									<tr>
										<td>
											<span class="wpa-conversion-badge" style="background: #4caf50; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
												<?php echo esc_html( $info['name'] ); ?>
											</span>
										</td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $page_path ) ) ); ?>">
												<?php echo esc_html( self::truncate_path( $page_path, 30 ) ); ?>
											</a>
										</td>
										<td>
											<span title="<?php echo esc_attr( $conv['created_at'] ?? '' ); ?>">
												<?php echo esc_html( self::time_ago( $conv['created_at'] ?? '' ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- Conversion Configuration -->
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<div class="wpa-conversion-config" style="margin-top: 30px; background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
				<h2 style="margin-top: 0;"><?php echo esc_html__( 'Conversion Goals Configuration', 'wp-analytics' ); ?></h2>

				<form method="post" action="">
					<?php wp_nonce_field( 'wpa_save_conversions', 'wpa_conversions_nonce' ); ?>
					<input type="hidden" name="wpa_action" value="save_conversions" />

					<!-- Button Conversions -->
					<h3><?php echo esc_html__( 'Button Click Conversions', 'wp-analytics' ); ?></h3>
					<p class="description">
						<?php echo esc_html__( 'Track clicks on buttons by their HTML ID or class attribute.', 'wp-analytics' ); ?>
					</p>

					<div id="wpa-button-conversions" style="margin: 15px 0;">
						<?php if ( empty( $conversion_buttons ) ) : ?>
							<div class="wpa-conversion-row" data-index="0" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
								<select name="conversion_buttons[0][type]" style="width: 120px;">
									<option value="id"><?php echo esc_html__( 'ID', 'wp-analytics' ); ?></option>
									<option value="class"><?php echo esc_html__( 'Class', 'wp-analytics' ); ?></option>
								</select>
								<input type="text" name="conversion_buttons[0][selector]" placeholder="<?php echo esc_attr__( 'e.g., buy-now-btn', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
								<input type="text" name="conversion_buttons[0][name]" placeholder="<?php echo esc_attr__( 'Friendly Name (e.g., Purchase)', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
								<label><input type="checkbox" name="conversion_buttons[0][enabled]" value="1" checked /> <?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?></label>
								<button type="button" class="button wpa-remove-row"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
							</div>
						<?php else : ?>
							<?php foreach ( $conversion_buttons as $index => $button ) : ?>
								<div class="wpa-conversion-row" data-index="<?php echo esc_attr( $index ); ?>" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
									<select name="conversion_buttons[<?php echo esc_attr( $index ); ?>][type]" style="width: 120px;">
										<option value="id" <?php selected( $button['type'] ?? 'id', 'id' ); ?>><?php echo esc_html__( 'ID', 'wp-analytics' ); ?></option>
										<option value="class" <?php selected( $button['type'] ?? 'id', 'class' ); ?>><?php echo esc_html__( 'Class', 'wp-analytics' ); ?></option>
									</select>
									<input type="text" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][selector]" value="<?php echo esc_attr( $button['selector'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Selector', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
									<input type="text" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $button['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Friendly Name', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
									<label><input type="checkbox" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $button['enabled'] ?? false ); ?> /> <?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?></label>
									<button type="button" class="button wpa-remove-row"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button" id="wpa-add-button"><?php echo esc_html__( 'Add Button', 'wp-analytics' ); ?></button>

					<hr style="margin: 30px 0;" />

					<!-- URL Conversions (Thank You Pages) -->
					<h3><?php echo esc_html__( 'Thank You Page Conversions', 'wp-analytics' ); ?></h3>
					<p class="description">
						<?php echo esc_html__( 'Track conversions when users visit specific URLs (e.g., thank you pages, confirmation pages).', 'wp-analytics' ); ?><br />
						<?php echo esc_html__( 'Use * as a wildcard (e.g., /thank-you/* matches any thank you page).', 'wp-analytics' ); ?>
					</p>

					<div id="wpa-url-conversions" style="margin: 15px 0;">
						<?php if ( empty( $conversion_urls ) ) : ?>
							<div class="wpa-conversion-row" data-index="0" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
								<input type="text" name="conversion_urls[0][url]" placeholder="<?php echo esc_attr__( '/thank-you/ or /confirmation/*', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 300px;" />
								<input type="text" name="conversion_urls[0][name]" placeholder="<?php echo esc_attr__( 'Goal Name (e.g., Purchase Complete)', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
								<label><input type="checkbox" name="conversion_urls[0][enabled]" value="1" checked /> <?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?></label>
								<button type="button" class="button wpa-remove-row"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
							</div>
						<?php else : ?>
							<?php foreach ( $conversion_urls as $index => $url_config ) : ?>
								<div class="wpa-conversion-row" data-index="<?php echo esc_attr( $index ); ?>" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
									<input type="text" name="conversion_urls[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $url_config['url'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'URL pattern', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 300px;" />
									<input type="text" name="conversion_urls[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $url_config['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr__( 'Goal Name', 'wp-analytics' ); ?>" class="regular-text" style="max-width: 200px;" />
									<label><input type="checkbox" name="conversion_urls[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $url_config['enabled'] ?? false ); ?> /> <?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?></label>
									<button type="button" class="button wpa-remove-row"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button" id="wpa-add-url"><?php echo esc_html__( 'Add URL', 'wp-analytics' ); ?></button>

					<hr style="margin: 30px 0;" />

					<?php submit_button( __( 'Save Conversion Goals', 'wp-analytics' ) ); ?>
				</form>

				<script>
				(function() {
					var buttonContainer = document.getElementById('wpa-button-conversions');
					var urlContainer = document.getElementById('wpa-url-conversions');
					var addButtonBtn = document.getElementById('wpa-add-button');
					var addUrlBtn = document.getElementById('wpa-add-url');
					var buttonIndex = <?php echo max( count( $conversion_buttons ), 1 ); ?>;
					var urlIndex = <?php echo max( count( $conversion_urls ), 1 ); ?>;

					addButtonBtn.addEventListener('click', function() {
						var row = document.createElement('div');
						row.className = 'wpa-conversion-row';
						row.dataset.index = buttonIndex;
						row.style.cssText = 'display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;';
						row.innerHTML = '<select name="conversion_buttons[' + buttonIndex + '][type]" style="width: 120px;"><option value="id"><?php echo esc_js( __( 'ID', 'wp-analytics' ) ); ?></option><option value="class"><?php echo esc_js( __( 'Class', 'wp-analytics' ) ); ?></option></select>' +
							'<input type="text" name="conversion_buttons[' + buttonIndex + '][selector]" placeholder="<?php echo esc_js( __( 'Selector', 'wp-analytics' ) ); ?>" class="regular-text" style="max-width: 200px;" />' +
							'<input type="text" name="conversion_buttons[' + buttonIndex + '][name]" placeholder="<?php echo esc_js( __( 'Friendly Name', 'wp-analytics' ) ); ?>" class="regular-text" style="max-width: 200px;" />' +
							'<label><input type="checkbox" name="conversion_buttons[' + buttonIndex + '][enabled]" value="1" checked /> <?php echo esc_js( __( 'Enabled', 'wp-analytics' ) ); ?></label>' +
							'<button type="button" class="button wpa-remove-row"><?php echo esc_js( __( 'Remove', 'wp-analytics' ) ); ?></button>';
						buttonContainer.appendChild(row);
						buttonIndex++;
					});

					addUrlBtn.addEventListener('click', function() {
						var row = document.createElement('div');
						row.className = 'wpa-conversion-row';
						row.dataset.index = urlIndex;
						row.style.cssText = 'display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;';
						row.innerHTML = '<input type="text" name="conversion_urls[' + urlIndex + '][url]" placeholder="<?php echo esc_js( __( 'URL pattern', 'wp-analytics' ) ); ?>" class="regular-text" style="max-width: 300px;" />' +
							'<input type="text" name="conversion_urls[' + urlIndex + '][name]" placeholder="<?php echo esc_js( __( 'Goal Name', 'wp-analytics' ) ); ?>" class="regular-text" style="max-width: 200px;" />' +
							'<label><input type="checkbox" name="conversion_urls[' + urlIndex + '][enabled]" value="1" checked /> <?php echo esc_js( __( 'Enabled', 'wp-analytics' ) ); ?></label>' +
							'<button type="button" class="button wpa-remove-row"><?php echo esc_js( __( 'Remove', 'wp-analytics' ) ); ?></button>';
						urlContainer.appendChild(row);
						urlIndex++;
					});

					document.addEventListener('click', function(e) {
						if (e.target.classList.contains('wpa-remove-row')) {
							e.target.closest('.wpa-conversion-row').remove();
						}
					});
				})();
				</script>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle form submission for saving conversion settings.
	 *
	 * @return void
	 */
	private static function handle_save_settings(): void {
		if ( ! isset( $_POST['wpa_action'] ) || $_POST['wpa_action'] !== 'save_conversions' ) {
			return;
		}

		if ( ! isset( $_POST['wpa_conversions_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpa_conversions_nonce'] ) ), 'wpa_save_conversions' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save button conversions
		$buttons = array();
		if ( isset( $_POST['conversion_buttons'] ) && is_array( $_POST['conversion_buttons'] ) ) {
			foreach ( $_POST['conversion_buttons'] as $button ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_array( $button ) && ! empty( $button['selector'] ) ) {
					$buttons[] = array(
						'selector' => sanitize_html_class( $button['selector'] ),
						'type'     => isset( $button['type'] ) && $button['type'] === 'class' ? 'class' : 'id',
						'name'     => sanitize_text_field( $button['name'] ?? '' ),
						'enabled'  => ! empty( $button['enabled'] ),
					);
				}
			}
		}
		WPA_Database::set_conversion_buttons( $buttons );

		// Save URL conversions
		$urls = array();
		if ( isset( $_POST['conversion_urls'] ) && is_array( $_POST['conversion_urls'] ) ) {
			foreach ( $_POST['conversion_urls'] as $url_config ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_array( $url_config ) && ! empty( $url_config['url'] ) ) {
					$urls[] = array(
						'url'     => sanitize_text_field( $url_config['url'] ),
						'name'    => sanitize_text_field( $url_config['name'] ?? '' ),
						'enabled' => ! empty( $url_config['enabled'] ),
					);
				}
			}
		}
		WPA_Database::set_conversion_urls( $urls );

		set_transient(
			'wpa_admin_notice',
			array(
				'type'    => 'success',
				'message' => __( 'Conversion goals saved successfully.', 'wp-analytics' ),
			),
			30
		);
	}

	/**
	 * Parse conversion info from stored format.
	 *
	 * Handles both old format (id|name) and new format (type|value|name).
	 *
	 * @param string $info The stored conversion info.
	 * @return array{type: string, value: string, name: string}
	 */
	private static function parse_conversion_info( string $info ): array {
		$parts = explode( '|', $info );

		// New format: type|value|name
		if ( count( $parts ) >= 3 && in_array( $parts[0], array( 'id', 'class', 'url' ), true ) ) {
			return array(
				'type'  => $parts[0],
				'value' => $parts[1],
				'name'  => $parts[2],
			);
		}

		// Old format: id|name (assume type is 'id')
		if ( count( $parts ) >= 2 ) {
			return array(
				'type'  => 'id',
				'value' => $parts[0],
				'name'  => $parts[1],
			);
		}

		// Fallback
		return array(
			'type'  => 'unknown',
			'value' => $info,
			'name'  => $info,
		);
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private static function render_admin_notices(): void {
		$notice = get_transient( 'wpa_admin_notice' );
		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'wpa_admin_notice' );

		$type    = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true )
			? $notice['type']
			: 'info';
		$message = $notice['message'] ?? '';

		if ( $message === '' ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Format a datetime as "time ago".
	 *
	 * @param string $datetime The datetime string.
	 * @return string Formatted time ago.
	 */
	private static function time_ago( string $datetime ): string {
		if ( $datetime === '' ) {
			return __( 'Unknown', 'wp-analytics' );
		}

		$timestamp = strtotime( $datetime );
		if ( $timestamp === false ) {
			return $datetime;
		}

		return human_time_diff( $timestamp, time() ) . ' ' . __( 'ago', 'wp-analytics' );
	}

	/**
	 * Truncate a path for display.
	 *
	 * @param string $path The path to truncate.
	 * @param int $length Maximum length.
	 * @return string Truncated path.
	 */
	private static function truncate_path( string $path, int $length = 50 ): string {
		if ( strlen( $path ) <= $length ) {
			return $path;
		}
		return substr( $path, 0, $length - 3 ) . '...';
	}
}
