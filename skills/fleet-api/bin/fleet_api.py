#!/usr/bin/env python3
"""Client and CLI for the Fleet HTTP API (`/api/v1`). Standard library only.

Contract: docs/reference/15-api.md in the Fleet repository. Setup, from the environment (never from a repo file):
  FLEET_API_TOKEN   the project token (flt_svc_...)
  FLEET_API_URL     the Fleet base URL, https only (for example https://fleet.example.com)
The token is masked (flt_***) in everything this tool prints or raises. Exit codes: 0 done, 1 API or network error,
2 usage or refused locally, 3 a waited-for job ended unsuccessfully.
"""
import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

TOKEN_RE = re.compile(r"flt_[A-Za-z0-9_]+")
FINAL_JOB = {"succeeded", "failed", "interrupted", "cancelled"}
MAX_RETRY_WAIT = 60


_LITERALS = set()  # tokens seen by this process: masked even when they do not look like flt_...


def mask(text):
    out = TOKEN_RE.sub("flt_***", str(text))
    for lit in sorted(_LITERALS, key=len, reverse=True):
        out = out.replace(lit, "flt_***")
    return out


class ApiError(Exception):
    """A refused or failed call. `code` is the problem+json `code` (for example capacity_full)."""

    def __init__(self, status, code="", detail="", body=None):
        self.status, self.code, self.detail, self.body = status, code, detail, body or {}
        super().__init__(mask(f"HTTP {status} {code}: {detail}".strip()))


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """urllib keeps the Authorization header across a redirect, even to another host: refuse every one."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ApiError(code, "redirect_refused", "refusing to follow a redirect (the token would travel with it)")


_NO_REDIRECT_OPEN = urllib.request.build_opener(_NoRedirect).open


def check_base_url(url):
    """The bearer token must not travel in clear: https only (plain http for a loopback test server)."""
    u = urllib.parse.urlsplit(url)
    if u.scheme == "https" and u.hostname:
        return
    if u.scheme == "http" and u.hostname in ("localhost", "127.0.0.1", "::1"):
        return
    raise ApiError(0, "insecure_url", f"refusing API base URL {mask(url)!r}: it must start with https://")


def retry_wait(value, attempt):
    """Retry-After in seconds, capped; an HTTP-date or garbage falls back to exponential backoff."""
    try:
        secs = float(value)
    except (TypeError, ValueError):
        secs = 0
    if not secs > 0:
        secs = 2 ** attempt
    return min(secs, MAX_RETRY_WAIT)


class FleetApi:
    def __init__(self, token=None, base_url=None, environ=None, opener=None, sleep=time.sleep, retries=3, timeout=30):
        env = os.environ if environ is None else environ
        self.token = (token or env.get("FLEET_API_TOKEN") or "").strip()
        if not self.token:
            raise ApiError(0, "no_token", "FLEET_API_TOKEN is not set in the environment")
        url = (base_url or env.get("FLEET_API_URL") or "").strip()
        if not url:
            raise ApiError(0, "no_url", "FLEET_API_URL is not set in the environment")
        _LITERALS.add(self.token)
        self.base = url.rstrip("/")
        check_base_url(self.base)
        self._open = opener or _NO_REDIRECT_OPEN
        self._sleep, self.retries, self.timeout = sleep, retries, timeout

    # -- transport
    def _request(self, method, path, params=None, body=None, headers=None, raw=False):
        url = self.base + "/api/v1" + path
        if params:
            query = {k: v for k, v in params.items() if v is not None}
            if query:
                url += "?" + urllib.parse.urlencode(query)
        data = json.dumps(body).encode() if body is not None else None
        hdrs = {"Authorization": f"Bearer {self.token}", "Accept": "application/json"}
        if data is not None:
            hdrs["Content-Type"] = "application/json"
        hdrs.update(headers or {})
        # Reads retry on 429, 5xx and network errors; writes only on 429 (nothing ran, and no idempotency key exists).
        safe = method == "GET"
        attempt = 0
        while True:
            req = urllib.request.Request(url, data=data, headers=hdrs, method=method)
            try:
                with self._open(req, timeout=self.timeout) as r:
                    payload, status, rh = r.read(), r.status, dict(r.headers)
            except urllib.error.HTTPError as e:
                payload, status, rh = e.read(), e.code, dict(e.headers or {})
            except (urllib.error.URLError, TimeoutError, OSError) as e:
                if safe and attempt < self.retries:
                    attempt += 1
                    self._sleep(min(2 ** attempt, 30))
                    continue
                raise ApiError(0, "network", mask(e)) from None
            if (status == 429 or (safe and status >= 500)) and attempt < self.retries:
                attempt += 1
                wait = retry_wait(rh.get("Retry-After"), attempt) if status == 429 else min(2 ** attempt, 30)
                self._sleep(wait)
                continue
            if 300 <= status < 400:
                raise ApiError(status, "redirect_refused", "refusing to follow a redirect")
            if status >= 400:
                try:
                    prob = json.loads(payload)
                except ValueError:
                    prob = {}
                if not isinstance(prob, dict):
                    prob = {}
                raise ApiError(status, prob.get("code", ""), prob.get("detail") or prob.get("title") or "", prob)
            if raw:
                return payload.decode("utf-8", "replace"), {k.lower(): v for k, v in rh.items()}
            return (json.loads(payload) if payload else {}), rh

    def get(self, path, **params):
        return self._request("GET", path, params)[0]

    def post(self, path, body=None):
        return self._request("POST", path, body=body if body is not None else {})[0]

    def delete(self, path, if_match=None):
        headers = {"If-Match": f'"{if_match}"'} if if_match else None
        return self._request("DELETE", path, headers=headers)[0]

    def pages(self, path, key=None, **params):
        """All items of a paginated list (`limit`, `cursor`, `next_cursor`)."""
        out, cursor = [], None
        while True:
            doc = self.get(path, limit=200, cursor=cursor, **params)
            if isinstance(doc, list):
                return out + doc
            k = key or next((k for k, v in doc.items() if isinstance(v, list)), None)
            out += doc.get(k) or []
            cursor = doc.get("next_cursor")
            if not cursor:
                return out

    # -- resources
    def token_self(self):
        return self.get("/tokens/self")

    def instances(self, **filters):
        return self.pages("/instances", **filters)

    def instance(self, iid):
        return self.get(f"/instances/{iid}")

    def job(self, jid):
        return self.get(f"/jobs/{jid}")

    def job_log(self, jid, offset=0):
        """(text, {next_offset, state, done}) from byte `offset`; continue from next_offset to tail."""
        text, h = self._request("GET", f"/jobs/{jid}/log", {"offset": offset}, raw=True)[0:2]
        return text, {"next_offset": int(h.get("x-next-offset", offset) or offset), "state": h.get("x-job-state", ""),
                      "done": h.get("x-log-done") == "1"}

    def wait_job(self, jid, timeout=600, interval=3, clock=time.monotonic):
        end = clock() + timeout
        while True:
            doc = self.job(jid)
            job = doc.get("job", doc)
            if job.get("final") or job.get("status") in FINAL_JOB:
                return job
            if clock() + interval > end:
                raise ApiError(0, "timeout", f"job {jid} still {job.get('status')} after {timeout}s")
            self._sleep(interval)

    def _finish(self, ans, wait, timeout):
        jid = (ans.get("job") or {}).get("id")
        return self.wait_job(jid, timeout) if wait and jid else ans

    def deploy(self, project, branch=None, template=None, label=None, wait=False, timeout=600):
        body = {k: v for k, v in {"project": project, "branch": branch, "template": template, "label": label}.items() if v}
        return self._finish(self.post("/instances", body), wait, timeout)

    def redeploy(self, iid, branch=None, template=None, force=False, wait=False, timeout=600):
        body = {k: v for k, v in {"branch": branch, "template": template}.items() if v}
        if force:
            body["force"] = True
        return self._finish(self.post(f"/instances/{iid}/redeploy", body), wait, timeout)

    def start(self, iid, wait=False, timeout=600):
        return self._finish(self.post(f"/instances/{iid}/start"), wait, timeout)

    def stop(self, iid, wait=False, timeout=600):
        return self._finish(self.post(f"/instances/{iid}/stop"), wait, timeout)

    def destroy(self, iid, wait=False, timeout=600):
        """Read the instance, then destroy it guarded by If-Match on its created_at (a reused id is never hit)."""
        created = self.instance(iid).get("created_at")
        if not created:
            raise ApiError(0, "no_created_at", f"instance {iid} has no created_at to guard the destroy with")
        return self._finish(self.delete(f"/instances/{iid}", if_match=created), wait, timeout)

    def terminal(self, iid, pane="tty2", lines=40):
        return self.get(f"/instances/{iid}/terminal", pane=pane, lines=lines)

    def capacity(self, project=None, template=None):
        """Capacity verdict for the token's own project (`GET /host/capacity?project=`, project-scoped document).
        Without `project` the token's single project is used; several or none is a usage error (nothing is guessed)."""
        if not project:
            projects = [p for p in (self.token_self().get("projects") or []) if p != "*"]
            if len(projects) != 1:
                raise ApiError(0, "project_required", "pass --project: the token does not name exactly one project")
            project = projects[0]
        return self.get("/host/capacity", project=project, template=template)


