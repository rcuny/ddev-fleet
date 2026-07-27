// Minimal WebSocket log tailer for the deploy job panel (spec §13).
// Any element with a `data-ws-url` attribute gets its own socket; incoming
// text frames are appended verbatim. Re-entrant safe: htmx swaps re-trigger
// htmx:afterSettle, so already-attached panes are skipped via the
// `data-ws-attached` marker. A module-level `attached` set (keyed by WS URL)
// is a second guard: with hx-preserve keeping the same DOM node across
// polls, this ensures only one socket per URL exists even if multiple
// elements briefly reference the same URL.
//
// Scroll behaviour ("stick to the bottom", tail -f style) is the other half
// of this file. See the comment above `isAtBottom` and the htmx:beforeSwap/
// afterSettle handlers below for why that needs more than "scroll to bottom
// on every message" — that naive version is what used to make the log jump
// around every 2 seconds.
(function () {
  var attached = new Set();

  // How close to the bottom (in px) still counts as "at the bottom". Not 0:
  // fractional line heights, browser zoom, and sub-pixel layout mean an
  // element the user deliberately scrolled all the way down can still be
  // reported as 1-2px short of scrollHeight - clientHeight. 24px is roughly
  // one line of the monospace log text, so it forgives that noise without
  // being loose enough to feel "stuck" when the user is genuinely reading
  // a few lines up from the end.
  var STICK_THRESHOLD = 24;

  // Scroll state captured just before an htmx outerHTML swap, keyed by the
  // log element's DOM `id` (stable across the swap — see the beforeSwap/
  // afterSettle comment below). Read back and cleared in afterSettle.
  var swapState = new Map();

  function isAtBottom(el) {
    return el.scrollHeight - el.scrollTop - el.clientHeight <= STICK_THRESHOLD;
  }

  function attach(el) {
    var url = el.getAttribute("data-ws-url");
    if (!url || el.dataset.wsAttached || attached.has(url)) return;
    el.dataset.wsAttached = "1";
    attached.add(url);

    // "Stuck" means the pane should keep following new output. Default to
    // stuck for a freshly-attached element: the whole point of a log tailer
    // is to show the latest lines until the user asks otherwise by scrolling
    // up. `htmx:afterSettle` below may immediately override this with a
    // restored value when the element survived an htmx swap.
    el._wsStuck = true;

    el.addEventListener("scroll", function () {
      // Setting `el.scrollTop` programmatically (our own restore below, or
      // the browser resetting scrollTop to 0 when a preserved node is
      // detached/re-inserted by htmx) fires a `scroll` event too, same as a
      // real user gesture. While `el._wsRestoring` is set we're in the
      // middle of one of those programmatic moves, so we must NOT let it
      // re-derive stickiness here — that would immediately undo the restore
      // (e.g. the browser's own 0-reset would read as "user scrolled to the
      // top", un-sticking a pane that was actually still at the bottom).
      if (el._wsRestoring) return;
      el._wsStuck = isAtBottom(el);
    });

    var proto = window.location.protocol === "https:" ? "wss:" : "ws:";
    var socket = new WebSocket(proto + "//" + window.location.host + url);
    el._wsSocket = socket;
    socket.onmessage = function (event) {
      el.textContent += event.data;
      // Only follow the tail when the pane is stuck to the bottom. If the
      // user scrolled up to read earlier output, appending text must not
      // yank them back down — unconditionally doing so was the other half
      // of the original "jumps around" bug (poll -> jumps to top via the
      // htmx swap below, next line -> jumps back to the bottom here).
      if (el._wsStuck) el.scrollTop = el.scrollHeight;
    };
    socket.onclose = function () {
      el.dataset.wsAttached = "";
      attached.delete(url);
    };
  }

  // --- Preserve scroll position across the 2s htmx polling swap ----------
  //
  // While a job is queued/running, job_panel.html re-fetches the WHOLE panel
  // every 2s (`hx-trigger="every 2s" hx-swap="outerHTML"`). For the single-
  // deploy log, `hx-preserve="true"` keeps the same DOM node across that
  // swap instead of replacing it with a freshly-parsed one — but htmx
  // implements hx-preserve by DETACHING the node from the old document
  // fragment and re-inserting it into the new one, and re-inserting a
  // scrollable element resets its `scrollTop` to 0 as a side effect of that
  // detach/attach (this is a browser layout behaviour, nothing to do with
  // our code). That reset is the "jumps to the top every 2 seconds" half of
  // the bug. So: capture `{stuck, scrollTop}` just before the swap, and put
  // it back just after, keyed by the element's DOM id (stable across the
  // swap for both branches of job_panel.html — see the bulk-branch caveat
  // below for the one case where "stable id" doesn't imply "same content").
  document.addEventListener("htmx:beforeSwap", function (evt) {
    var root = evt.target;
    function record(el) {
      if (!el.id) return;
      swapState.set(el.id, { stuck: !!el._wsStuck, scrollTop: el.scrollTop });
    }
    // querySelectorAll only matches descendants, so an outerHTML swap that
    // puts data-ws-url on the swapped node itself would otherwise be missed
    // (mirrors the root.matches(...) handling in htmx:afterSettle below).
    if (root.matches && root.matches("[data-ws-url]")) record(root);
    root.querySelectorAll("[data-ws-url]").forEach(record);
  });

  // Listen on `document`, not `document.body`: htmx events bubble, and body is
  // still null if this script is ever loaded from <head> without `defer`.
  document.addEventListener("htmx:afterSettle", function (evt) {
    var root = evt.target;
    // querySelectorAll only matches descendants, so an outerHTML swap that puts
    // data-ws-url on the swapped node itself would otherwise be missed.
    function settle(el) {
      attach(el);
      if (!el.id) return;
      var saved = swapState.get(el.id);
      swapState.delete(el.id);
      if (!saved) return;
      // Guard, mirrored with the scroll listener above: writing scrollTop
      // here fires a `scroll` event (synchronously in most browsers), which
      // would otherwise re-derive el._wsStuck from a mid-restore scroll
      // position instead of honouring the value we saved. Clear the flag on
      // the next animation frame, by which point both the browser's own
      // post-insertion layout/scroll settling and our restore below are done.
      el._wsRestoring = true;
      el._wsStuck = saved.stuck;
      el.scrollTop = saved.stuck ? el.scrollHeight : saved.scrollTop;
      requestAnimationFrame(function () {
        el._wsRestoring = false;
      });
    }
    if (root.matches && root.matches("[data-ws-url]")) settle(root);
    root.querySelectorAll("[data-ws-url]").forEach(settle);
  });
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-ws-url]").forEach(attach);
  });

  // --- Known limitation: the bulk / multi-deploy log pane -----------------
  //
  // job_panel.html's bulk branch has NO hx-preserve on its `.job-log`,
  // because its `data-ws-url` has to change as `progress.current` advances
  // to the next instance — hx-preserve would pin the pane to the WRONG
  // instance's URL, so it deliberately isn't used there. That means every
  // 2s poll destroys and rebuilds that element (a new DOM node, a new
  // socket, the old one closed), and the daemon replays the *entire* log
  // file on connect (`daemon.py`'s `ws_instance_log`, ~line 254, sends
  // `log_path.read_text()` before tailing new writes).
  //
  // Because the old and new bulk `.job-log` nodes happen to share the same
  // `id` (`log-{{ job.id }}`, constant for the life of the job), the
  // beforeSwap/afterSettle bookkeeping above still fires for them and does
  // something reasonable in the common case (freshly stuck -> stays stuck).
  // But it is restoring state captured against one instance's log onto a
  // brand-new element for a *different* instance, whose content then
  // arrives asynchronously after the reconnect — so a restored non-bottom
  // `scrollTop` can land imprecisely once that content streams in. This is
  // a known limitation of the bulk branch's necessary re-creation, not
  // something this file tries to fix.
})();
