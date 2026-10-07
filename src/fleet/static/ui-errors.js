// Generic 4xx-body rendering for every hx-target on the page (spec: deploy-
// error-visibility fix). htmx (1.x and 2.x alike) does not swap non-2xx
// responses into hx-target by default — it only fires `htmx:responseError` —
// so a correct-but-invisible 400 (e.g. an invalid label, a mismatched
// project/template pair) left the Deploy button looking dead with no
// message anywhere. `htmx:beforeSwap` is the documented hook for overriding
// that, and it works unchanged in htmx 2.x (verified against the vendored
// 2.0.11 bundle): setting `shouldSwap = true` / `isError = false` tells htmx
// to swap the response body into the target and treat the request as
// non-erroring, exactly as it would for a 2xx. (htmx 2.x also offers
// `htmx.config.responseHandling`, a per-status-code table that is the
// declarative equivalent — e.g. `{code:"4..", swap:true, error:false}` —
// but this event handler is kept because it needs no config call before
// htmx initialises and behaves identically on both major versions.) The
// daemon's `fleet_error_handler` (daemon.py) renders `partials/error.html`
// for any HX-Request-flagged 4xx, so this handler has real HTML to swap in
// for every hx-post/hx-get on the page, not just the bulk routes bulk.js
// used to special-case.
document.addEventListener("htmx:beforeSwap", function (evt) {
  if (evt.detail.xhr.status >= 400 && evt.detail.xhr.status < 500) {
    evt.detail.shouldSwap = true;
    evt.detail.isError = false;
  }
});
