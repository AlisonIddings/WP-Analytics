<?php
/**
 * Page Details view for WP Analytics.
 *
 * Displays detailed analytics for a single page including
 * stats, trends, sessions, and outbound links.
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
 * Class WPA_Page_Details
 *
 * Renders the single page analytics view.
 *
 * @since 1.2.1
 */
final class WPA_Page_Details {

	/**
	 * Render the page details view.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wp-analytics' ) );
		}

		// Get the page path from query params
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';

		if ( $page_path === '' ) {
			self::render_no_page_selected();
			return;
		}

		// Get date range (default last 30 days)
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// Get analytics data
		$summary      = WPA_Database::get_page_analytics( $page_path, $start_date, $end_date );
		$trends       = WPA_Database::get_page_daily_trends( $page_path, 30 );
		$sessions     = WPA_Database::get_page_sessions( $page_path, 25 );
		$outbound     = WPA_Database::get_page_outbound_links( $page_path, 15 );

		// Prepare chart data
		$chart_labels    = array();
		$chart_pageviews = array();

		foreach ( $trends as $trend ) {
			$chart_labels[]    = $trend['period'];
			$chart_pageviews[] = (int) $trend['pageviews'];
		}

		?>
		<div class="wrap wpa-page-details-wrap">
			<h1>
				<?php echo esc_html__( 'Page Analytics', 'wp-analytics' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics' ) ); ?>" class="page-title-action">
					<?php echo esc_html__( '← Back to Overview', 'wp-analytics' ); ?>
				</a>
			</h1>

			<!-- Page Info -->
			<div class="wpa-page-header" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin: 20px 0;">
				<h2 style="margin: 0 0 10px 0;">
					<span class="dashicons dashicons-admin-page" style="margin-right: 8px;"></span>
					<?php echo esc_html( $page_path ); ?>
				</h2>
				<p style="margin: 0;">
					<a href="<?php echo esc_url( home_url( $page_path ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'View Page', 'wp-analytics' ); ?> →
					</a>
				</p>
			</div>

			<!-- Summary Stats -->
			<div class="wpa-stats-cards">
				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-visibility"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['pageviews'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Pageviews', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-groups"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['unique_sessions'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Unique Sessions', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-clock"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( self::format_duration( (int) ( $summary['avg_time'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Avg. Time', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-arrow-down-alt"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( (int) ( $summary['avg_scroll'] ?? 0 ) . '%' ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Avg. Scroll', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-external"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['link_clicks'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Link Clicks', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-flag"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( (int) ( $summary['conversions'] ?? 0 ) ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></span>
					</div>
				</div>
			</div>

			<div class="wpa-page-details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
				<!-- Pageview Trend Chart -->
				<div class="wpa-chart-container" style="grid-column: 1 / -1;">
					<h2><?php echo esc_html__( 'Pageviews (Last 30 Days)', 'wp-analytics' ); ?></h2>
					<?php if ( empty( $trends ) ) : ?>
						<p class="wpa-no-data"><?php echo esc_html__( 'No data available yet.', 'wp-analytics' ); ?></p>
					<?php else : ?>
						<canvas id="wpa-page-chart" height="200"></canvas>
					<?php endif; ?>
				</div>

				<!-- Recent Sessions -->
				<div class="wpa-sessions-list" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__( 'Recent Visitors', 'wp-analytics' ); ?></h3>
					<?php if ( empty( $sessions ) ) : ?>
						<p class="wpa-no-data"><?php echo esc_html__( 'No sessions found.', 'wp-analytics' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped" style="margin: 0;">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Session', 'wp-analytics' ); ?></th>
									<th><?php echo esc_html__( 'Time', 'wp-analytics' ); ?></th>
									<th><?php echo esc_html__( 'Scroll', 'wp-analytics' ); ?></th>
									<th><?php echo esc_html__( 'Visited', 'wp-analytics' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sessions as $session ) : ?>
									<tr>
										<td>
											<?php if ( ! empty( $session['session_id'] ) ) : ?>
												<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-session&session=' . urlencode( $session['session_id'] ) ) ); ?>">
													<?php echo esc_html( substr( $session['session_id'], 0, 8 ) . '...' ); ?>
												</a>
											<?php else : ?>
												<em><?php echo esc_html__( 'Unknown', 'wp-analytics' ); ?></em>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( self::format_duration( (int) ( $session['time_on_page'] ?? 0 ) ) ); ?></td>
										<td><?php echo esc_html( (int) ( $session['scroll_depth'] ?? 0 ) . '%' ); ?></td>
										<td>
											<span title="<?php echo esc_attr( $session['first_visit'] ?? '' ); ?>">
												<?php echo esc_html( self::time_ago( $session['first_visit'] ?? '' ) ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Outbound Links -->
				<div class="wpa-outbound-links" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__( 'Links Clicked From This Page', 'wp-analytics' ); ?></h3>
					<?php if ( empty( $outbound ) ) : ?>
						<p class="wpa-no-data"><?php echo esc_html__( 'No link clicks recorded.', 'wp-analytics' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped" style="margin: 0;">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Link URL', 'wp-analytics' ); ?></th>
									<th style="width: 80px;"><?php echo esc_html__( 'Clicks', 'wp-analytics' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $outbound as $link ) : ?>
									<tr>
										<td>
											<?php
											$link_path = WPA_Database::extract_path( $link['link_url'] );
											$truncated = strlen( $link_path ) > 50 ? substr( $link_path, 0, 47 ) . '...' : $link_path;
											?>
											<a href="<?php echo esc_url( $link['link_url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $link['link_url'] ); ?>">
												<?php echo esc_html( $truncated ); ?>
											</a>
										</td>
										<td><?php echo esc_html( number_format_i18n( (int) $link['click_count'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $trends ) ) : ?>
			<!-- Chart.js -->
			<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
			<script>
			document.addEventListener('DOMContentLoaded', function() {
				var ctx = document.getElementById('wpa-page-chart');
				if (!ctx) return;

				new Chart(ctx, {
					type: 'bar',
					data: {
						labels: <?php echo wp_json_encode( $chart_labels ); ?>,
						datasets: [{
							label: '<?php echo esc_js( __( 'Pageviews', 'wp-analytics' ) ); ?>',
							data: <?php echo wp_json_encode( $chart_pageviews ); ?>,
							backgroundColor: 'rgba(34, 113, 177, 0.7)',
							borderColor: '#2271b1',
							borderWidth: 1
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							legend: { display: false }
						},
						scales: {
							y: {
								beginAtZero: true,
								ticks: { precision: 0 }
							}
						}
					}
				});
			});
			</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the no page selected state.
	 *
	 * @return void
	 */
	private static function render_no_page_selected(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Page Analytics', 'wp-analytics' ); ?></h1>
			<div class="notice notice-warning">
				<p><?php echo esc_html__( 'No page selected. Please select a page from the Analytics Overview or Event Log.', 'wp-analytics' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics' ) ); ?>" class="button button-primary">
					<?php echo esc_html__( 'Go to Analytics Overview', 'wp-analytics' ); ?>
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
}
