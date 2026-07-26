// Generic 4xx-body rendering for every hx-target on the page (spec: deploy-
// error-visibility fix). htmx 1.9.12 does not swap non-2xx responses into
// hx-target by default — it only fires `htmx:responseError` — so a
// correct-but-invisible 400 (e.g. an invalid label, a mismatched
// project/template pair) left the Deploy button looking dead with no
// message anywhere. `htmx:beforeSwap` is the documented htmx 1.x hook for
// overriding that: setting `shouldSwap = true` / `isError = false` tells
// htmx to swap the response body into the target and treat the request as
// non-erroring, exactly as it would for a 2xx. (`htmx.config.
// responseHandling` is the 2.x way to configure this per-status-code; this
// bundle is 1.9.12 — see `src/fleet/static/htmx.min.js` — so that config
// object doesn't exist here and this event handler is the correct fix for
// THIS version.) The daemon's `fleet_error_handler` (daemon.py) renders
// `partials/error.html` for any HX-Request-flagged 4xx, so this handler has
// real HTML to swap in for every hx-post/hx-get on the page, not just the
// bulk routes bulk.js used to special-case.
document.addEventListener("htmx:beforeSwap", function (evt) {
  if (evt.detail.xhr.status >= 400 && evt.detail.xhr.status < 500) {
    evt.detail.shouldSwap = true;
    evt.detail.isError = false;
  }
});
