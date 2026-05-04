/**
 * WP Analytics - Frontend Tracking Script
 *
 * This script collects analytics data and sends it to the WordPress REST API.
 * It tracks pageviews, scroll depth, time on page, link clicks, and conversions.
 *
 * Features:
 * - Lightweight and privacy-focused
 * - No cookies - uses localStorage for session tracking
 * - Respects URL exclusion/inclusion patterns
 * - Tracks conversions via button IDs, classes, and thank you page URLs
 *
 * @package WP_Analytics
 * @since 1.0.0
 */
(function () {
  "use strict";

  // ==========================================================================
  // CONFIGURATION
  // ==========================================================================

  // Get settings injected by WordPress
  var settings = window.wpaTrackerSettings || {};

  // Exit early if not properly configured
  if (!settings.restUrl || !settings.token) {
    return;
  }

  // ==========================================================================
  // URL FILTERING
  // ==========================================================================

  /**
   * Check if a URL matches a pattern.
   * Supports * wildcard for matching any characters.
   *
   * @param {string} url - The URL to check
   * @param {string} pattern - The pattern to match against
   * @returns {boolean} True if URL matches the pattern
   */
  function urlMatchesPattern(url, pattern) {
    // Exact match
    if (url === pattern) {
      return true;
    }

    // Wildcard matching
    if (pattern.indexOf("*") !== -1) {
      // Escape regex special characters, then replace * with .*
      var regex = pattern
        .replace(/[.+?^${}()|[\]\\]/g, "\\$&")
        .replace(/\*/g, ".*");
      return new RegExp("^" + regex + "$", "i").test(url);
    }

    // Partial/substring match
    return url.indexOf(pattern) !== -1;
  }

  /**
   * Check if URL matches any pattern in an array.
   *
   * @param {string} url - The URL to check
   * @param {string[]} patterns - Array of patterns
   * @returns {string|boolean} The matched pattern or false
   */
  function urlMatchesAnyPattern(url, patterns) {
    if (!patterns || !patterns.length) {
      return false;
    }
    for (var i = 0; i < patterns.length; i++) {
      if (urlMatchesPattern(url, patterns[i])) {
        return patterns[i];
      }
    }
    return false;
  }

  /**
   * Determine if the current page should be tracked.
   *
   * @param {string} url - The URL to check
   * @returns {boolean} True if the page should be tracked
   */
  function shouldTrackUrl(url) {
    var mode = settings.trackingMode || "all";

    if (mode === "whitelist") {
      // Whitelist mode: only track URLs matching included patterns
      var included = settings.includedUrls || [];
      if (!included.length) {
        return false; // No patterns = track nothing
      }
      return !!urlMatchesAnyPattern(url, included);
    }

    // Default mode: track all except excluded URLs
    var excluded = settings.excludedUrls || [];
    if (!excluded.length) {
      return true; // No exclusions = track everything
    }
    return !urlMatchesAnyPattern(url, excluded);
  }

  // Check if current page should be tracked
  var pageUrl = String(window.location.href || "");
  var pathname = String(window.location.pathname || "");

  // Check both full URL and pathname against filters
  if (!shouldTrackUrl(pageUrl) && !shouldTrackUrl(pathname)) {
    return; // Don't track this page
  }

  // ==========================================================================
  // SESSION MANAGEMENT
  // ==========================================================================

  /**
   * Get or create a session ID.
   * Uses localStorage for persistence across page views.
   *
   * @returns {string} 32-character hex session ID
   */
  function getSessionId() {
    try {
      var key = "wpa_session_id";
      var existing = window.localStorage.getItem(key);

      // Return existing valid session ID
      if (existing && existing.length >= 8) {
        return existing;
      }

      // Generate new session ID using crypto API if available
      var buf = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(buf);
      } else {
        // Fallback for older browsers
        for (var i = 0; i < buf.length; i++) {
          buf[i] = Math.floor(Math.random() * 256);
        }
      }

      // Convert to hex string
      var id = Array.prototype.map
        .call(buf, function (b) {
          return ("0" + b.toString(16)).slice(-2);
        })
        .join("");

      window.localStorage.setItem(key, id);
      return id;
    } catch (e) {
      return "";
    }
  }

  // ==========================================================================
  // STATE VARIABLES
  // ==========================================================================

  var sessionId = getSessionId();
  var referrer = String(document.referrer || "");
  var startMs = Date.now();
  var maxScroll = 0;
  var pageviewId = 0;
  var sentFinal = false;
  var pendingClicks = [];
  var pageHidden = false;
  var urlConversionSent = false;

  // Safety limits to prevent memory issues
  var MAX_PENDING_CLICKS = 20;
  var MAX_TRACKED_CONVERSIONS = 100;

  // ==========================================================================
  // UTILITY FUNCTIONS
  // ==========================================================================

  /**
   * Clamp a number between min and max values.
   */
  function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
  }

  /**
   * Calculate current scroll depth as a percentage.
   *
   * @returns {number} Scroll depth (0-100)
   */
  function calcScrollDepth() {
    var doc = document.documentElement;
    var body = document.body;

    // Get current scroll position
    var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop || 0;

    // Get viewport height
    var viewport = window.innerHeight || doc.clientHeight || 0;

    // Get full document height
    var full = Math.max(
      body.scrollHeight || 0,
      doc.scrollHeight || 0,
      body.offsetHeight || 0,
      doc.offsetHeight || 0,
      body.clientHeight || 0,
      doc.clientHeight || 0
    );

    // Calculate percentage
    var scrollableHeight = Math.max(1, full - viewport);
    var pct =
      scrollableHeight <= 1
        ? 100
        : Math.round((scrollTop / scrollableHeight) * 100);

    return clamp(pct, 0, 100);
  }

  // ==========================================================================
  // SCROLL TRACKING
  // ==========================================================================

  var scrollTicking = false;

  /**
   * Handle scroll events with requestAnimationFrame for performance.
   */
  function onScroll() {
    if (scrollTicking) {
      return;
    }
    scrollTicking = true;
    window.requestAnimationFrame(function () {
      maxScroll = Math.max(maxScroll, calcScrollDepth());
      scrollTicking = false;
    });
  }

  // Listen for scroll events
  window.addEventListener("scroll", onScroll, { passive: true });

  // Calculate initial scroll depth (for pages that start scrolled)
  maxScroll = Math.max(maxScroll, calcScrollDepth());

  // ==========================================================================
  // API COMMUNICATION
  // ==========================================================================

  /**
   * Send JSON data to the REST API.
   *
   * @param {string} path - API endpoint path
   * @param {Object} payload - Data to send
   * @param {boolean} useBeacon - Use sendBeacon for page unload
   */
  function postJson(path, payload, useBeacon) {
    var url = settings.restUrl.replace(/\/$/, "") + path;

    try {
      var body = JSON.stringify(payload);

      // Use Beacon API for unload events (more reliable)
      if (useBeacon && navigator.sendBeacon) {
        var blob = new Blob([body], { type: "application/json" });
        navigator.sendBeacon(url, blob);
        return;
      }

      // Standard fetch request
      fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: body,
        keepalive: !!useBeacon,
        credentials: "omit",
      }).catch(function () {
        // Silently ignore errors
      });
    } catch (e) {
      // Silently ignore errors
    }
  }

  // ==========================================================================
  // PAGEVIEW TRACKING
  // ==========================================================================

  /**
   * Create a new pageview record.
   */
  function createPageview() {
    try {
      var url = settings.restUrl.replace(/\/$/, "") + "/pageview";

      fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          token: settings.token,
          page_url: pageUrl,
          referrer: referrer,
          session: sessionId,
        }),
        credentials: "omit",
      })
        .then(function (response) {
          return response && response.ok ? response.json() : null;
        })
        .then(function (data) {
          if (data && data.pageview_id) {
            pageviewId = Number(data.pageview_id) || 0;
          }

          // Send any pending link clicks now that we have a pageview ID
          if (pageviewId && pendingClicks.length) {
            pendingClicks.forEach(function (href) {
              postJson(
                "/link-click",
                {
                  token: settings.token,
                  pageview_id: pageviewId,
                  page_url: pageUrl,
                  link_url: href,
                  referrer: referrer,
                  session: sessionId,
                },
                false
              );
            });
            pendingClicks = [];
          }

          // Check for URL-based conversion (thank you page)
          checkUrlConversion();

          // Send engagement if page was hidden before pageview completed
          if (pageHidden) {
            sendEngagementFinal();
          }
        })
        .catch(function () {
          // Silently ignore errors
        });
    } catch (e) {
      // Silently ignore errors
    }
  }

  // Create pageview when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", createPageview, {
      once: true,
    });
  } else {
    createPageview();
  }

  // ==========================================================================
  // ENGAGEMENT TRACKING
  // ==========================================================================

  /**
   * Send final engagement data when user leaves page.
   */
  function sendEngagementFinal() {
    // Only send once
    if (sentFinal) {
      return;
    }

    // Must have valid pageview and session
    if (!pageviewId || !sessionId) {
      return;
    }

    sentFinal = true;

    // Calculate time on page in seconds
    var seconds = Math.round((Date.now() - startMs) / 1000);
    var depth = clamp(maxScroll, 0, 100);

    postJson(
      "/engagement",
      {
        token: settings.token,
        pageview_id: pageviewId,
        session: sessionId,
        time_on_page: seconds,
        scroll_depth: depth,
      },
      true // Use beacon for reliability
    );
  }

  // Track when page becomes hidden
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") {
      pageHidden = true;
      sendEngagementFinal();
    }
  });

  // Also track on page unload
  window.addEventListener("pagehide", sendEngagementFinal, { capture: true });

  // ==========================================================================
  // LINK CLICK TRACKING
  // ==========================================================================

  /**
   * Find the closest anchor element in the DOM tree.
   *
   * @param {Element} el - Starting element
   * @returns {Element|null} Anchor element or null
   */
  function closestAnchor(el) {
    while (el && el !== document.body) {
      if (el.tagName && String(el.tagName).toLowerCase() === "a" && el.href) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  // Listen for link clicks
  document.addEventListener(
    "click",
    function (e) {
      var target = e.target;
      var anchor = closestAnchor(target);

      if (!anchor) {
        return;
      }

      var href = String(anchor.href || "");
      if (!href) {
        return;
      }

      // Queue click if pageview not yet created
      if (!pageviewId) {
        if (pendingClicks.length < MAX_PENDING_CLICKS) {
          pendingClicks.push(href);
        }
        return;
      }

      // Send link click event
      postJson(
        "/link-click",
        {
          token: settings.token,
          pageview_id: pageviewId,
          page_url: pageUrl,
          link_url: href,
          referrer: referrer,
          session: sessionId,
        },
        false
      );
    },
    { capture: true, passive: true }
  );

  // ==========================================================================
  // CONVERSION TRACKING - URL-BASED (Thank You Pages)
  // ==========================================================================

  var conversionUrls = settings.conversionUrls || [];

  /**
   * Check if current page URL matches a conversion URL pattern.
   */
  function checkUrlConversion() {
    if (urlConversionSent || !conversionUrls.length) {
      return;
    }

    if (!pageviewId || !sessionId) {
      return;
    }

    // Check if current URL matches any conversion URL pattern
    var matchedPattern = urlMatchesAnyPattern(pageUrl, conversionUrls);
    if (!matchedPattern) {
      matchedPattern = urlMatchesAnyPattern(pathname, conversionUrls);
    }

    if (matchedPattern) {
      urlConversionSent = true;

      // Send conversion event with URL type indicator
      postJson(
        "/conversion",
        {
          token: settings.token,
          pageview_id: pageviewId,
          conversion_type: "url",
          conversion_value: matchedPattern,
          page_url: pageUrl,
          session: sessionId,
        },
        false
      );
    }
  }

  // ==========================================================================
  // CONVERSION TRACKING - BUTTON CLICKS (IDs and Classes)
  // ==========================================================================

  var conversionButtonIds = settings.conversionButtonIds || [];
  var conversionButtonClasses = settings.conversionButtonClasses || [];
  var trackedConversions = {};
  var trackedConversionCount = 0;

  // Only set up button tracking if there are buttons to track
  if (conversionButtonIds.length > 0 || conversionButtonClasses.length > 0) {
    document.addEventListener(
      "click",
      function (e) {
        var target = e.target;

        // Walk up DOM tree to find element with tracked ID or class
        var element = target;
        var matchedSelector = null;
        var matchType = null;

        while (element && element !== document.body) {
          // Check for matching ID
          if (element.id && conversionButtonIds.indexOf(element.id) !== -1) {
            matchedSelector = element.id;
            matchType = "id";
            break;
          }

          // Check for matching class
          if (element.classList && conversionButtonClasses.length > 0) {
            for (var i = 0; i < conversionButtonClasses.length; i++) {
              if (element.classList.contains(conversionButtonClasses[i])) {
                matchedSelector = conversionButtonClasses[i];
                matchType = "class";
                break;
              }
            }
            if (matchedSelector) {
              break;
            }
          }

          element = element.parentNode;
        }

        // No tracked button found
        if (!matchedSelector) {
          return;
        }

        // Validate selector length (security check)
        if (matchedSelector.length > 100) {
          return;
        }

        // Prevent duplicate tracking for same button in same pageview
        var trackKey = pageviewId + "_" + matchType + "_" + matchedSelector;
        if (trackedConversions[trackKey]) {
          return;
        }

        // Limit total tracked conversions
        if (trackedConversionCount >= MAX_TRACKED_CONVERSIONS) {
          return;
        }

        trackedConversions[trackKey] = true;
        trackedConversionCount++;

        // Wait for pageview ID
        if (!pageviewId || !sessionId) {
          return;
        }

        // Send conversion event
        postJson(
          "/conversion",
          {
            token: settings.token,
            pageview_id: pageviewId,
            conversion_type: matchType,
            conversion_value: matchedSelector,
            page_url: pageUrl,
            session: sessionId,
          },
          false
        );
      },
      { capture: true, passive: true }
    );
  }
})();
