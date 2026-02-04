<?php
/**
 * Analytics dashboard page for WP Analytics.
 *
 * Displays charts and statistics for long-term analytics viewing
 * including pageview trends, top pages, and conversion data.
 *
 * @package WP_Analytics
 * @since 1.1.0
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_Analytics
 *
 * Renders the Analytics overview page with:
 * - Summary statistics cards
 * - Pageview trend charts (month/year comparison)
 * - Top pages table
 *
 * @since 1.1.0
 */
final class WPA_Analytics {

	/**
	 * Render the analytics overview page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wp-analytics' ) );
		}

		// Handle manual aggregation request
		self::handle_manual_aggregation();

		// Get date range from query params or default to last 30 days
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$range     = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30days';
		$compare   = isset( $_GET['compare'] ) ? sanitize_key( $_GET['compare'] ) : 'month';
		// phpcs:enable

		// Calculate date ranges
		$ranges     = self::get_date_ranges( $range );
		$end_date   = $ranges['end'];
		$start_date = $ranges['start'];

		// Check if we have aggregated stats or need to use real-time data
		$has_aggregated = WPA_Database::has_aggregated_stats();
		$using_realtime = false;

		if ( $has_aggregated ) {
			// Use aggregated stats (efficient)
			$summary   = WPA_Database::get_summary_stats( $start_date, $end_date );
			$top_pages = WPA_Database::get_top_pages( $start_date, $end_date, 15 );
			$trends    = WPA_Database::get_pageview_trends( $compare, $compare === 'year' ? 5 : 12 );
		} else {
			// Fall back to real-time data from events table
			$using_realtime = true;
			$summary        = WPA_Database::get_realtime_summary_stats( $start_date, $end_date );
			$top_pages      = WPA_Database::get_realtime_top_pages( $start_date, $end_date, 15 );

			// For trends, use daily data from events
			$days = self::get_days_for_range( $range );
			$trends = WPA_Database::get_realtime_daily_trends( $days );
		}

		// Prepare chart data
		$chart_labels      = array();
		$chart_pageviews   = array();
		$chart_sessions    = array();
		$chart_conversions = array();

		foreach ( $trends as $trend ) {
			$chart_labels[]      = $trend['period'];
			$chart_pageviews[]   = (int) $trend['pageviews'];
			$chart_sessions[]    = (int) $trend['sessions'];
			$chart_conversions[] = (int) $trend['conversions'];
		}

		?>
		<div class="wrap wpa-analytics-wrap">
			<h1><?php echo esc_html__( 'Analytics Overview', 'wp-analytics' ); ?></h1>

			<?php if ( $using_realtime ) : ?>
			<!-- Real-time data notice -->
			<div class="notice notice-info" style="margin: 15px 0;">
				<p>
					<strong><?php echo esc_html__( 'Real-time Mode:', 'wp-analytics' ); ?></strong>
					<?php echo esc_html__( 'Showing live data from the events table. For better performance, data is normally aggregated daily. You can trigger aggregation manually below.', 'wp-analytics' ); ?>
				</p>
				<p>
					<form method="post" action="" style="display: inline;">
						<?php wp_nonce_field( 'wpa_manual_aggregate', 'wpa_aggregate_nonce' ); ?>
						<input type="hidden" name="wpa_action" value="aggregate_now" />
						<button type="submit" class="button button-secondary">
							<?php echo esc_html__( 'Aggregate Data Now', 'wp-analytics' ); ?>
						</button>
					</form>
					<span class="description" style="margin-left: 10px;">
						<?php echo esc_html__( 'This will process all historical data into the aggregated stats table.', 'wp-analytics' ); ?>
					</span>
				</p>
			</div>
			<?php endif; ?>

			<!-- Date Range Selector -->
			<div class="wpa-date-controls">
				<form method="get" action="">
					<input type="hidden" name="page" value="wp-analytics-overview" />
					
					<label for="wpa-range"><?php echo esc_html__( 'Date Range:', 'wp-analytics' ); ?></label>
					<select name="range" id="wpa-range">
						<option value="7days" <?php selected( $range, '7days' ); ?>><?php echo esc_html__( 'Last 7 Days', 'wp-analytics' ); ?></option>
						<option value="30days" <?php selected( $range, '30days' ); ?>><?php echo esc_html__( 'Last 30 Days', 'wp-analytics' ); ?></option>
						<option value="90days" <?php selected( $range, '90days' ); ?>><?php echo esc_html__( 'Last 90 Days', 'wp-analytics' ); ?></option>
						<option value="year" <?php selected( $range, 'year' ); ?>><?php echo esc_html__( 'Last 12 Months', 'wp-analytics' ); ?></option>
						<option value="all" <?php selected( $range, 'all' ); ?>><?php echo esc_html__( 'All Time', 'wp-analytics' ); ?></option>
					</select>

					<label for="wpa-compare"><?php echo esc_html__( 'Chart View:', 'wp-analytics' ); ?></label>
					<select name="compare" id="wpa-compare">
						<option value="month" <?php selected( $compare, 'month' ); ?>><?php echo esc_html__( 'Month over Month', 'wp-analytics' ); ?></option>
						<option value="year" <?php selected( $compare, 'year' ); ?>><?php echo esc_html__( 'Year over Year', 'wp-analytics' ); ?></option>
					</select>

					<button type="submit" class="button"><?php echo esc_html__( 'Apply', 'wp-analytics' ); ?></button>
				</form>
			</div>

			<!-- Summary Cards -->
			<div class="wpa-stats-cards">
				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-visibility"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( $summary['total_pageviews'] ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Pageviews', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-groups"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( $summary['total_sessions'] ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Sessions', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-clock"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( self::format_duration( $summary['avg_time'] ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Avg. Time on Page', 'wp-analytics' ); ?></span>
					</div>
				</div>

				<div class="wpa-stat-card">
					<div class="wpa-stat-icon dashicons dashicons-flag"></div>
					<div class="wpa-stat-content">
						<span class="wpa-stat-number"><?php echo esc_html( number_format_i18n( $summary['total_conversions'] ) ); ?></span>
						<span class="wpa-stat-label"><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Pageview Trends Chart -->
			<div class="wpa-chart-container">
				<h2><?php echo esc_html__( 'Traffic Trends', 'wp-analytics' ); ?></h2>
				<?php if ( empty( $trends ) ) : ?>
					<p class="wpa-no-data">
						<?php echo esc_html__( 'No trend data available yet. Visit some pages on your site to start collecting data.', 'wp-analytics' ); ?>
						<br />
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-analytics-events' ) ); ?>">
							<?php echo esc_html__( 'View Event Log', 'wp-analytics' ); ?>
						</a>
						<?php echo esc_html__( 'to see real-time events as they happen.', 'wp-analytics' ); ?>
					</p>
				<?php else : ?>
					<canvas id="wpa-trends-chart" height="300"></canvas>
				<?php endif; ?>
			</div>

			<!-- Top Pages Table -->
			<div class="wpa-top-pages">
				<h2><?php echo esc_html__( 'Top Pages', 'wp-analytics' ); ?></h2>
				<?php if ( empty( $top_pages ) ) : ?>
					<p class="wpa-no-data">
						<?php echo esc_html__( 'No page data available yet. Visit some pages on your site to start collecting data.', 'wp-analytics' ); ?>
					</p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th class="wpa-col-page"><?php echo esc_html__( 'Page', 'wp-analytics' ); ?></th>
								<th class="wpa-col-num"><?php echo esc_html__( 'Pageviews', 'wp-analytics' ); ?></th>
								<th class="wpa-col-num"><?php echo esc_html__( 'Sessions', 'wp-analytics' ); ?></th>
								<th class="wpa-col-num"><?php echo esc_html__( 'Avg. Time', 'wp-analytics' ); ?></th>
								<th class="wpa-col-num"><?php echo esc_html__( 'Scroll %', 'wp-analytics' ); ?></th>
								<th class="wpa-col-num"><?php echo esc_html__( 'Conversions', 'wp-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $top_pages as $page ) : ?>
								<tr>
									<td class="wpa-col-page">
										<a href="<?php echo esc_url( home_url( $page['page_path'] ) ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $page['page_path'] ); ?>">
											<?php echo esc_html( self::truncate_path( $page['page_path'], 60 ) ); ?>
										</a>
									</td>
									<td class="wpa-col-num"><?php echo esc_html( number_format_i18n( (int) $page['total_pageviews'] ) ); ?></td>
									<td class="wpa-col-num"><?php echo esc_html( number_format_i18n( (int) $page['total_sessions'] ) ); ?></td>
									<td class="wpa-col-num"><?php echo esc_html( self::format_duration( (int) $page['avg_time'] ) ); ?></td>
									<td class="wpa-col-num"><?php echo esc_html( (int) $page['avg_scroll'] . '%' ); ?></td>
									<td class="wpa-col-num"><?php echo esc_html( number_format_i18n( (int) $page['total_conversions'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

		<?php if ( ! empty( $trends ) ) : ?>
		<!-- Chart.js from CDN with SRI for security -->
		<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-fMnnnlPH8grVo/RbY/FMpn6X3L9RfMsOG37fOqVlkjMFZ0160sJALqniJQx/nSUG" crossorigin="anonymous"></script>
			<script>
			document.addEventListener('DOMContentLoaded', function() {
				var ctx = document.getElementById('wpa-trends-chart');
				if (!ctx) return;

				new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode( $chart_labels ); ?>,
						datasets: [
							{
								label: '<?php echo esc_js( __( 'Pageviews', 'wp-analytics' ) ); ?>',
								data: <?php echo wp_json_encode( $chart_pageviews ); ?>,
								borderColor: '#2271b1',
								backgroundColor: 'rgba(34, 113, 177, 0.1)',
								fill: true,
								tension: 0.3
							},
							{
								label: '<?php echo esc_js( __( 'Sessions', 'wp-analytics' ) ); ?>',
								data: <?php echo wp_json_encode( $chart_sessions ); ?>,
								borderColor: '#72aee6',
								backgroundColor: 'transparent',
								borderDash: [5, 5],
								tension: 0.3
							},
							{
								label: '<?php echo esc_js( __( 'Conversions', 'wp-analytics' ) ); ?>',
								data: <?php echo wp_json_encode( $chart_conversions ); ?>,
								borderColor: '#4caf50',
								backgroundColor: 'transparent',
								tension: 0.3
							}
						]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						interaction: {
							intersect: false,
							mode: 'index'
						},
						plugins: {
							legend: {
								position: 'bottom'
							}
						},
						scales: {
							y: {
								beginAtZero: true,
								ticks: {
									precision: 0
								}
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
	 * Handle manual aggregation request.
	 *
	 * @return void
	 */
	private static function handle_manual_aggregation(): void {
		if ( ! isset( $_POST['wpa_action'] ) || $_POST['wpa_action'] !== 'aggregate_now' ) {
			return;
		}

		if ( ! isset( $_POST['wpa_aggregate_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpa_aggregate_nonce'] ) ), 'wpa_manual_aggregate' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Run aggregation for all historical data
		$count = self::aggregate_all_historical_data();

		// Show success notice
		add_action(
			'admin_notices',
			static function () use ( $count ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of pages aggregated */
							__( 'Aggregation complete! Processed data for %d unique date/page combinations.', 'wp-analytics' ),
							$count
						)
					)
				);
			}
		);
	}

	/**
	 * Aggregate all historical data from events into the stats table.
	 *
	 * @return int Number of records created/updated.
	 */
	private static function aggregate_all_historical_data(): int {
		global $wpdb;

		$events_table = WPA_Database::table_name();
		$stats_table  = WPA_Database::stats_table_name();

		// Get all unique dates in the events table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dates = $wpdb->get_col(
			"SELECT DISTINCT DATE(created_at) as event_date 
			FROM {$events_table} 
			WHERE created_at < CURDATE()
			ORDER BY event_date ASC"
		);

		if ( empty( $dates ) ) {
			return 0;
		}

		$total_count = 0;

		foreach ( $dates as $date ) {
			// Check if already aggregated
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$stats_table} WHERE stat_date = %s",
					$date
				)
			);

			if ( (int) $exists > 0 ) {
				continue; // Already aggregated
			}

			// Aggregate pageview stats for this date
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
					$date
				),
				ARRAY_A
			);

			if ( empty( $pageview_stats ) ) {
				continue;
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
					$date
				),
				ARRAY_A
			);

			// Index click stats by URL
			$click_index = array();
			foreach ( $click_stats as $stat ) {
				$click_index[ $stat['page_url'] ] = $stat;
			}

			// Insert aggregated stats
			foreach ( $pageview_stats as $stat ) {
				$page_path = WPA_Database::extract_path( $stat['page_url'] );
				$clicks    = $click_index[ $stat['page_url'] ]['clicks'] ?? 0;
				$convs     = $click_index[ $stat['page_url'] ]['conversions'] ?? 0;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$stats_table,
					array(
						'stat_date'        => $date,
						'page_path'        => $page_path,
						'pageviews'        => (int) $stat['pageviews'],
						'unique_sessions'  => (int) $stat['unique_sessions'],
						'avg_time_on_page' => (int) round( (float) ( $stat['avg_time'] ?? 0 ) ),
						'avg_scroll_depth' => (int) round( (float) ( $stat['avg_scroll'] ?? 0 ) ),
						'link_clicks'      => (int) $clicks,
						'conversions'      => (int) $convs,
					),
					array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
				);
				$total_count++;
			}
		}

		return $total_count;
	}

	/**
	 * Get number of days for a given range identifier.
	 *
	 * @param string $range Range identifier.
	 * @return int Number of days.
	 */
	private static function get_days_for_range( string $range ): int {
		switch ( $range ) {
			case '7days':
				return 7;
			case '90days':
				return 90;
			case 'year':
				return 365;
			case 'all':
				return 365; // Cap at 1 year for real-time queries
			default: // 30days
				return 30;
		}
	}

	/**
	 * Get date range based on selected period.
	 *
	 * @param string $range Range identifier.
	 * @return array{start: string, end: string}
	 */
	private static function get_date_ranges( string $range ): array {
		$end = gmdate( 'Y-m-d' );

		switch ( $range ) {
			case '7days':
				$start = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;
			case '90days':
				$start = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
				break;
			case 'year':
				$start = gmdate( 'Y-m-d', strtotime( '-1 year' ) );
				break;
			case 'all':
				$start = '2020-01-01';
				break;
			default: // 30days
				$start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Format seconds as human-readable duration.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration (e.g., "2m 30s")
	 */
	private static function format_duration( int $seconds ): string {
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
	 * Truncate a path for display.
	 *
	 * @param string $path   The path to truncate.
	 * @param int    $length Maximum length.
	 * @return string Truncated path.
	 */
	private static function truncate_path( string $path, int $length = 50 ): string {
		if ( strlen( $path ) <= $length ) {
			return $path;
		}

		return substr( $path, 0, $length - 3 ) . '...';
	}
}
