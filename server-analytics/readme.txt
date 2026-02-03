=== Server Analytics ===
Contributors: yourwordpressusername
Donate link: https://example.com/donate
Tags: analytics, pageviews, engagement, statistics, tracking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-focused, server-side analytics for WordPress. Track pageviews, engagement, and link clicks without external services.

== Description ==

Server Analytics is a lightweight, privacy-focused analytics plugin that collects pageview and engagement data entirely on your own server. No data is sent to third-party services.

**Features:**

* Track pageviews, referrers, and link clicks
* Measure time on page and scroll depth
* **Custom conversion tracking** - Track button clicks by element ID
* Filter and sort analytics in a dashboard
* Export data to CSV or PDF
* Exclude specific post types or URLs from tracking
* Whitelist mode to only track specific URLs
* GDPR-friendly with IP anonymization (enabled by default)
* Automatic data retention and cleanup
* Rate limiting to prevent abuse
* No external dependencies or tracking scripts from third parties

**Privacy First:**

* IP addresses are anonymized by default (last octet removed for IPv4, last 80 bits for IPv6)
* Automatic data deletion after configurable retention period (default: 90 days)
* All data stays on your server
* Integrates with WordPress Privacy Policy page

**For Developers:**

Override the capability required to view analytics:

`add_filter('sa_view_analytics_capability', fn() => 'manage_options');`

== Installation ==

1. Upload the `server-analytics` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Visit Dashboard → Server Analytics to view your data

The plugin starts collecting data automatically upon activation.

== Frequently Asked Questions ==

= Does this plugin use cookies? =

No, this plugin does not use cookies. It uses browser localStorage to maintain a session identifier for grouping pageviews.

= Is this plugin GDPR compliant? =

The plugin is designed with privacy in mind. IP addresses are anonymized by default, data is automatically deleted after a retention period, and no data is shared with third parties. However, you should still disclose analytics collection in your privacy policy. The plugin automatically suggests text for your Privacy Policy page.

= Where is the data stored? =

All analytics data is stored in your WordPress database in a custom table (`{prefix}_sa_events`). No data is sent to external servers.

= How do I export my data? =

Visit Dashboard → Server Analytics and use the "Export CSV" or "Export PDF" buttons. You can filter the data before exporting.

= Can I disable IP tracking entirely? =

IP addresses are already anonymized by default. The anonymized IP helps with basic geographic insights while protecting visitor privacy.

= How do I change the data retention period? =

Go to Dashboard → Server Analytics → Settings and adjust the "Data Retention" setting. The default is 90 days. Set to 0 to keep data indefinitely (not recommended for GDPR compliance).

= How do I track button clicks as conversions? =

Go to Dashboard → Server Analytics → Settings and scroll to "Conversion Tracking". Add the HTML ID of buttons you want to track. For example, if your button is `<button id="buy-now">`, enter `buy-now` as the Button ID with a friendly name like "Purchase Button".

= Can I exclude certain pages from tracking? =

Yes! Go to Settings and you can:

* Exclude specific post types (e.g., don't track attachment pages)
* Exclude URLs matching patterns (e.g., `/cart/*` to exclude cart pages)
* Use "whitelist mode" to only track specific URLs

= What capabilities are required to view analytics? =

By default, users with the `edit_pages` capability (Editors and above) can view and export analytics. You can change this with the `sa_view_analytics_capability` filter.

== Screenshots ==

1. Analytics dashboard with filtering and sorting
2. CSV export of analytics data
3. PDF report generation

== Changelog ==

= 1.1.0 =
* Added custom conversion tracking for buttons by element ID
* Added settings page for tracking configuration
* Added option to exclude specific post types from tracking
* Added URL exclusion patterns
* Added whitelist mode to only track specific URLs
* Added bulk delete and date range delete options
* Added delete all data functionality
* Improved security hardening for all inputs

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

= 1.1.0 =
New features: Conversion tracking, URL filtering, and data management tools. Update recommended for all users.

= 1.0.0 =
Initial release of Server Analytics.