# -- CLI
def _print(doc):
    print(json.dumps(doc, indent=2, sort_keys=True))


def _build_parser():
    p = argparse.ArgumentParser(prog="fleet_api.py", description=__doc__.split("\n")[0])
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("token-check", help="show this token's scopes, projects and expiry")
    li = sub.add_parser("instances", help="list instances (all pages)")
    for f in ("project", "branch", "state", "label"):
        li.add_argument(f"--{f}")
    sub.add_parser("instance", help="show one instance").add_argument("id")
    sub.add_parser("job", help="show a job").add_argument("id")
    jl = sub.add_parser("job-log", help="print a job log from a byte offset")
    jl.add_argument("id")
    jl.add_argument("--offset", type=int, default=0)
    cap = sub.add_parser("capacity", help="capacity verdict for the own project (scoped document)")
    cap.add_argument("--project")
    cap.add_argument("--template")
    d = sub.add_parser("deploy", help="create an instance")
    d.add_argument("--project", required=True)
    for f in ("branch", "template", "label"):
        d.add_argument(f"--{f}")
    r = sub.add_parser("redeploy", help="redeploy an instance")
    r.add_argument("id")
    r.add_argument("--branch")
    r.add_argument("--template")
    r.add_argument("--force", action="store_true")
    for name in ("start", "stop"):
        sub.add_parser(name, help=f"{name} an instance").add_argument("id")
    x = sub.add_parser("destroy", help="destroy an instance (needs --confirm <same id>)")
    x.add_argument("id")
    x.add_argument("--confirm", help="repeat the instance id to confirm")
    t = sub.add_parser("terminal", help="read an agent's pane")
    t.add_argument("id")
    t.add_argument("--pane", default="tty2", choices=["tty1", "tty2"])
    t.add_argument("--lines", type=int, default=40)
    for name in ("deploy", "redeploy", "start", "stop", "destroy"):
        sp = sub.choices[name]
        sp.add_argument("--wait", action="store_true", help="poll the job until it is final")
        sp.add_argument("--timeout", type=int, default=600)
    return p


