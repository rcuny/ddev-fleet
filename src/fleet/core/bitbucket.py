"""Bitbucket Cloud REST call behind the `run-pipeline` webhook action
(spec FLE-11 §4): start a custom pipeline. Stdlib only and blocking, so the
daemon runs it off the event loop; tests monkeypatch `trigger_pipeline`.

The token is a Repository Access Token with ONLY the `pipeline:write` scope:
it can start pipelines but cannot push or merge, so a leak is bounded. It is
sent as a bearer header and never logged; neither are response headers or
bodies. Failures carry the HTTP status code only.
"""

import http.client
import json
import urllib.error
import urllib.parse
import urllib.request

from fleet.core.errors import FleetError

API_BASE = "https://api.bitbucket.org/2.0"
TIMEOUT_SECONDS = 8


class BitbucketError(FleetError):
    """The Bitbucket API call failed. `status` is the HTTP status code, or
    None for a timeout / connection failure; the message never contains the
    token or any response content."""

    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Never follow a redirect: urllib would replay the Authorization header
    to wherever the response points."""

    def redirect_request(self, *args, **kwargs):
        return None


def trigger_pipeline(repo: str, token: str, ref: str, pattern: str) -> int | str | None:
    """Start the custom pipeline `pattern` on branch `ref` of `repo`
    (`workspace/slug`). Returns the new pipeline's `build_number` (else its
    `uuid`, else None if the response carries neither). Raises
    `BitbucketError` on any HTTP error, timeout or connection failure."""
    url = f"{API_BASE}/repositories/{urllib.parse.quote(repo, safe='/')}/pipelines/"
    payload = {
        "target": {
            "type": "pipeline_ref_target",
            "ref_type": "branch",
            "ref_name": ref,
            "selector": {"type": "custom", "pattern": pattern},
        }
    }
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    opener = urllib.request.build_opener(_NoRedirect)
    try:
        with opener.open(request, timeout=TIMEOUT_SECONDS) as response:
            raw = response.read()
    except urllib.error.HTTPError as exc:
        raise BitbucketError(f"Bitbucket answered HTTP {exc.code}", status=exc.code) from None
    except (urllib.error.URLError, http.client.HTTPException, OSError, ValueError) as exc:
        # `from None`: the chained exception text can echo request details.
        raise BitbucketError(f"Bitbucket request failed ({type(exc).__name__})") from None
    try:
        body = json.loads(raw)
    except ValueError:
        return None
    if not isinstance(body, dict):
        return None
    number = body.get("build_number")
    if isinstance(number, int):
        return number
    uuid = body.get("uuid")
    return uuid if isinstance(uuid, str) else None
