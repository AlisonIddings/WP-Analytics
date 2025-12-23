(function () {
  "use strict";

  var settings = window.saTrackerSettings || {};
  if (!settings.restUrl || !settings.token) return;

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
  var pageUrl = String(window.location.href || "");
  var referrer = String(document.referrer || "");
  var startMs = Date.now();
  var maxScroll = 0;
  var pageviewId = 0;
  var sentFinal = false;
  var pendingClicks = [];
  var pageHidden = false;

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
    sentFinal = true;
    var seconds = Math.round((Date.now() - startMs) / 1000);
    var depth = clamp(maxScroll, 0, 100);
    if (!pageviewId) return;
    postJson(
      "/engagement",
      {
        token: settings.token,
        pageview_id: pageviewId,
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
        pendingClicks.push(href);
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
})();

