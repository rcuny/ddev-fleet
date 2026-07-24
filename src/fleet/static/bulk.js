// Bulk-selection UI behavior for the instances table + the multi-deploy
// WebSocket log-switching fix (spec §8, 2026-07-24 fleet-bulk-actions
// design). Kept separate from ws-log.js, whose scope is only "tail one
// already-known log" — this file owns bulk-specific concerns: select-all,
// the destroy-confirmation reveal, guarding against an empty-selection POST,
// surfacing a bulk route's error response, and closing a per-instance socket
// when htmx swaps it out for the next one.
(function () {
  function selectedIds() {
    return Array.prototype.map.call(
      document.querySelectorAll("#bulk-form .row-select:checked"),
      function (cb) {
        return cb.value;
      }
    );
  }

  document.addEventListener("change", function (evt) {
    if (evt.target && evt.target.id === "select-all") {
      var checked = evt.target.checked;
      document.querySelectorAll("#bulk-form .row-select").forEach(function (cb) {
        cb.checked = checked;
      });
    }
  });

  document.addEventListener("click", function (evt) {
    if (evt.target && evt.target.id === "bulk-destroy-trigger") {
      var selected = document.querySelectorAll("#bulk-form .row-select:checked");
      var n = selected.length;
      var confirmRow = document.getElementById("bulk-destroy-confirm");
      if (!confirmRow || n === 0) return;
      confirmRow.hidden = false;
      document.getElementById("bulk-destroy-count").textContent = n;
      document.getElementById("bulk-destroy-count-2").textContent = n;
      document.getElementById("bulk-destroy-confirm-count-field").value = n;
      var input = document.getElementById("bulk-destroy-confirm-input");
      var btn = document.getElementById("bulk-destroy-confirm-btn");
      input.value = "";
      btn.disabled = true;
      input.oninput = function () {
        btn.disabled = input.value.trim() !== String(n);
      };
    }
  });

  // Guard against an empty-selection POST (spec §8 review addition): the
  // three bulk buttons (start/stop/destroy-confirm) all carry hx-post to
  // "/ui/bulk/*" and hx-include="#bulk-form". With nothing checked, htmx
  // would still fire the request with zero `instance_id` fields, which the
  // daemon's `list[str] = Form(...)` rejects with a 422 — a confusing error
  // for what should be a silent no-op. `htmx:beforeRequest` is the
  // documented htmx hook for conditionally cancelling a request:
  // preventDefault() on it aborts the ajax call before it's sent.
  document.addEventListener("htmx:beforeRequest", function (evt) {
    var elt = evt.target;
    var path = elt && elt.getAttribute && elt.getAttribute("hx-post");
    if (!path || path.indexOf("/ui/bulk/") !== 0) return;
    if (selectedIds().length === 0) {
      evt.preventDefault();
    }
  });

  // Surface a bulk route's error response (spec §8 review addition) rather
  // than silently failing: htmx does not swap non-2xx responses into the
  // target by default, it only fires `htmx:responseError` — so a rejected
  // destroy confirmation (400, confirm_count mismatch) would otherwise
  // vanish with no visible feedback.
  document.addEventListener("htmx:responseError", function (evt) {
    var elt = evt.detail && evt.detail.elt;
    var path = elt && elt.getAttribute && elt.getAttribute("hx-post");
    if (!path || path.indexOf("/ui/bulk/") !== 0) return;
    var message = "bulk action failed";
    try {
      message = JSON.parse(evt.detail.xhr.responseText).error || message;
    } catch (e) {
      // non-JSON body: fall back to the generic message above
    }
    alert(message);
  });

  // WebSocket log-switching fix (spec §8): a bulk/multi-deploy job panel
  // re-renders its `.job-log` element via an outerHTML swap every poll,
  // pointed at whichever instance is currently deploying. Nothing else
  // closes the PREVIOUS element's socket when htmx removes it — left
  // alone, it idles forever against a finished deploy.
  // `htmx:beforeCleanupElement` fires right before htmx detaches a node,
  // the correct point to force-close its socket (ws-log.js's own
  // `onclose` handler then clears its `attached`/`data-ws-attached`
  // bookkeeping, so a same-URL element later still opens cleanly).
  document.addEventListener("htmx:beforeCleanupElement", function (evt) {
    var el = evt.target;
    if (el.dataset && el.dataset.wsAttached && el._wsSocket) {
      el._wsSocket.close();
    }
  });
})();
