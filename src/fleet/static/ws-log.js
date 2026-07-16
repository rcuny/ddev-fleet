// Minimal WebSocket log tailer for the deploy job panel (spec §13).
// Any element with a `data-ws-url` attribute gets its own socket; incoming
// text frames are appended verbatim and the pane auto-scrolls. Re-entrant
// safe: htmx swaps re-trigger htmx:afterSettle, so already-attached panes
// are skipped via the `data-ws-attached` marker. A module-level `attached`
// set (keyed by WS URL) is a second guard: with hx-preserve keeping the
// same DOM node across polls, this ensures only one socket per URL exists
// even if multiple elements briefly reference the same URL.
(function () {
  var attached = new Set();

  function attach(el) {
    var url = el.getAttribute("data-ws-url");
    if (!url || el.dataset.wsAttached || attached.has(url)) return;
    el.dataset.wsAttached = "1";
    attached.add(url);
    var proto = window.location.protocol === "https:" ? "wss:" : "ws:";
    var socket = new WebSocket(proto + "//" + window.location.host + url);
    socket.onmessage = function (event) {
      el.textContent += event.data;
      el.scrollTop = el.scrollHeight;
    };
    socket.onclose = function () {
      el.dataset.wsAttached = "";
      attached.delete(url);
    };
  }

  // Listen on `document`, not `document.body`: htmx events bubble, and body is
  // still null if this script is ever loaded from <head> without `defer`.
  document.addEventListener("htmx:afterSettle", function (evt) {
    var root = evt.target;
    // querySelectorAll only matches descendants, so an outerHTML swap that puts
    // data-ws-url on the swapped node itself would otherwise be missed.
    if (root.matches && root.matches("[data-ws-url]")) attach(root);
    root.querySelectorAll("[data-ws-url]").forEach(attach);
  });
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-ws-url]").forEach(attach);
  });
})();