def run(args, api):
    c = args.cmd
    if c == "token-check":
        _print(api.token_self())
    elif c == "instances":
        _print(api.instances(project=args.project, branch=args.branch, state=args.state, label=args.label))
    elif c == "instance":
        _print(api.instance(args.id))
    elif c == "job":
        _print(api.job(args.id))
    elif c == "job-log":
        text, meta = api.job_log(args.id, args.offset)
        sys.stdout.write(text)
        print(f"next-offset={meta['next_offset']} state={meta['state']} done={int(meta['done'])}", file=sys.stderr)
    elif c == "capacity":
        _print(api.capacity(args.project, args.template))
    elif c == "terminal":
        doc = api.terminal(args.id, args.pane, args.lines)
        sys.stdout.write(doc.get("text", ""))
        if doc.get("truncated"):
            print("[truncated: the start was cut]", file=sys.stderr)
    else:
        if c == "destroy" and args.confirm != args.id:
            raise ApiError(0, "confirmation_required", f"destroy is irreversible: repeat the id with --confirm {args.id}")
        wait = {"wait": args.wait, "timeout": args.timeout}
        if c == "deploy":
            res = api.deploy(args.project, args.branch, args.template, args.label, **wait)
        elif c == "redeploy":
            res = api.redeploy(args.id, args.branch, args.template, args.force, **wait)
        else:
            res = getattr(api, c)(args.id, **wait)
        _print(res)
        if args.wait and "status" in res and res["status"] != "succeeded":  # no job id: nothing was waited for
            return 3
    return 0


def main(argv=None, environ=None, opener=None):
    args = _build_parser().parse_args(argv)
    try:
        return run(args, FleetApi(environ=environ, opener=opener))
    except ApiError as e:
        print(f"error: {e}", file=sys.stderr)
        return 2 if e.status == 0 and e.code in ("no_token", "no_url", "insecure_url", "confirmation_required",
                                                  "project_required") else 1


if __name__ == "__main__":
    sys.exit(main())
