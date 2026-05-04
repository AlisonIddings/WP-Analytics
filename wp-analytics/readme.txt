=== WP Analytics ===
Contributors: AlisonIddings
Donate link: https://alisoniddings.com
Tags: analytics, pageviews, engagement, statistics, tracking, conversions
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-focused, server-side analytics for WordPress. Track pageviews, engagement, and conversions without external services.

== Description ==

WP Analytics is a lightweight, privacy-focused analytics plugin that collects pageview and engagement data entirely on your own server. No data is sent to third-party services.

**Features:**

* **Analytics Overview Dashboard** - Visual charts and top pages at a glance
* **Traffic Trends** - Month-over-month and year-over-year comparison charts
* **Top Pages Report** - See your most visited pages with engagement metrics
* Track pageviews, referrers, and link clicks
* Measure time on page and scroll depth
* **Custom conversion tracking** - Track button clicks by ID/class and thank you page URLs
* Filter and sort analytics in a detailed event log
* Export data to CSV or PDF
* Exclude specific post types or URLs from tracking
* Whitelist mode to only track specific URLs
* GDPR-friendly with IP anonymization (enabled by default)
* **Efficient long-term storage** - Daily aggregates for fast historical queries
* Automatic data retention and cleanup
* Rate limiting to prevent abuse
* No external dependencies or tracking scripts from third parties

**Privacy First:**

* IP addresses are anonymized by default (last octet removed for IPv4, last 80 bits for IPv6)
* Automatic data deletion after configurable retention period (default: 90 days)
* All data stays on your server
* Integrates with WordPress Privacy Policy page
* No cookies used - session tracking via localStorage only

**For Developers:**

Override the capability required to view analytics:

`add_filter( 'wpa_view_analytics_capability', function() { return 'manage_options'; } );`

== Installation ==

1. Upload the `wp-analytics` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Visit Dashboard → WP Analytics to view your data
4. Configure tracking options under WP Analytics → Settings

The plugin starts collecting data automatically upon activation.

== Frequently Asked Questions ==

= Does this plugin use cookies? =

No, this plugin does not use cookies. It uses browser localStorage to maintain a session identifier for grouping pageviews.

= Is this plugin GDPR compliant? =

The plugin is designed with privacy in mind. IP addresses are anonymized by default, data is automatically deleted after a retention period, and no data is shared with third parties. However, you should still disclose analytics collection in your privacy policy. The plugin automatically suggests text for your Privacy Policy page.

= Where is the data stored? =

All analytics data is stored in your WordPress database in a custom table (`{prefix}_wpa_events`). No data is sent to external servers.

= How do I export my data? =

Visit Dashboard → WP Analytics and use the "Export CSV" or "Export PDF" buttons. You can filter the data before exporting.

= Can I disable IP tracking entirely? =

IP addresses are already anonymized by default. The anonymized IP helps with basic geographic insights while protecting visitor privacy.

= How do I change the data retention period? =

Go to Dashboard → WP Analytics → Settings and adjust the "Data Retention" setting. The default is 90 days. Set to 0 to keep data indefinitely (not recommended for GDPR compliance).

= How do I track button clicks as conversions? =

Go to Dashboard → WP Analytics → Settings and scroll to "Conversion Tracking". Add the HTML ID of buttons you want to track. For example, if your button is `<button id="buy-now">`, enter `buy-now` as the Button ID with a friendly name like "Purchase Button".

= Can I exclude certain pages from tracking? =

Yes! Go to Settings and you can:

* Exclude specific post types (e.g., don't track attachment pages)
* Exclude URLs matching patterns (e.g., `/cart/*` to exclude cart pages)
* Use "whitelist mode" to only track specific URLs

= Can I exclude my own visits from being tracked? =

Yes! Go to Settings → Privacy Settings and add your IP address to the "Exclude IP Addresses" field. Your current IP is displayed with an "Add My IP" button for convenience. You can also use CIDR notation (e.g., `192.168.1.0/24`) to exclude entire IP ranges.

= What capabilities are required to view analytics? =

By default, users with the `edit_pages` capability (Editors and above) can view and export analytics. You can change this with the `wpa_view_analytics_capability` filter.

== Screenshots ==

1. Analytics dashboard with filtering and sorting
2. Settings page for tracking configuration
3. Conversion tracking setup
4. Mobile-responsive data table

== Changelog ==

= 1.2.1 =
* **New IP exclusion feature** - Exclude your own IP from being tracked
* Added "Add My IP" button for easy self-exclusion
* Support for CIDR notation to exclude IP ranges (e.g., 192.168.1.0/24)
* Enhanced security with SRI hash for CDN scripts
* Stricter date validation using checkdate()
* Improved SQL parameter handling

= 1.3.0 =
* **Enhanced Conversion Tracking**: Track conversions via button clicks (IDs and classes) and thank you page URLs
* **Dedicated Conversions Page**: New submenu with conversion statistics, recent conversions, and goal configuration
* **Sortable Top Pages Table**: Click column headers to sort by pageviews, sessions, time, scroll depth, or conversions
* **Page Analytics**: Click any page in Top Pages to see detailed stats, trends, sessions, and outbound links
* **Session Journey**: View complete user journeys showing every page and action in a session
* Moved conversion configuration to dedicated Conversions page for better organization
* Improved conversion data storage format to support multiple conversion types

= 1.2.0 =
* **New Analytics Overview page** with summary statistics cards
* Added traffic trends chart with month-over-month and year-over-year views
* Added top pages report showing pageviews, sessions, and conversions
* Improved UI with modern card-based design
* URLs now display as relative paths (cleaner, more readable)
* Added efficient daily stats aggregation for long-term data storage
* Stats are retained 3x longer than raw events (respects compliance settings)
* Better mobile responsive design throughout
* Event Log moved to dedicated submenu page
* Performance improvements for large datasets

= 1.1.0 =
* Added custom conversion tracking for buttons by element ID
* Added settings page for tracking configuration
* Added option to exclude specific post types from tracking
* Added URL exclusion patterns
* Added whitelist mode to only track specific URLs
* Added bulk delete and date range delete options
* Added delete all data functionality
* Added mobile-responsive event display
* Improved security hardening for all inputs
* Better code documentation and organization

= 1.0.0 =
* Initial release
* Pageview and engagement tracking
* Link click tracking
* Dashboard with filtering and sorting
* CSV and PDF export
* IP anonymization (enabled by default)
* Automatic data retention and cleanup
* Rate limiting for API endpoints
* Privacy policy integration

== Upgrade Notice ==

= 1.3.0 =
Enhanced conversion tracking with button classes and thank you page URLs. New dedicated Conversions page with statistics. Sortable tables and detailed page/session analytics views.

= 1.2.0 =
Major update: New Analytics Overview dashboard with charts and top pages. Improved UI and efficient long-term storage. Update recommended!

= 1.1.0 =
New features: Conversion tracking, URL filtering, and data management tools. Update recommended for all users.

= 1.0.0 =
Initial release of WP Analytics.
