<?php
/**
 * Admin interface handler for WP Analytics.
 *
 * Manages the WordPress admin dashboard pages including:
 * - Analytics dashboard with filtering and sorting
 * - Settings page for configuration
 * - Export functionality (CSV/PDF)
 * - Data management (delete, cleanup)
 *
 * @package WP_Analytics
 * @since 1.0.0
 */

declare(strict_types=1);

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPA_Admin
 *
 * Handles all WordPress admin functionality for WP Analytics.
 *
 * @since 1.0.0
 */
final class WPA_Admin {

	/** @var string Menu slug for admin pages */
	private const MENU_SLUG = 'wp-analytics';

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register admin menu pages
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

		// Enqueue admin styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Export handlers
		add_action( 'admin_post_wpa_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_wpa_export_pdf', array( __CLASS__, 'export_pdf' ) );

		// Delete handlers
		add_action( 'admin_post_wpa_delete_by_date', array( __CLASS__, 'handle_delete_by_date' ) );
		add_action( 'admin_post_wpa_delete_all', array( __CLASS__, 'handle_delete_all' ) );

		// Settings handler
		add_action( 'admin_post_wpa_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * Creates the main WP Analytics menu and subpages:
	 * - Overview (charts and trends)
	 * - Events (detailed event log)
	 * - Settings (configuration options)
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		// Main menu page - Analytics Overview
		add_menu_page(
			__( 'WP Analytics', 'wp-analytics' ),
			__( 'WP Analytics', 'wp-analytics' ),
			wpa_view_analytics_capability(),
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview_page' ),
			'dashicons-chart-area',
			65
		);

		// Overview submenu (same as main)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics Overview', 'wp-analytics' ),
			__( 'Overview', 'wp-analytics' ),
			wpa_view_analytics_capability(),
			self::MENU_SLUG,
			array( __CLASS__, 'render_overview_page' )
		);

