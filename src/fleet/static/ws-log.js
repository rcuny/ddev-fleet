// Minimal WebSocket log tailer for the deploy job panel (spec §13).
// Any element with a `data-ws-url` attribute gets its own socket; incoming
// text frames are appended verbatim and the pane auto-scrolls. Re-entrant
// safe: htmx swaps re-trigger htmx:afterSettle, so already-attached panes
// are skipped via the `data-ws-attached` marker.
(function () {
  function attach(el) {
    var url = el.getAttribute("data-ws-url");
    if (!url || el.dataset.wsAttached) return;
    el.dataset.wsAttached = "1";
    var proto = window.location.protocol === "https:" ? "wss:" : "ws:";
    var socket = new WebSocket(proto + "//" + window.location.host + url);
    socket.onmessage = function (event) {
      el.textContent += event.data;
      el.scrollTop = el.scrollHeight;
    };
    socket.onclose = function () {
      el.dataset.wsAttached = "";
    };
  }

  document.body.addEventListener("htmx:afterSettle", function (evt) {
    evt.target.querySelectorAll("[data-ws-url]").forEach(attach);
  });
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-ws-url]").forEach(attach);
  });
})();
