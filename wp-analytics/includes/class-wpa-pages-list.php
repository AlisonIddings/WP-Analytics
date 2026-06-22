<?php
/**
 * All Pages list view for WP Analytics.
 *
 * Displays a searchable, filterable, paginated list of all tracked pages
 * with detailed analytics for each.
 *
 * @package WP_Analytics
 * @since 1.3.1
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_Pages_List
 *
 * Renders the All Pages view with search, filter, and pagination.
 *
 * @since 1.3.1
 */
final class WPA_Pages_List {

	/** @var int Items per page */
	private const PER_PAGE = 25;

	/**
	 * Render the pages list view.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wp-analytics' ) );
		}

		// Get filter parameters
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$range     = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30days';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'pageviews';
		$order     = isset( $_GET['order'] ) ? strtoupper( sanitize_key( $_GET['order'] ) ) : 'DESC';
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		// Validate order direction
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Validate orderby column
		$allowed_orderby = array( 'page_path', 'pageviews', 'sessions', 'avg_time', 'avg_scroll', 'conversions' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'pageviews';
		}

		// Calculate date range
		$dates      = self::get_date_range( $range );
		$start_date = $dates['start'];
		$end_date   = $dates['end'];

		// Get data
		$total_items = WPA_Database::count_all_pages( $start_date, $end_date, $search );
		$total_pages = (int) ceil( $total_items / self::PER_PAGE );
		$pages       = WPA_Database::get_all_pages( $start_date, $end_date, $search, $orderby, $order, self::PER_PAGE, $paged );

		// Build base URL for pagination and sorting
		$base_url = admin_url( 'admin.php?page=wp-analytics-pages' );

		?>
		<div class="wrap wpa-pages-list-wrap">
			<h1><?php echo esc_html__( 'All Pages', 'wp-analytics' ); ?></h1>

			<!-- Filters Form -->
			<form method="get" action="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="page" value="wp-analytics-pages" />

				<div class="wpa-filters" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4;">
					<!-- Search -->
					<div class="wpa-field">
						<label for="wpa-search"><?php echo esc_html__( 'Search Pages', 'wp-analytics' ); ?></label>
						<input type="search" id="wpa-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'URL path...', 'wp-analytics' ); ?>" style="width: 250px;" />
					</div>

					<!-- Date Range -->
					<div class="wpa-field">
						<label for="wpa-range"><?php echo esc_html__( 'Date Range', 'wp-analytics' ); ?></label>
						<select name="range" id="wpa-range">
							<option value="7days" <?php selected( $range, '7days' ); ?>><?php echo esc_html__( 'Last 7 Days', 'wp-analytics' ); ?></option>
							<option value="30days" <?php selected( $range, '30days' ); ?>><?php echo esc_html__( 'Last 30 Days', 'wp-analytics' ); ?></option>
							<option value="90days" <?php selected( $range, '90days' ); ?>><?php echo esc_html__( 'Last 90 Days', 'wp-analytics' ); ?></option>
							<option value="year" <?php selected( $range, 'year' ); ?>><?php echo esc_html__( 'Last 12 Months', 'wp-analytics' ); ?></option>
							<option value="all" <?php selected( $range, 'all' ); ?>><?php echo esc_html__( 'All Time', 'wp-analytics' ); ?></option>
						</select>
					</div>

					<!-- Submit -->
					<div class="wpa-field">
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Filter', 'wp-analytics' ); ?></button>
						<?php if ( $search !== '' || $range !== '30days' ) : ?>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php echo esc_html__( 'Reset', 'wp-analytics' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>

			<!-- Results Summary -->
			<p class="wpa-results-summary">
				<?php
				printf(
					/* translators: %s: number of pages */
					esc_html( _n( '%s page found', '%s pages found', $total_items, 'wp-analytics' ) ),
					'<strong>' . esc_html( number_format_i18n( $total_items ) ) . '</strong>'
				);
				if ( $search !== '' ) {
					printf(
						/* translators: %s: search term */
						' ' . esc_html__( 'matching "%s"', 'wp-analytics' ),
						esc_html( $search )
					);
				}
				?>
			</p>

			<?php if ( empty( $pages ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No pages found matching your criteria.', 'wp-analytics' ); ?></p>
				</div>
			<?php else : ?>
				<!-- Pages Table -->
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<?php echo self::render_sortable_header( 'page_path', __( 'Page', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<?php echo self::render_sortable_header( 'pageviews', __( 'Pageviews', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<?php echo self::render_sortable_header( 'sessions', __( 'Sessions', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<?php echo self::render_sortable_header( 'avg_time', __( 'Avg. Time', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<?php echo self::render_sortable_header( 'avg_scroll', __( 'Scroll %', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<?php echo self::render_sortable_header( 'conversions', __( 'Conversions', 'wp-analytics' ), $orderby, $order, $search, $range ); ?>
							<th><?php echo esc_html__( 'Actions', 'wp-analytics' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<?php
							$page_path = $page['page_path'] ?? '';
							$details_url = admin_url( 'admin.php?page=wp-analytics-page&path=' . urlencode( $page_path ) );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $details_url ); ?>" title="<?php echo esc_attr( $page_path ); ?>">
										<strong><?php echo esc_html( self::truncate_path( $page_path, 50 ) ); ?></strong>
									</a>
									<br>
									<a href="<?php echo esc_url( home_url( $page_path ) ); ?>" target="_blank" rel="noopener noreferrer" class="row-actions" style="color: #999;">
										<?php echo esc_html__( 'View page', 'wp-analytics' ); ?> →
									</a>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) ( $page['total_pageviews'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) ( $page['total_sessions'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( self::format_duration( (int) ( $page['avg_time'] ?? 0 ) ) ); ?></td>
								<td><?php echo esc_html( (int) ( $page['avg_scroll'] ?? 0 ) . '%' ); ?></td>
								<td>
									<?php if ( (int) ( $page['total_conversions'] ?? 0 ) > 0 ) : ?>
										<span style="color: #4caf50; font-weight: 600;">
											<?php echo esc_html( number_format_i18n( (int) $page['total_conversions'] ) ); ?>
										</span>
									<?php else : ?>
										0
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $details_url ); ?>" class="button button-small">
										<?php echo esc_html__( 'Details', 'wp-analytics' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Pagination -->
				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %s: number of items */
									esc_html( _n( '%s item', '%s items', $total_items, 'wp-analytics' ) ),
									esc_html( number_format_i18n( $total_items ) )
								);
								?>
							</span>
							<span class="pagination-links">
								<?php echo self::render_pagination( $paged, $total_pages, $search, $range, $orderby, $order ); ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a sortable table header.
	 *
	 * @param string $column  Column key.
	 * @param string $label   Column label.
	 * @param string $current Current orderby.
	 * @param string $order   Current order.
	 * @param string $search  Current search.
	 * @param string $range   Current range.
	 * @return string HTML for the header.
	 */
	private static function render_sortable_header( string $column, string $label, string $current, string $order, string $search, string $range ): string {
		$is_current  = ( $column === $current );
		$new_order   = ( $is_current && $order === 'ASC' ) ? 'DESC' : 'ASC';
		$class       = $is_current ? 'sorted ' . strtolower( $order ) : 'sortable asc';

		$url = add_query_arg(
			array(
				'page'    => 'wp-analytics-pages',
				'orderby' => $column,
				'order'   => $new_order,
				's'       => $search,
				'range'   => $range,
			),
			admin_url( 'admin.php' )
		);

		$arrow = '';
		if ( $is_current ) {
			$arrow = $order === 'ASC' ? ' ▲' : ' ▼';
		}

		return sprintf(
			'<th class="%s" style="cursor: pointer;"><a href="%s">%s%s</a></th>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label ),
			$arrow
		);
	}

	/**
	 * Render pagination links.
	 *
	 * @param int    $current Current page.
	 * @param int    $total   Total pages.
	 * @param string $search  Current search.
	 * @param string $range   Current range.
	 * @param string $orderby Current orderby.
	 * @param string $order   Current order.
	 * @return string HTML for pagination.
	 */
	private static function render_pagination( int $current, int $total, string $search, string $range, string $orderby, string $order ): string {
		$base_args = array(
			'page'    => 'wp-analytics-pages',
			's'       => $search,
			'range'   => $range,
			'orderby' => $orderby,
			'order'   => $order,
		);

		$output = '';

		// First page
		if ( $current > 1 ) {
			$url     = add_query_arg( array_merge( $base_args, array( 'paged' => 1 ) ), admin_url( 'admin.php' ) );
			$output .= sprintf( '<a class="first-page button" href="%s">«</a> ', esc_url( $url ) );
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled">«</span> ';
		}

		// Previous page
		if ( $current > 1 ) {
			$url     = add_query_arg( array_merge( $base_args, array( 'paged' => $current - 1 ) ), admin_url( 'admin.php' ) );
			$output .= sprintf( '<a class="prev-page button" href="%s">‹</a> ', esc_url( $url ) );
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled">‹</span> ';
		}

		// Page indicator
		$output .= sprintf(
			'<span class="paging-input">%d / %d</span> ',
			$current,
			$total
		);

		// Next page
		if ( $current < $total ) {
			$url     = add_query_arg( array_merge( $base_args, array( 'paged' => $current + 1 ) ), admin_url( 'admin.php' ) );
			$output .= sprintf( '<a class="next-page button" href="%s">›</a> ', esc_url( $url ) );
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled">›</span> ';
		}

		// Last page
		if ( $current < $total ) {
			$url     = add_query_arg( array_merge( $base_args, array( 'paged' => $total ) ), admin_url( 'admin.php' ) );
			$output .= sprintf( '<a class="last-page button" href="%s">»</a>', esc_url( $url ) );
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled">»</span>';
		}

		return $output;
	}

	/**
	 * Get date range from range key.
	 *
	 * @param string $range Range key.
	 * @return array{start: string, end: string}
	 */
	private static function get_date_range( string $range ): array {
		$end_date = gmdate( 'Y-m-d' );

		switch ( $range ) {
			case '7days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;
			case '90days':
				$start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
				break;
			case 'year':
				$start_date = gmdate( 'Y-m-d', strtotime( '-365 days' ) );
				break;
			case 'all':
				$start_date = '2000-01-01';
				break;
			default:
				$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}

		return array(
			'start' => $start_date,
			'end'   => $end_date,
		);
	}

	/**
	 * Format duration in seconds to human readable.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
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
	 * @param string $path   Path to truncate.
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
