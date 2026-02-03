(function () {
  "use strict";

  var settings = window.saTrackerSettings || {};
  if (!settings.restUrl || !settings.token) return;

  /**
   * Check if a URL matches a pattern (supports * wildcard).
   */
  function urlMatchesPattern(url, pattern) {
    if (url === pattern) return true;
    
    if (pattern.indexOf("*") !== -1) {
      // Convert pattern to regex
      var regex = pattern
        .replace(/[.+?^${}()|[\]\\]/g, "\\$&") // Escape special chars except *
        .replace(/\*/g, ".*"); // Convert * to .*
      return new RegExp("^" + regex + "$", "i").test(url);
    }
    
    // Partial match
    return url.indexOf(pattern) !== -1;
  }

  /**
   * Check if URL matches any pattern in the array.
   */
  function urlMatchesAnyPattern(url, patterns) {
    if (!patterns || !patterns.length) return false;
    for (var i = 0; i < patterns.length; i++) {
      if (urlMatchesPattern(url, patterns[i])) return true;
    }
    return false;
  }

  /**
   * Check if current page should be tracked based on URL settings.
   */
  function shouldTrackUrl(url) {
    var mode = settings.trackingMode || "all";
    
    if (mode === "whitelist") {
      // Only track if URL matches included patterns
      var included = settings.includedUrls || [];
      if (!included.length) return false; // No patterns = track nothing
      return urlMatchesAnyPattern(url, included);
    }
    
    // Default: track all except excluded
    var excluded = settings.excludedUrls || [];
    if (!excluded.length) return true; // No exclusions = track everything
    return !urlMatchesAnyPattern(url, excluded);
  }

  // Check if current URL should be tracked
  var pageUrl = String(window.location.href || "");
  var pathname = String(window.location.pathname || "");
  
  // Check both full URL and pathname
  if (!shouldTrackUrl(pageUrl) && !shouldTrackUrl(pathname)) {
    return; // Don't track this page
  }

  function getSessionId() {
    try {
      var key = "sa_session_id";
      var existing = window.localStorage.getItem(key);
      if (existing && existing.length >= 8) return existing;
      var buf = new Uint8Array(16);
      if (window.crypto && window.crypto.getRandomValues) {
        window.crypto.getRandomValues(buf);
      } else {
        for (var i = 0; i < buf.length; i++) buf[i] = Math.floor(Math.random() * 256);
      }
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

  var sessionId = getSessionId();
  // pageUrl already defined above for URL filtering
  var referrer = String(document.referrer || "");
  var startMs = Date.now();
  var maxScroll = 0;
  var pageviewId = 0;
  var sentFinal = false;
  var pendingClicks = [];
  var pageHidden = false;
  var MAX_PENDING_CLICKS = 20; // Limit to prevent memory issues

  function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
  }

  function calcScrollDepth() {
    var doc = document.documentElement;
    var body = document.body;
    var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop || 0;
    var viewport = window.innerHeight || doc.clientHeight || 0;
    var full = Math.max(
      body.scrollHeight || 0,
      doc.scrollHeight || 0,
      body.offsetHeight || 0,
      doc.offsetHeight || 0,
      body.clientHeight || 0,
      doc.clientHeight || 0
    );
    var denom = Math.max(1, full - viewport);
    var pct = denom <= 1 ? 100 : Math.round((scrollTop / denom) * 100);
    return clamp(pct, 0, 100);
  }

  var scrollTicking = false;
  function onScroll() {
    if (scrollTicking) return;
    scrollTicking = true;
    window.requestAnimationFrame(function () {
      maxScroll = Math.max(maxScroll, calcScrollDepth());
      scrollTicking = false;
    });
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  maxScroll = Math.max(maxScroll, calcScrollDepth());

  function postJson(path, payload, useBeacon) {
    var url = settings.restUrl.replace(/\/$/, "") + path;
    try {
      var body = JSON.stringify(payload);
      if (useBeacon && navigator.sendBeacon) {
        var blob = new Blob([body], { type: "application/json" });
        navigator.sendBeacon(url, blob);
        return;
      }
      fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: body,
        keepalive: !!useBeacon,
        credentials: "omit",
      }).catch(function () {});
    } catch (e) {}
  }

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
        .then(function (r) {
          return r && r.ok ? r.json() : null;
        })
        .then(function (data) {
          if (data && data.pageview_id) pageviewId = Number(data.pageview_id) || 0;
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
          if (pageHidden) sendEngagementFinal();
        })
        .catch(function () {});
    } catch (e) {}
  }

  // Create pageview early.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", createPageview, { once: true });
  } else {
    createPageview();
  }

  function sendEngagementFinal() {
    if (sentFinal) return;
    // Check pageviewId BEFORE setting sentFinal to avoid race condition
    // where quick page exit marks sentFinal=true before pageview completes
    if (!pageviewId) return;
    // Also require valid session for security
    if (!sessionId) return;
    sentFinal = true;
    var seconds = Math.round((Date.now() - startMs) / 1000);
    var depth = clamp(maxScroll, 0, 100);
    postJson(
      "/engagement",
      {
        token: settings.token,
        pageview_id: pageviewId,
        session: sessionId, // Required for ownership validation
        time_on_page: seconds,
        scroll_depth: depth,
      },
      true
    );
  }

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") {
      pageHidden = true;
      sendEngagementFinal();
    }
  });
  window.addEventListener("pagehide", sendEngagementFinal, { capture: true });

  function closestAnchor(el) {
    while (el && el !== document.body) {
      if (el.tagName && String(el.tagName).toLowerCase() === "a" && el.href) return el;
      el = el.parentNode;
    }
    return null;
  }

  document.addEventListener(
    "click",
    function (e) {
      var target = e.target;
      var a = closestAnchor(target);
      if (!a) return;
      var href = String(a.href || "");
      if (!href) return;
      if (!pageviewId) {
        // Limit pending clicks to prevent memory issues
        if (pendingClicks.length < MAX_PENDING_CLICKS) {
          pendingClicks.push(href);
        }
        return;
      }
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

  // Conversion tracking for specific button IDs
  var conversionButtons = settings.conversionButtons || [];
  var trackedConversions = {}; // Prevent duplicate tracking per session
  var trackedConversionCount = 0;
  var MAX_TRACKED_CONVERSIONS = 100; // Limit to prevent memory issues

  if (conversionButtons.length > 0) {
    document.addEventListener(
      "click",
      function (e) {
        var target = e.target;
        
        // Walk up the DOM tree to find an element with a tracked ID
        var element = target;
        var buttonId = null;
        
        while (element && element !== document.body) {
          if (element.id && conversionButtons.indexOf(element.id) !== -1) {
            buttonId = element.id;
            break;
          }
          element = element.parentNode;
        }

        if (!buttonId) return;
        
        // Validate button ID length (must match server-side limit)
        if (buttonId.length > 100) return;
        
        // Prevent duplicate tracking for same button in same pageview
        var trackKey = pageviewId + "_" + buttonId;
        if (trackedConversions[trackKey]) return;
        
        // Limit total tracked conversions to prevent memory issues
        if (trackedConversionCount >= MAX_TRACKED_CONVERSIONS) return;
        
        trackedConversions[trackKey] = true;
        trackedConversionCount++;

        // Wait for pageview ID if not yet available
        if (!pageviewId || !sessionId) {
          return;
        }

        postJson(
          "/conversion",
          {
            token: settings.token,
            pageview_id: pageviewId,
            button_id: buttonId,
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

