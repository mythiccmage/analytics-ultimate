/*! Analytics Ultimate tracking script. No cookies, no IP addresses, no dependencies.
 *
 * Add to every page you want counted:
 *   <script defer src="https://YOUR-STATS-SITE/analytics-ultimate/a.js"></script>
 *
 * Optional attributes on that tag:
 *   data-respect-dnt="true"       skip visitors who send Do Not Track
 *   data-spa="false"              don't count in-page route changes (default: counted)
 *   data-extensions="iso,bin"     extra file extensions to count as downloads
 *   data-dev="true"               also count localhost (for testing the setup)
 *
 * Other things you can use on your pages:
 *   <a href="..." data-ua-download>      count this link as a download
 *   <button data-ua-event="signup">      count clicks as the event "signup"
 *   AnalyticsUltimate.track("signup")    count an event from your own JavaScript
 *   ?ua-ignore=1 on any page of your site stops counting THIS browser (?ua-ignore=0 turns it back on)
 */
(function () {
  "use strict";
  var d = document, w = window, n = navigator, loc = w.location;
  var tag = d.currentScript;
  if (!tag) {
    var all = d.getElementsByTagName("script");
    tag = all[all.length - 1];
  }
  var src = (tag && tag.src) || "";
  if (!src) return;
  var base = src.replace(/[^\/]*(\?.*)?$/, "");
  var endpoint = base + "collect.php";
  var ds = tag.dataset || {};

  if (loc.protocol === "file:") return;
  if (ds.dev !== "true" && /^(localhost|127\.|\[::1\]|0\.0\.0\.0)/.test(loc.hostname)) return;

  // Owner opt-out: visit any page with ?ua-ignore=1 once per browser.
  try {
    var m = /[?&]ua-ignore=(0|1)(&|$)/.exec(loc.search);
    if (m) {
      if (m[1] === "1") localStorage.setItem("auc_ignore", "1");
      else localStorage.removeItem("auc_ignore");
    }
    if (localStorage.getItem("auc_ignore") === "1") return;
  } catch (e) {}

  if (ds.respectDnt === "true" && (n.doNotTrack === "1" || w.doNotTrack === "1")) return;

  function uid() {
    try {
      var k = "auc_vid", v = localStorage.getItem(k);
      if (!v) {
        v = (w.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, "")
          : String(Date.now()) + Math.random().toString(16).slice(2);
        localStorage.setItem(k, v);
      }
      return v;
    } catch (e) { return "anonymous"; }
  }

  function send(o) {
    o.h = loc.hostname;
    o.u = uid();
    var body = JSON.stringify(o);
    try {
      // text/plain keeps this a "simple" request, so no CORS preflight is needed across domains.
      if (n.sendBeacon && n.sendBeacon(endpoint, new Blob([body], { type: "text/plain;charset=UTF-8" }))) return;
    } catch (e) {}
    try {
      fetch(endpoint, { method: "POST", body: body, keepalive: true, mode: "cors",
        headers: { "Content-Type": "text/plain;charset=UTF-8" } });
    } catch (e) {}
  }

  var lastPath = null;
  function pageview(referrer) {
    var path = loc.pathname;
    if (path === lastPath) return;
    lastPath = path;
    var s = /[?&](?:utm_source|ref)=([^&#]+)/.exec(loc.search);
    send({ t: "pageview", p: path, r: referrer || "", s: s ? decodeURIComponent(s[1]) : "" });
  }

  pageview(d.referrer);

  // Single-page apps: count route changes too.
  if (ds.spa !== "false" && w.history && history.pushState) {
    var prev = loc.href;
    var wrap = function (name) {
      var orig = history[name];
      history[name] = function () {
        var r = orig.apply(this, arguments);
        var from = prev;
        prev = loc.href;
        pageview(from);
        return r;
      };
    };
    wrap("pushState");
    wrap("replaceState");
    w.addEventListener("popstate", function () { var from = prev; prev = loc.href; pageview(from); });
  }

  // Downloads: file links, or anything marked data-ua-download.
  var exts = "zip|rar|7z|tar|gz|tgz|bz2|xz|exe|msi|dmg|pkg|deb|rpm|apk|ipa|iso|appimage|pdf|epub|mobi|doc|docx|xls|xlsx|ppt|pptx|csv|mp3|wav|flac|mp4|mov|mkv";
  if (ds.extensions) exts += "|" + ds.extensions.replace(/[^a-z0-9,|]/gi, "").replace(/,/g, "|");
  var extRe = new RegExp("\\.(" + exts + ")$", "i");

  function onClick(e) {
    if (e.type === "click" && e.button !== 0) return;
    var el = e.target;
    while (el && el !== d && el.nodeType === 1) {
      if (el.getAttribute) {
        var ev = el.getAttribute("data-ua-event");
        if (ev) send({ t: "event", l: ev, p: loc.pathname });
        if (el.tagName === "A" && el.href) {
          var u;
          try { u = new URL(el.href, loc.href); } catch (err) { u = null; }
          if (u && /^https?:$/.test(u.protocol)) {
            var isGo = el.href.indexOf(base + "go.php") === 0; // counted on the server instead
            var file = decodeURIComponent(u.pathname.split("/").pop() || "");
            if (!isGo && (el.hasAttribute("data-ua-download") || extRe.test(u.pathname))) {
              send({ t: "download", l: file || u.hostname, p: loc.pathname });
            }
          }
        }
      }
      el = el.parentNode;
    }
  }
  d.addEventListener("click", onClick, true);
  d.addEventListener("auxclick", onClick, true);

  w.AnalyticsUltimate = {
    track: function (name) {
      if (name) send({ t: "event", l: String(name), p: loc.pathname });
    },
  };
})();