		// Events log submenu
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Event Log', 'wp-analytics' ),
			__( 'Event Log', 'wp-analytics' ),
			wpa_view_analytics_capability(),
			self::MENU_SLUG . '-events',
			array( __CLASS__, 'render_page' )
		);

		// Settings submenu (requires admin capability)
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wp-analytics' ),
			__( 'Settings', 'wp-analytics' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render the analytics overview page.
	 *
	 * @return void
	 */
	public static function render_overview_page(): void {
		self::load_analytics_class();
		WPA_Analytics::render_page();
	}

	/**
	 * Enqueue admin CSS styles.
	 *
	 * Only loads on WP Analytics admin pages.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		// Only load on our admin pages
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wpa-admin',
			WPA_PLUGIN_URL . 'assets/css/wpa-admin.css',
			array(),
			WPA_PLUGIN_VERSION
		);
	}

	/**
	 * Load the analytics class on demand.
	 *
	 * @return void
	 */
	private static function load_analytics_class(): void {
		if ( ! class_exists( 'WPA_Analytics' ) ) {
			require_once WPA_PLUGIN_DIR . 'includes/class-wpa-analytics.php';
		}
	}

	/**
	 * Get current filter values from URL parameters.
	 *
	 * @return array<string, mixed> Filter values
	 */
	private static function current_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
		if ( strlen( $search ) > 200 ) {
			$search = substr( $search, 0, 200 );
		}

		return array(
			'event_type' => isset( $_GET['event_type'] ) ? sanitize_key( (string) $_GET['event_type'] ) : '',
			'date_from'  => isset( $_GET['date_from'] ) ? sanitize_text_field( (string) $_GET['date_from'] ) : '',
			'date_to'    => isset( $_GET['date_to'] ) ? sanitize_text_field( (string) $_GET['date_to'] ) : '',
			's'          => $search,
		);
		// phpcs:enable
	}

	/**
	 * Render the main analytics dashboard page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// Check permissions
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wp-analytics' ) );
		}

		// Process any pending delete actions
		self::process_delete_actions();

		// Load the list table class
		self::load_list_table_class();

		// Initialize and prepare the data table
		$filters = self::current_filters();
		$table   = new WPA_List_Table( $filters );
		$table->prepare_items();

		// Build export URLs
		$base_url    = menu_page_url( self::MENU_SLUG, false );
		$export_args = array_filter(
			array(
				'page'       => self::MENU_SLUG,
				'event_type' => $filters['event_type'],
				'date_from'  => $filters['date_from'],
				'date_to'    => $filters['date_to'],
				's'          => $filters['s'],
			),
			static fn( $v ): bool => $v !== '' && $v !== null
		);

		$csv_url = wp_nonce_url(
			add_query_arg( $export_args, admin_url( 'admin-post.php?action=wpa_export_csv' ) ),
			'wpa_export'
		);
		$pdf_url = wp_nonce_url(
			add_query_arg( $export_args, admin_url( 'admin-post.php?action=wpa_export_pdf' ) ),
			'wpa_export'
		);

		$total_events = WPA_Database::get_total_count();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Analytics', 'wp-analytics' ); ?></h1>

			<?php self::render_admin_notices(); ?>

			<!-- Filter Form -->
			<form method="get" action="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />

				<fieldset class="wpa-filters" aria-label="<?php echo esc_attr__( 'Analytics filters', 'wp-analytics' ); ?>">
					<!-- Event Type Filter -->
					<div class="wpa-field">
						<label for="wpa-event-type"><?php echo esc_html__( 'Event Type', 'wp-analytics' ); ?></label>
						<select id="wpa-event-type" name="event_type">
							<?php
							$types = array(
								''           => __( 'All Events', 'wp-analytics' ),
								'pageview'   => __( 'Pageview', 'wp-analytics' ),
								'link_click' => __( 'Link Click', 'wp-analytics' ),
								'conversion' => __( 'Conversion', 'wp-analytics' ),
							);
							foreach ( $types as $value => $label ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $value ),
									selected( $filters['event_type'], $value, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</div>

					<!-- Date Range Filters -->
					<div class="wpa-field">
						<label for="wpa-date-from"><?php echo esc_html__( 'From Date', 'wp-analytics' ); ?></label>
						<input id="wpa-date-from" name="date_from" type="date" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
					</div>

					<div class="wpa-field">
						<label for="wpa-date-to"><?php echo esc_html__( 'To Date', 'wp-analytics' ); ?></label>
						<input id="wpa-date-to" name="date_to" type="date" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
					</div>

					<!-- Search Filter -->
					<div class="wpa-field" style="flex: 1 1 260px;">
						<label for="wpa-search"><?php echo esc_html__( 'Search', 'wp-analytics' ); ?></label>
						<input id="wpa-search" name="s" type="search" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php echo esc_attr__( 'URL or IP address...', 'wp-analytics' ); ?>" />
					</div>

					<!-- Apply Button -->
					<div class="wpa-field">
						<label class="screen-reader-text" for="wpa-submit"><?php echo esc_html__( 'Apply filters', 'wp-analytics' ); ?></label>
						<button id="wpa-submit" class="button button-primary" type="submit"><?php echo esc_html__( 'Apply', 'wp-analytics' ); ?></button>
					</div>
				</fieldset>
			</form>

			<!-- Export Buttons -->
			<div class="wpa-actions" aria-label="<?php echo esc_attr__( 'Export actions', 'wp-analytics' ); ?>">
				<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php echo esc_html__( 'Export CSV', 'wp-analytics' ); ?></a>
				<a class="button" href="<?php echo esc_url( $pdf_url ); ?>"><?php echo esc_html__( 'Export PDF', 'wp-analytics' ); ?></a>
			</div>

			<!-- Data Table with Bulk Actions -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
				<?php wp_nonce_field( 'wpa_bulk_action', 'wpa_bulk_nonce' ); ?>
				<input type="hidden" name="wpa_bulk_action" value="1" />
				<?php $table->display(); ?>
			</form>

			<hr style="margin: 30px 0;" />

			<!-- Data Management Section -->
			<h2><?php echo esc_html__( 'Data Management', 'wp-analytics' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of events */
					esc_html__( 'Total events in database: %s', 'wp-analytics' ),
					'<strong>' . esc_html( number_format_i18n( $total_events ) ) . '</strong>'
				);
				?>
			</p>

			<div class="wpa-data-management" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
				<!-- Delete by Date Range -->
				<div class="wpa-delete-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 300px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__( 'Delete by Date Range', 'wp-analytics' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wpa_delete_by_date', 'wpa_delete_date_nonce' ); ?>
						<input type="hidden" name="action" value="wpa_delete_by_date" />
						<p>
							<label for="wpa-delete-from"><?php echo esc_html__( 'From:', 'wp-analytics' ); ?></label><br />
							<input type="date" id="wpa-delete-from" name="delete_from" required />
						</p>
						<p>
							<label for="wpa-delete-to"><?php echo esc_html__( 'To:', 'wp-analytics' ); ?></label><br />
							<input type="date" id="wpa-delete-to" name="delete_to" required />
						</p>
						<p>
							<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete events in this date range? This cannot be undone.', 'wp-analytics' ) ); ?>');">
								<?php echo esc_html__( 'Delete by Date Range', 'wp-analytics' ); ?>
							</button>
						</p>
					</form>
				</div>

				<!-- Delete All Data -->
				<div class="wpa-delete-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 300px;">
					<h3 style="margin-top: 0;"><?php echo esc_html__( 'Delete All Data', 'wp-analytics' ); ?></h3>
					<p class="description" style="color: #d63638;">
						<?php echo esc_html__( 'Warning: This will permanently delete all analytics data. This action cannot be undone.', 'wp-analytics' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wpa_delete_all', 'wpa_delete_all_nonce' ); ?>
						<input type="hidden" name="action" value="wpa_delete_all" />
						<p>
							<label>
								<input type="checkbox" name="confirm_delete_all" value="1" required />
								<?php echo esc_html__( 'I understand this will delete all data', 'wp-analytics' ); ?>
							</label>
						</p>
						<p>
							<button type="submit" class="button" style="background: #d63638; border-color: #d63638; color: #fff;" onclick="return confirm('<?php echo esc_js( __( 'Are you ABSOLUTELY sure? ALL analytics data will be permanently deleted!', 'wp-analytics' ) ); ?>');">
								<?php echo esc_html__( 'Delete All Data', 'wp-analytics' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Process single and bulk delete actions from the list table.
	 *
	 * @return void
	 */
	private static function process_delete_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		// Handle single event deletion
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['event'] ) ) {
			$event_id = absint( $_GET['event'] );
			if ( $event_id > 0 && check_admin_referer( 'wpa_delete_event_' . $event_id ) ) {
				if ( WPA_Database::delete_event( $event_id ) ) {
					set_transient(
						'wpa_admin_notice',
						array(
							'type'    => 'success',
							'message' => __( 'Event deleted successfully.', 'wp-analytics' ),
						),
						30
					);
				} else {
					set_transient(
						'wpa_admin_notice',
						array(
							'type'    => 'error',
							'message' => __( 'Failed to delete event.', 'wp-analytics' ),
						),
						30
					);
				}
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				exit;
			}
		}

		// phpcs:enable

		// Handle bulk deletion
		if ( isset( $_POST['wpa_bulk_action'] ) && isset( $_POST['wpa_bulk_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpa_bulk_nonce'] ) ), 'wpa_bulk_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-analytics' ) );
			}

			$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
			if ( $action === '-1' && isset( $_POST['action2'] ) ) {
				$action = sanitize_key( $_POST['action2'] );
			}

			if ( $action === 'bulk_delete' && ! empty( $_POST['event_ids'] ) ) {
				$ids     = array_map( 'absint', (array) $_POST['event_ids'] );
				$deleted = WPA_Database::delete_events( $ids );

				if ( $deleted > 0 ) {
					set_transient(
						'wpa_admin_notice',
						array(
							'type'    => 'success',
							'message' => sprintf(
								/* translators: %d: number of deleted events */
								_n( '%d event deleted successfully.', '%d events deleted successfully.', $deleted, 'wp-analytics' ),
								$deleted
							),
						),
						30
					);
				} else {
					set_transient(
						'wpa_admin_notice',
						array(
							'type'    => 'error',
							'message' => __( 'No events were deleted.', 'wp-analytics' ),
						),
						30
					);
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				exit;
			}
		}
	}

	/**
	 * Handle delete by date range form submission.
	 *
	 * @return void
	 */
	public static function handle_delete_by_date(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to delete analytics.', 'wp-analytics' ) );
		}

		check_admin_referer( 'wpa_delete_by_date', 'wpa_delete_date_nonce' );

		$date_from = isset( $_POST['delete_from'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_from'] ) ) : '';
		$date_to   = isset( $_POST['delete_to'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_to'] ) ) : '';

		if ( $date_from === '' || $date_to === '' ) {
			set_transient(
				'wpa_admin_notice',
				array(
					'type'    => 'error',
					'message' => __( 'Please provide both start and end dates.', 'wp-analytics' ),
				),
				30
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		$deleted = WPA_Database::delete_events_by_date( $date_from, $date_to );

		if ( $deleted > 0 ) {
			set_transient(
				'wpa_admin_notice',
				array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %d: number of deleted events */
						_n( '%d event deleted successfully.', '%d events deleted successfully.', $deleted, 'wp-analytics' ),
						$deleted
					),
				),
				30
			);
		} else {
			set_transient(
				'wpa_admin_notice',
				array(
					'type'    => 'info',
					'message' => __( 'No events found in the specified date range.', 'wp-analytics' ),
				),
				30
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Handle delete all data form submission.
	 *
	 * @return void
	 */
	public static function handle_delete_all(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to delete analytics.', 'wp-analytics' ) );
		}

		check_admin_referer( 'wpa_delete_all', 'wpa_delete_all_nonce' );

		if ( empty( $_POST['confirm_delete_all'] ) ) {
			set_transient(
				'wpa_admin_notice',
				array(
					'type'    => 'error',
					'message' => __( 'You must confirm that you want to delete all data.', 'wp-analytics' ),
				),
				30
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		$deleted = WPA_Database::delete_all_data();

		set_transient(
			'wpa_admin_notice',
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: number of deleted events */
					_n( '%d event deleted. All analytics data has been removed.', '%d events deleted. All analytics data has been removed.', $deleted, 'wp-analytics' ),
					$deleted
				),
			),
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-analytics' ) );
		}

		// Get current settings
		$tracking_mode       = WPA_Database::get_tracking_mode();
		$excluded_post_types = WPA_Database::get_excluded_post_types();
		$excluded_urls       = WPA_Database::get_excluded_urls_raw();
		$included_urls       = WPA_Database::get_included_urls_raw();
		$excluded_ips        = WPA_Database::get_excluded_ips_raw();
		$anonymize_ip        = WPA_Database::is_ip_anonymization_enabled();
		$retention_days      = WPA_Database::get_data_retention_days();
		$conversion_buttons  = WPA_Database::get_conversion_buttons();

		// Get the current user's IP for display
		$current_ip = self::get_current_user_ip();

		// Get all public post types for the exclusion list
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Analytics Settings', 'wp-analytics' ); ?></h1>

			<?php self::render_admin_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpa_save_settings', 'wpa_settings_nonce' ); ?>
				<input type="hidden" name="action" value="wpa_save_settings" />

				<!-- Tracking Settings Section -->
				<h2><?php echo esc_html__( 'Tracking Settings', 'wp-analytics' ); ?></h2>

				<table class="form-table" role="presentation">
					<!-- Tracking Mode -->
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tracking Mode', 'wp-analytics' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="tracking_mode" value="all" <?php checked( $tracking_mode, 'all' ); ?> />
									<?php echo esc_html__( 'Track all pages (exclude specified)', 'wp-analytics' ); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="tracking_mode" value="whitelist" <?php checked( $tracking_mode, 'whitelist' ); ?> />
									<?php echo esc_html__( 'Only track specified pages (whitelist mode)', 'wp-analytics' ); ?>
								</label>
								<p class="description">
									<?php echo esc_html__( 'Choose whether to track all pages with exclusions, or only track specific pages.', 'wp-analytics' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<!-- Excluded Post Types -->
					<tr>
						<th scope="row">
							<label for="excluded_post_types"><?php echo esc_html__( 'Exclude Post Types', 'wp-analytics' ); ?></label>
						</th>
						<td>
							<select name="excluded_post_types[]" id="excluded_post_types" multiple size="8" style="min-width: 300px; height: auto;">
								<?php foreach ( $post_types as $post_type ) : ?>
									<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, $excluded_post_types, true ) ); ?>>
										<?php echo esc_html( $post_type->labels->name . ' (' . $post_type->name . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Hold Ctrl/Cmd to select multiple. Pages of these post types will not be tracked.', 'wp-analytics' ); ?>
							</p>
						</td>
					</tr>

					<!-- Excluded URLs -->
					<tr>
						<th scope="row">
							<label for="excluded_urls"><?php echo esc_html__( 'Exclude URLs', 'wp-analytics' ); ?></label>
						</th>
						<td>
							<textarea name="excluded_urls" id="excluded_urls" rows="6" cols="50" class="large-text code"><?php echo esc_textarea( $excluded_urls ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Enter one URL pattern per line. Use * as wildcard.', 'wp-analytics' ); ?><br />
								<?php echo esc_html__( 'Examples:', 'wp-analytics' ); ?><br />
								<code>/wp-admin/*</code> - <?php echo esc_html__( 'Excludes all admin pages', 'wp-analytics' ); ?><br />
								<code>/cart/</code> - <?php echo esc_html__( 'Excludes URLs containing /cart/', 'wp-analytics' ); ?>
							</p>
						</td>
					</tr>

					<!-- Included URLs (Whitelist) -->
					<tr>
						<th scope="row">
							<label for="included_urls"><?php echo esc_html__( 'Only Track URLs (Whitelist)', 'wp-analytics' ); ?></label>
						</th>
						<td>
							<textarea name="included_urls" id="included_urls" rows="6" cols="50" class="large-text code"><?php echo esc_textarea( $included_urls ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Only used when "Whitelist mode" is selected above.', 'wp-analytics' ); ?><br />
								<?php echo esc_html__( 'Enter one URL pattern per line. Use * as wildcard.', 'wp-analytics' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<!-- Conversion Tracking Section -->
				<h2><?php echo esc_html__( 'Conversion Tracking', 'wp-analytics' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Track button clicks as conversions by specifying their HTML element IDs.', 'wp-analytics' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tracked Buttons', 'wp-analytics' ); ?></th>
						<td>
							<div id="wpa-conversion-buttons">
								<?php if ( empty( $conversion_buttons ) ) : ?>
									<div class="wpa-button-row" data-index="0">
										<input type="text" name="conversion_buttons[0][id]" placeholder="<?php echo esc_attr__( 'Button ID (e.g., buy-now-btn)', 'wp-analytics' ); ?>" class="regular-text" />
										<input type="text" name="conversion_buttons[0][name]" placeholder="<?php echo esc_attr__( 'Friendly Name (e.g., Buy Now)', 'wp-analytics' ); ?>" class="regular-text" />
										<label>
											<input type="checkbox" name="conversion_buttons[0][enabled]" value="1" checked />
											<?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?>
										</label>
										<button type="button" class="button wpa-remove-button"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
									</div>
								<?php else : ?>
									<?php foreach ( $conversion_buttons as $index => $button ) : ?>
										<div class="wpa-button-row" data-index="<?php echo esc_attr( $index ); ?>">
											<input type="text" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $button['id'] ); ?>" placeholder="<?php echo esc_attr__( 'Button ID', 'wp-analytics' ); ?>" class="regular-text" />
											<input type="text" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $button['name'] ); ?>" placeholder="<?php echo esc_attr__( 'Friendly Name', 'wp-analytics' ); ?>" class="regular-text" />
											<label>
												<input type="checkbox" name="conversion_buttons[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $button['enabled'] ); ?> />
												<?php echo esc_html__( 'Enabled', 'wp-analytics' ); ?>
											</label>
											<button type="button" class="button wpa-remove-button"><?php echo esc_html__( 'Remove', 'wp-analytics' ); ?></button>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<p>
								<button type="button" class="button" id="wpa-add-button"><?php echo esc_html__( 'Add Button', 'wp-analytics' ); ?></button>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Enter the HTML ID attribute of buttons you want to track (without the # symbol).', 'wp-analytics' ); ?><br />
								<?php
								printf(
									/* translators: %1$s: opening code tag, %2$s: closing code tag */
									esc_html__( 'Example: If your button is %1$s<button id="purchase-btn">%2$s, enter %1$spurchase-btn%2$s', 'wp-analytics' ),
									'<code>',
									'</code>'
								);
								?>
							</p>

							<script>
							(function() {
								var container = document.getElementById('wpa-conversion-buttons');
								var addBtn = document.getElementById('wpa-add-button');
								var index = <?php echo count( $conversion_buttons ) > 0 ? count( $conversion_buttons ) : 1; ?>;

								addBtn.addEventListener('click', function() {
									var row = document.createElement('div');
									row.className = 'wpa-button-row';
									row.dataset.index = index;
									row.innerHTML = '<input type="text" name="conversion_buttons[' + index + '][id]" placeholder="<?php echo esc_js( __( 'Button ID', 'wp-analytics' ) ); ?>" class="regular-text" /> ' +
										'<input type="text" name="conversion_buttons[' + index + '][name]" placeholder="<?php echo esc_js( __( 'Friendly Name', 'wp-analytics' ) ); ?>" class="regular-text" /> ' +
										'<label><input type="checkbox" name="conversion_buttons[' + index + '][enabled]" value="1" checked /> <?php echo esc_js( __( 'Enabled', 'wp-analytics' ) ); ?></label> ' +
										'<button type="button" class="button wpa-remove-button"><?php echo esc_js( __( 'Remove', 'wp-analytics' ) ); ?></button>';
									container.appendChild(row);
									index++;
								});

								container.addEventListener('click', function(e) {
									if (e.target.classList.contains('wpa-remove-button')) {
										e.target.closest('.wpa-button-row').remove();
									}
								});
							})();
							</script>
							<style>
								.wpa-button-row { margin-bottom: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
								.wpa-button-row input[type="text"] { max-width: 250px; }
							</style>
						</td>
					</tr>
				</table>

				<!-- Privacy Settings Section -->
				<h2><?php echo esc_html__( 'Privacy Settings', 'wp-analytics' ); ?></h2>

				<table class="form-table" role="presentation">
					<!-- Excluded IPs -->
					<tr>
						<th scope="row">
							<label for="excluded_ips"><?php echo esc_html__( 'Exclude IP Addresses', 'wp-analytics' ); ?></label>
						</th>
						<td>
							<textarea name="excluded_ips" id="excluded_ips" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $excluded_ips ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Enter one IP address per line. These IPs will not be tracked.', 'wp-analytics' ); ?><br />
								<?php echo esc_html__( 'Supports CIDR notation for ranges (e.g., 192.168.1.0/24).', 'wp-analytics' ); ?><br />
								<?php echo esc_html__( 'Lines starting with # are treated as comments.', 'wp-analytics' ); ?>
							</p>
							<?php if ( $current_ip !== '' ) : ?>
								<p class="description" style="margin-top: 10px;">
									<strong><?php echo esc_html__( 'Your current IP:', 'wp-analytics' ); ?></strong>
									<code><?php echo esc_html( $current_ip ); ?></code>
									<button type="button" class="button button-small" id="wpa-add-my-ip" style="margin-left: 10px;">
										<?php echo esc_html__( 'Add My IP', 'wp-analytics' ); ?>
									</button>
								</p>
								<script>
								(function() {
									var addBtn = document.getElementById('wpa-add-my-ip');
									var textarea = document.getElementById('excluded_ips');
									var myIp = <?php echo wp_json_encode( $current_ip ); ?>;
									
									if (addBtn && textarea) {
										addBtn.addEventListener('click', function() {
											var current = textarea.value.trim();
											// Check if IP is already in the list
											var lines = current.split('\n');
											for (var i = 0; i < lines.length; i++) {
												if (lines[i].trim() === myIp) {
													alert(<?php echo wp_json_encode( __( 'This IP is already in the list.', 'wp-analytics' ) ); ?>);
													return;
												}
											}
											// Add IP to textarea
											if (current !== '') {
												textarea.value = current + '\n' + myIp;
											} else {
												textarea.value = myIp;
											}
										});
									}
								})();
								</script>
							<?php endif; ?>
						</td>
					</tr>

					<!-- IP Anonymization -->
					<tr>
						<th scope="row"><?php echo esc_html__( 'IP Anonymization', 'wp-analytics' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="anonymize_ip" value="1" <?php checked( $anonymize_ip ); ?> />
								<?php echo esc_html__( 'Anonymize IP addresses (recommended for GDPR compliance)', 'wp-analytics' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'When enabled, the last octet of IPv4 addresses and last 80 bits of IPv6 addresses are removed.', 'wp-analytics' ); ?>
							</p>
						</td>
					</tr>

					<!-- Data Retention -->
					<tr>
						<th scope="row">
							<label for="retention_days"><?php echo esc_html__( 'Data Retention', 'wp-analytics' ); ?></label>
						</th>
						<td>
							<input type="number" name="retention_days" id="retention_days" value="<?php echo esc_attr( $retention_days ); ?>" min="0" max="3650" class="small-text" />
							<?php echo esc_html__( 'days', 'wp-analytics' ); ?>
							<p class="description">
								<?php echo esc_html__( 'Automatically delete data older than this many days. Set to 0 to keep data indefinitely.', 'wp-analytics' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'wp-analytics' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle settings form submission.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'wp-analytics' ) );
		}

		check_admin_referer( 'wpa_save_settings', 'wpa_settings_nonce' );

		// Save tracking mode
		$tracking_mode = isset( $_POST['tracking_mode'] ) ? sanitize_key( wp_unslash( $_POST['tracking_mode'] ) ) : 'all';
		WPA_Database::set_tracking_mode( $tracking_mode );

		// Save excluded post types
		$excluded_post_types = array();
		if ( isset( $_POST['excluded_post_types'] ) && is_array( $_POST['excluded_post_types'] ) ) {
			$excluded_post_types = array_map( 'sanitize_key', wp_unslash( $_POST['excluded_post_types'] ) );
		}
		WPA_Database::set_excluded_post_types( $excluded_post_types );

		// Save excluded URLs
		$excluded_urls = isset( $_POST['excluded_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_urls'] ) ) : '';
		WPA_Database::set_excluded_urls( $excluded_urls );

		// Save included URLs
		$included_urls = isset( $_POST['included_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['included_urls'] ) ) : '';
		WPA_Database::set_included_urls( $included_urls );

		// Save conversion buttons
		$conversion_buttons = array();
		if ( isset( $_POST['conversion_buttons'] ) && is_array( $_POST['conversion_buttons'] ) ) {
			foreach ( $_POST['conversion_buttons'] as $button ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_array( $button ) && ! empty( $button['id'] ) ) {
					$conversion_buttons[] = array(
						'id'      => sanitize_html_class( $button['id'] ),
						'name'    => sanitize_text_field( $button['name'] ?? '' ),
						'enabled' => ! empty( $button['enabled'] ),
					);
				}
			}
		}
		WPA_Database::set_conversion_buttons( $conversion_buttons );

		// Save excluded IPs
		$excluded_ips = isset( $_POST['excluded_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_ips'] ) ) : '';
		WPA_Database::set_excluded_ips( $excluded_ips );

		// Save IP anonymization
		$anonymize_ip = isset( $_POST['anonymize_ip'] ) && $_POST['anonymize_ip'] === '1';
		WPA_Database::set_ip_anonymization( $anonymize_ip );

		// Save data retention
		$retention_days = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 90;
		WPA_Database::set_data_retention_days( $retention_days );

		set_transient(
			'wpa_admin_notice',
			array(
				'type'    => 'success',
				'message' => __( 'Settings saved successfully.', 'wp-analytics' ),
			),
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) );
		exit;
	}

	/**
	 * Render admin notices from transient storage.
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
	 * Verify export access permissions and nonce.
	 *
	 * @return void
	 */
	private static function assert_export_access(): void {
		if ( ! current_user_can( wpa_view_analytics_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export analytics.', 'wp-analytics' ) );
		}
		check_admin_referer( 'wpa_export' );
	}

	/**
	 * Load the list table class on demand.
	 *
	 * @return void
	 */
	private static function load_list_table_class(): void {
		if ( ! class_exists( 'WPA_List_Table' ) ) {
			require_once WPA_PLUGIN_DIR . 'includes/class-wpa-list-table.php';
		}
	}

	/**
	 * Load the PDF class on demand.
	 *
	 * @return void
	 */
	private static function load_pdf_class(): void {
		if ( ! class_exists( 'WPA_PDF' ) ) {
			require_once WPA_PLUGIN_DIR . 'includes/class-wpa-pdf.php';
		}
	}

	/**
	 * Validate a date string is a valid YYYY-MM-DD format and represents a real date.
	 *
	 * @param string $date The date string to validate.
	 * @return bool True if valid date, false otherwise.
	 */
	private static function is_valid_date( string $date ): bool {
		// Must match YYYY-MM-DD format
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}

		// Verify it's a real date (e.g., not 2024-13-45)
		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];

		// Reasonable year range (1970-2100)
		if ( $year < 1970 || $year > 2100 ) {
			return false;
		}

		return checkdate( $month, $day, $year );
	}

	/**
	 * Get the current user's IP address for display in settings.
	 *
	 * @return string The IP address or empty string.
	 */
	private static function get_current_user_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) $_SERVER['REMOTE_ADDR'];
		}

		// Remove any non-IP characters and limit length
		$ip = preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip );

		return is_string( $ip ) ? substr( $ip, 0, 45 ) : '';
	}

	/**
	 * Export analytics data as CSV.
	 *
	 * Streams data in batches to handle large datasets efficiently.
	 *
	 * @return void
	 */
	public static function export_csv(): void {
		self::assert_export_access();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wp-analytics-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			wp_die( esc_html__( 'Unable to generate export.', 'wp-analytics' ) );
		}

		// Write CSV header row
		fputcsv( $out, array( 'created_at', 'event_type', 'page_url', 'referrer_url', 'link_url', 'ip_address', 'time_on_page', 'scroll_depth' ) );

		// Stream data in batches for memory efficiency
		$filters    = self::current_filters();
		$batch_size = 1000;
		$offset     = 0;
		$max_rows   = 50000; // Safety limit

		while ( $offset < $max_rows ) {
			$rows = self::query_export_batch( $filters, $batch_size, $offset );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array(
						(string) ( $row['created_at'] ?? '' ),
						(string) ( $row['event_type'] ?? '' ),
						(string) ( $row['page_url'] ?? '' ),
						(string) ( $row['referrer_url'] ?? '' ),
						(string) ( $row['link_url'] ?? '' ),
						(string) ( $row['ip_address'] ?? '' ),
						(string) ( $row['time_on_page'] ?? '' ),
						(string) ( $row['scroll_depth'] ?? '' ),
					)
				);
			}

			// Flush output buffer
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();

			$offset += $batch_size;

			// If we got less than batch size, we're done
			if ( count( $rows ) < $batch_size ) {
				break;
			}

			unset( $rows );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Query export data in batches for memory efficiency.
	 *
	 * @param array<string, mixed> $filters Filter parameters.
	 * @param int                  $limit   Number of rows per batch.
	 * @param int                  $offset  Offset for pagination.
	 * @return array<int, array<string, mixed>> Query results.
	 */
	private static function query_export_batch( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$table = WPA_Database::table_name();

		$where  = array();
		$params = array();

		// Build WHERE conditions
		$event_type = isset( $filters['event_type'] ) ? sanitize_key( (string) $filters['event_type'] ) : '';
		if ( $event_type !== '' ) {
			$where[]  = 'event_type = %s';
			$params[] = $event_type;
		}

		$date_from = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
		$date_to   = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';

		if ( self::is_valid_date( $date_from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( self::is_valid_date( $date_to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$search = isset( $filters['s'] ) ? sanitize_text_field( (string) $filters['s'] ) : '';
		if ( strlen( $search ) > 200 ) {
			$search = substr( $search, 0, 200 );
		}
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(page_url LIKE %s OR referrer_url LIKE %s OR link_url LIKE %s OR ip_address LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = $where !== array() ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT created_at, event_type, page_url, referrer_url, link_url, ip_address, time_on_page, scroll_depth
			FROM {$table}
			{$where_sql}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Export analytics data as PDF.
	 *
	 * @return void
	 */
	public static function export_pdf(): void {
		self::assert_export_access();

		// Load required classes
		self::load_list_table_class();
		self::load_pdf_class();

		$filters = self::current_filters();
		$table   = new WPA_List_Table( $filters );
		$rows    = $table->get_items_for_export( 500 ); // Limit for memory efficiency

		$headers  = array( 'created_at', 'event_type', 'page_url', 'referrer_url', 'link_url', 'ip_address', 'time_on_page', 'scroll_depth' );
		$pdf_rows = array();

		foreach ( $rows as $row ) {
			$pdf_rows[] = array(
				(string) ( $row['created_at'] ?? '' ),
				(string) ( $row['event_type'] ?? '' ),
				(string) ( $row['page_url'] ?? '' ),
				(string) ( $row['referrer_url'] ?? '' ),
				(string) ( $row['link_url'] ?? '' ),
				(string) ( $row['ip_address'] ?? '' ),
				(string) ( $row['time_on_page'] ?? '' ),
				(string) ( $row['scroll_depth'] ?? '' ),
			);
		}

		$title = 'WP Analytics Export (UTC) - ' . gmdate( 'Y-m-d H:i:s' );
		$pdf   = WPA_PDF::render( $title, $headers, $pdf_rows );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename=wp-analytics-' . gmdate( 'Ymd-His' ) . '.pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
