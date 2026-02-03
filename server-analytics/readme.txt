=== Server Analytics (Pageviews + Engagement) ===
Contributors: (generated)
Tags: analytics, pageviews, engagement
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Collects server-side analytics for pageviews, referrers, link clicks, time on page, and scroll depth, and provides an accessible dashboard report with filtering, sorting, and CSV/PDF exports.

== Installation ==

1. Copy the `server-analytics/` folder into `wp-content/plugins/`.
2. Activate "Server Analytics (Pageviews + Engagement)" in Plugins.
3. Visit Dashboard → Server Analytics.

== What’s Collected ==

- Pageview (page URL)
- Referrer URL (when available)
- Link clicked on page (captured as link click events)
- Date/time (UTC)
- IP address (as seen by the server)
- Time on page (seconds)
- Scroll depth (max %)

== Permissions ==

Admins and Editors can view/export by default (capability: `edit_pages`).
Override via:

	add_filter('sa_view_analytics_capability', fn() => 'manage_options');

== Notes ==

- Engagement is recorded on `pagehide`/when the tab is hidden (best-effort).
- PDF export is a dependency-free, single-page text PDF intended for simple reporting.

