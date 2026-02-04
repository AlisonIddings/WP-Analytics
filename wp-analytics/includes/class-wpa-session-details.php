<?php
/**
 * Session/User Journey view for WP Analytics.
 *
 * Displays the complete journey of a user session through the site,
 * showing all pages visited, links clicked, and conversions.
 *
 * @package WP_Analytics
 * @since 1.2.1
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_Session_Details
 *
 * Renders the session journey view.
 *
 * @since 1.2.1
 */
final class WPA_Session_Details {

	/**
	 * Render the session details view.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wp-analytics' ) );
		}

		// Get the session ID from query params
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';

		if ( $session_id === '' ) {
			self::render_sessions_list();
			return;
		}

		// Validate session ID format
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/i', $session_id ) ) {
			self::render_invalid_session();
			return;
		}

		// Get session data
		$summary = WPA_Database::get_session_summary( $session_id );
		$journey = WPA_Database::get_session_journey( $session_id );

		if ( empty( $journey ) ) {
			self::render_session_not_found();
			return;
		}

		?>
		<div class="wrap wpa-session-details-wrap">
			<h1>
				<?php echo esc_html__( 'User Journey', 'wp-analytics' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-session' ) ); ?>" class="page-title-action">
					<?php echo esc_html__( '← All Sessions', 'wp-analytics' ); ?>
				</a>
			</h1>

			<!-- Session Info -->
			<div class="wpa-session-header" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin: 20px 0;">
				<h2 style="margin: 0 0 10px 0;">
					<span class="dashicons dashicons-admin-users" style="margin-right: 8px;"></span>
					<?php echo esc_html__( 'Session:', 'wp-analytics' ); ?>
					<code style="font-size: 14px;"><?php echo esc_html( $session_id ); ?></code>
				</h2>
				<?php if ( ! empty( $summary['ip_address'] ) ) : ?>
					<p style="margin: 5px 0; color: #666;">
						<strong><?php echo esc_html__( 'IP:', 'wp-analytics' ); ?></strong>
						<?php echo esc_html( $summary['ip_address'] ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Session Summary Stats -->
			<div class="wpa-stats-cards">
				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-admin-page"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['pages_viewed'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Pages Viewed', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-clock"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( self::format_duration( (int) ( $summary['total_time'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Total Time', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-external"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['links_clicked'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Links Clicked', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-flag"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['conversions'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-calendar-alt"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number" style="font-size: 16px;"><?php echo esc_html( self::format_datetime( $summary['session_start'] ?? '' ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Session Start', 'wp-analytics' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Journey Timeline -->
			<div class="wpa-journey-timeline" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px;">
				<h2 style="margin-top: 0;">
					<span class="dashicons dashicons-chart-line" style="margin-right: 8px;"></span>
					<?php echo esc_html__( 'User Journey', 'wp-analytics' ); ?>
				</h2>

				<div class="wpa-timeline">
					<?php
					$prev_time    = null;
					$step_number  = 0;

					foreach ( $journey as $event ) :
						$event_type  = $event['event_type'] ?? '';
						$page_url    = $event['page_url'] ?? '';
						$page_path   = WPA_Database::extract_path( $page_url );
						$created_at  = $event['created_at'] ?? '';
						$time_on     = (int) ( $event['time_on_page'] ?? 0 );
						$scroll      = (int) ( $event['scroll_depth'] ?? 0 );
						$link_url    = $event['link_url'] ?? '';
						$referrer    = $event['referrer_url'] ?? '';

						// Calculate time gap from previous event
						$time_gap = '';
						if ( $prev_time !== null && $created_at !== '' ) {
							$gap_seconds = strtotime( $created_at ) - strtotime( $prev_time );
							if ( $gap_seconds > 0 ) {
								$time_gap = self::format_duration( $gap_seconds );
							}
						}
						$prev_time = $created_at;

						// Determine event styling
						$icon_class  = 'dashicons-admin-page';
						$event_label = __( 'Pageview', 'wp-analytics' );
						$event_color = '#2271b1';

						if ( $event_type === 'link_click' ) {
							$icon_class  = 'dashicons-external';
							$event_label = __( 'Link Click', 'wp-analytics' );
							$event_color = '#826eb4';
						} elseif ( $event_type === 'conversion' ) {
							$icon_class  = 'dashicons-flag';
							$event_label = __( 'Conversion', 'wp-analytics' );
							$event_color = '#4caf50';
						}

						$step_number++;
					?>
						<?php if ( $time_gap !== '' && $step_number > 1 ) : ?>
							<div class="wpa-timeline-gap" style="text-align: center; padding: 5px 0; color: #666; font-size: 12px;">
								<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 14px;"></span>
								<?php echo esc_html( $time_gap ); ?>
							</div>
						<?php endif; ?>

						<div class="wpa-timeline-event" style="display: flex; gap: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid <?php echo esc_attr( $event_color ); ?>; margin-bottom: 10px;">
							<div class="wpa-timeline-icon" style="flex-shrink: 0;">
								<span class="dashicons <?php echo esc_attr( $icon_class ); ?>" style="color: <?php echo esc_attr( $event_color ); ?>; font-size: 24px;"></span>
							</div>
							<div class="wpa-timeline-content" style="flex-grow: 1;">
								<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
									<strong style="color: <?php echo esc_attr( $event_color ); ?>;">
										<?php echo esc_html( $step_number . '. ' . $event_label ); ?>
									</strong>
									<span style="color: #666; font-size: 12px;">
										<?php echo esc_html( self::format_time( $created_at ) ); ?>
									</span>
								</div>

								<?php if ( $event_type === 'pageview' ) : ?>
									<div style="margin-bottom: 5px;">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $page_path ) ) ); ?>">
											<?php echo esc_html( $page_path ); ?>
										</a>
									</div>
									<?php if ( $referrer !== '' && $step_number === 1 ) : ?>
										<div style="font-size: 12px; color: #666;">
											<?php echo esc_html__( 'Came from:', 'wp-analytics' ); ?>
											<?php echo esc_html( WPA_Database::extract_path( $referrer ) ); ?>
										</div>
									<?php endif; ?>
									<?php if ( $time_on > 0 || $scroll > 0 ) : ?>
										<div style="font-size: 12px; color: #666; margin-top: 5px;">
											<?php if ( $time_on > 0 ) : ?>
												<span style="margin-right: 15px;">
													<span class="dashicons dashicons-clock" style="font-size: 14px; vertical-align: middle;"></span>
													<?php echo esc_html( self::format_duration( $time_on ) ); ?>
												</span>
											<?php endif; ?>
											<?php if ( $scroll > 0 ) : ?>
												<span>
													<span class="dashicons dashicons-arrow-down-alt" style="font-size: 14px; vertical-align: middle;"></span>
													<?php echo esc_html( $scroll . '%' ); ?> <?php echo esc_html__( 'scroll', 'wp-analytics' ); ?>
												</span>
											<?php endif; ?>
										</div>
									<?php endif; ?>

								<?php elseif ( $event_type === 'link_click' ) : ?>
									<div style="margin-bottom: 5px;">
										<?php echo esc_html__( 'On page:', 'wp-analytics' ); ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $page_path ) ) ); ?>">
											<?php echo esc_html( $page_path ); ?>
										</a>
									</div>
									<div style="font-size: 12px; color: #666;">
										<?php echo esc_html__( 'Clicked:', 'wp-analytics' ); ?>
										<a href="<?php echo esc_url( $link_url ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( WPA_Database::extract_path( $link_url ) ); ?>
										</a>
									</div>

								<?php elseif ( $event_type === 'conversion' ) : ?>
									<div style="margin-bottom: 5px;">
										<?php echo esc_html__( 'On page:', 'wp-analytics' ); ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $page_path ) ) ); ?>">
											<?php echo esc_html( $page_path ); ?>
										</a>
									</div>
									<?php if ( $link_url !== '' && strpos( $link_url, '|' ) !== false ) : ?>
										<?php
										$parts       = explode( '|', $link_url, 2 );
										$button_id   = $parts[0];
										$button_name = $parts[1] ?? $button_id;
										?>
										<div style="font-size: 12px; margin-top: 5px;">
											<span class="wpa-conversion-badge" style="background: #4caf50; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
												<?php echo esc_html( $button_name ); ?>
											</span>
											<span style="color: #666; margin-left: 5px;">
												(ID: <?php echo esc_html( $button_id ); ?>)
											</span>
										</div>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<!-- Session End -->
					<div class="wpa-timeline-end" style="text-align: center; padding: 15px; color: #666;">
						<span class="dashicons dashicons-marker" style="font-size: 20px;"></span>
						<div style="margin-top: 5px;"><?php echo esc_html__( 'Session ended', 'wp-analytics' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render list of recent sessions.
	 *
	 * @return void
	 */
	private static function render_sessions_list(): void {
		$sessions = WPA_Database::get_recent_sessions( 50 );

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'User Sessions', 'wp-analytics' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics' ) ); ?>" class="page-title-action">
					<?php echo esc_html__( '← Back to Overview', 'wp-analytics' ); ?>
				</a>
			</h1>

			<p class="description" style="margin: 15px 0;">
				<?php echo esc_html__( 'Click on a session to view the complete user journey through your site.', 'wp-analytics' ); ?>
			</p>

			<?php if ( empty( $sessions ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No sessions recorded yet. Visit your site to start collecting data.', 'wp-analytics' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Session ID', 'wp-analytics' ); ?></th>
							<th><?php echo esc_html__( 'Started', 'wp-analytics' ); ?></th>
							<th><?php echo esc_html__( 'Pages', 'wp-analytics' ); ?></th>
							<th><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></th>
							<th><?php echo esc_html__( 'Entry Page', 'wp-analytics' ); ?></th>
							<th><?php echo esc_html__( 'IP Address', 'wp-analytics' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sessions as $session ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-session&session=' . urlencode( $session['session_id'] ) ) ); ?>">
										<strong><?php echo esc_html( substr( $session['session_id'], 0, 12 ) . '...' ); ?></strong>
									</a>
								</td>
								<td>
									<span title="<?php echo esc_attr( $session['session_start'] ); ?>">
										<?php echo esc_html( self::time_ago( $session['session_start'] ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $session['pages_viewed'] ) ); ?></td>
								<td>
									<?php if ( (int) $session['conversions'] > 0 ) : ?>
										<span style="color: #4caf50; font-weight: 600;">
											<?php echo esc_html( number_format_i18n( (int) $session['conversions'] ) ); ?>
										</span>
									<?php else : ?>
										0
									<?php endif; ?>
								</td>
								<td>
									<?php
									$entry_path = WPA_Database::extract_path( $session['entry_page'] ?? '' );
									$truncated  = strlen( $entry_path ) > 40 ? substr( $entry_path, 0, 37 ) . '...' : $entry_path;
									?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $entry_path ) ) ); ?>" title="<?php echo esc_attr( $entry_path ); ?>">
										<?php echo esc_html( $truncated ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $session['ip_address'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render invalid session error.
	 *
	 * @return void
	 */
	private static function render_invalid_session(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'User Journey', 'wp-analytics' ); ?></h1>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Invalid session ID format.', 'wp-analytics' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-session' ) ); ?>" class="button">
					<?php echo esc_html__( 'View All Sessions', 'wp-analytics' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render session not found error.
	 *
	 * @return void
	 */
	private static function render_session_not_found(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'User Journey', 'wp-analytics' ); ?></h1>
			<div class="notice notice-warning">
				<p><?php echo esc_html__( 'Session not found. It may have been deleted or the data retention period has passed.', 'wp-analytics' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-session' ) ); ?>" class="button">
					<?php echo esc_html__( 'View All Sessions', 'wp-analytics' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Format seconds as human-readable duration.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private static function format_duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '0s';
		}
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		$minutes = floor( $seconds / 60 );
		$secs    = $seconds % 60;
		if ( $minutes < 60 ) {
			return $minutes . 'm ' . $secs . 's';
		}
		$hours = floor( $minutes / 60 );
		$mins  = $minutes % 60;
		return $hours . 'h ' . $mins . 'm';
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
	 * Format a datetime for display.
	 *
	 * @param string $datetime The datetime string.
	 * @return string Formatted datetime.
	 */
	private static function format_datetime( string $datetime ): string {
		if ( $datetime === '' ) {
			return __( 'Unknown', 'wp-analytics' );
		}

		$timestamp = strtotime( $datetime );
		if ( $timestamp === false ) {
			return $datetime;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Format a datetime as time only.
	 *
	 * @param string $datetime The datetime string.
	 * @return string Formatted time.
	 */
	private static function format_time( string $datetime ): string {
		if ( $datetime === '' ) {
			return '';
		}

		$timestamp = strtotime( $datetime );
		if ( $timestamp === false ) {
			return $datetime;
		}

		return wp_date( get_option( 'time_format' ), $timestamp );
	}
}
