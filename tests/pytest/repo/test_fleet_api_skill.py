"""FLE-292: the shipped fleet-api skill client. Offline: a fake opener stands in for the network."""

import importlib.util
import io
import json
import sys
import urllib.error
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("fleet_api_skill", ROOT / "skills" / "fleet-api" / "bin" / "fleet_api.py")
fa = importlib.util.module_from_spec(SPEC)
sys.modules["fleet_api_skill"] = fa
SPEC.loader.exec_module(fa)

TOKEN = "flt_svc_SECRET123"
ENV = {"FLEET_API_TOKEN": TOKEN, "FLEET_API_URL": "https://fleet.example.com"}


class Resp:
    def __init__(self, body=b"{}", status=200, headers=None):
        self._b = body if isinstance(body, bytes) else json.dumps(body).encode()
        self.status, self.headers = status, headers or {}

    def read(self):
        return self._b

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False


def http_error(status, body=None, headers=None):
    return urllib.error.HTTPError("u", status, "x", headers or {}, io.BytesIO(json.dumps(body or {}).encode()))


class Fake:
    """Replays scripted answers (a Resp, or an exception to raise) and records the requests."""

    def __init__(self, *answers):
        self.answers, self.requests = list(answers), []

    def __call__(self, req, timeout=None):
        self.requests.append(req)
        a = self.answers.pop(0)
        if isinstance(a, Exception):
            raise a
        return a


def api(*answers, **kw):
    f = Fake(*answers)
    return fa.FleetApi(environ=ENV, opener=f, sleep=lambda s: f.requests.append(("sleep", s)), **kw), f


def test_missing_token_or_url_is_refused():
    with pytest.raises(fa.ApiError) as e:
        fa.FleetApi(environ={"FLEET_API_URL": "https://x.example.com"})
    assert e.value.code == "no_token"
    with pytest.raises(fa.ApiError) as e:
        fa.FleetApi(environ={"FLEET_API_TOKEN": TOKEN})
    assert e.value.code == "no_url"


@pytest.mark.parametrize("url", ["http://fleet.example.com", "ftp://x.example.com", "fleet.example.com"])
def test_plain_http_and_other_schemes_are_refused(url):
    with pytest.raises(fa.ApiError) as e:
        fa.FleetApi(environ={"FLEET_API_TOKEN": TOKEN, "FLEET_API_URL": url})
    assert e.value.code == "insecure_url"
    assert TOKEN not in str(e.value)


def test_bearer_header_and_path():
    a, f = api(Resp({"scopes": ["read"]}))
    assert a.token_self() == {"scopes": ["read"]}
    req = f.requests[0]
    assert req.full_url == "https://fleet.example.com/api/v1/tokens/self"
    assert req.get_header("Authorization") == f"Bearer {TOKEN}"


def test_redirect_handler_refuses():
    h = fa._NoRedirect()
    with pytest.raises(fa.ApiError) as e:
        h.redirect_request(None, None, 302, "Found", {}, "https://evil.example.org/")
    assert e.value.code == "redirect_refused"


def test_problem_json_error_is_parsed_and_masked():
    a, _ = api(http_error(403, {"code": "project_not_allowed", "detail": f"no {TOKEN}"}))
    with pytest.raises(fa.ApiError) as e:
        a.instance("blog--x")
    assert e.value.status == 403 and e.value.code == "project_not_allowed"
    assert TOKEN not in str(e.value) and "flt_***" in str(e.value)


def test_429_honours_retry_after_capped_at_60():
    a, f = api(http_error(429, headers={"Retry-After": "500"}), Resp({"ok": 1}))
    assert a.get("/instances/x") == {"ok": 1}
    assert ("sleep", 60) in f.requests


def test_429_on_a_write_retries_but_500_on_a_write_does_not():
    a, f = api(http_error(429, headers={"Retry-After": "2"}), Resp({"job": {"id": "j1"}}))
    assert a.post("/instances/x/start")["job"]["id"] == "j1"
    a, f = api(http_error(500, {"code": "internal_error"}), Resp())
    with pytest.raises(fa.ApiError):
        a.post("/instances/x/start")
    assert len(f.requests) == 1


def test_get_retries_5xx_then_gives_up():
    a, f = api(*[http_error(503)] * 4)
    with pytest.raises(fa.ApiError) as e:
        a.get("/jobs/j")
    assert e.value.status == 503 and len(f.requests) == 4 + 3  # 4 requests, 3 sleeps


def test_pagination_follows_next_cursor():
    a, f = api(Resp({"instances": [{"id": "a"}], "next_cursor": "c1"}), Resp({"instances": [{"id": "b"}], "next_cursor": None}))
    assert [i["id"] for i in a.instances(project="blog")] == ["a", "b"]
    assert "cursor=c1" in f.requests[1].full_url and "project=blog" in f.requests[0].full_url


def test_job_log_offset_and_headers():
    a, f = api(Resp(b"line\n", headers={"X-Next-Offset": "5", "X-Job-State": "running", "X-Log-Done": "0"}))
    text, meta = a.job_log("j1", 0)
    assert text == "line\n" and meta == {"next_offset": 5, "state": "running", "done": False}
    assert f.requests[0].full_url.endswith("/jobs/j1/log?offset=0")


def test_destroy_sends_if_match_from_created_at():
    a, f = api(Resp({"id": "blog--x", "created_at": "2026-01-01T10:00:00+00:00"}), Resp({"job": {"id": "j9"}}, 202))
    a.destroy("blog--x")
    req = f.requests[1]
    assert req.get_method() == "DELETE" and req.get_header("If-match") == '"2026-01-01T10:00:00+00:00"'


def test_cli_destroy_needs_confirm_and_sends_nothing(capsys):
    f = Fake()
    assert fa.main(["destroy", "blog--x"], environ=ENV, opener=f) == 2
    assert fa.main(["destroy", "blog--x", "--confirm", "other"], environ=ENV, opener=f) == 2
    assert f.requests == [] and "--confirm blog--x" in capsys.readouterr().err


def test_capacity_uses_the_scoped_endpoint_for_the_given_project(capsys):
    f = Fake(Resp({"schema": 1, "scope": "project", "project": "blog", "verdict": {"ok": True}}))
    assert fa.main(["capacity", "--project", "blog", "--template", "dev"], environ=ENV, opener=f) == 0
    assert f.requests[0].full_url.endswith("/api/v1/host/capacity?project=blog&template=dev")
    assert json.loads(capsys.readouterr().out)["verdict"]["ok"] is True


def test_capacity_defaults_to_the_tokens_single_project():
    a, f = api(Resp({"projects": ["blog"]}), Resp({"verdict": {"ok": False}}))
    assert a.capacity()["verdict"]["ok"] is False
    assert f.requests[0].full_url.endswith("/tokens/self") and f.requests[1].full_url.endswith("/host/capacity?project=blog")


@pytest.mark.parametrize("projects", [[], ["a", "b"], ["*"]])
def test_capacity_without_a_single_project_is_a_usage_error(projects, capsys):
    f = Fake(Resp({"projects": projects}))
    assert fa.main(["capacity"], environ=ENV, opener=f) == 2
    assert len(f.requests) == 1 and "project_required" in capsys.readouterr().err


def test_capacity_403_for_another_project_is_exit_1(capsys):
    f = Fake(http_error(403, {"code": "project_not_allowed", "detail": "no"}))
    assert fa.main(["capacity", "--project", "other"], environ=ENV, opener=f) == 1


def test_wait_without_a_job_id_does_not_exit_3(capsys):
    f = Fake(Resp({"accepted": True}, 202))
    assert fa.main(["start", "blog--x", "--wait"], environ=ENV, opener=f) == 0
    assert len(f.requests) == 1


def test_retry_after_http_date_falls_back_to_backoff():
    assert fa.retry_wait("Wed, 21 Oct 2026 07:28:00 GMT", 2) == 4
    assert fa.retry_wait("0", 1) == 2 and fa.retry_wait("5", 1) == 5


@pytest.mark.parametrize("url", ["http://localhost:8080", "http://127.0.0.1", "http://[::1]:9"])
def test_plain_http_is_allowed_on_loopback_only(url):
    assert fa.FleetApi(environ={"FLEET_API_TOKEN": TOKEN, "FLEET_API_URL": url}).base == url


def test_literal_token_is_masked_even_without_the_flt_shape():
    odd = "plainsecret-value-123"
    a = fa.FleetApi(environ={"FLEET_API_TOKEN": odd, "FLEET_API_URL": "https://fleet.example.com"},
                    opener=Fake(http_error(400, {"code": "x", "detail": f"bad {odd}"})))
    with pytest.raises(fa.ApiError) as e:
        a.get("/jobs/x")
    assert odd not in str(e.value) and "flt_***" in str(e.value)


def test_network_error_message_is_masked():
    err = urllib.error.URLError(f"connect failed for {TOKEN}")
    a, _ = api(err, err, err, err)
    with pytest.raises(fa.ApiError) as e:
        a.get("/jobs/x")
    assert e.value.code == "network" and TOKEN not in str(e.value)


def test_deploy_wait_polls_to_final_and_exit_code(capsys):
    f = Fake(Resp({"job": {"id": "j1"}}, 202), Resp({"id": "j1", "status": "running", "final": False}),
             Resp({"id": "j1", "status": "failed", "final": True}))
    assert fa.main(["deploy", "--project", "blog", "--branch", "main", "--wait"], environ=ENV, opener=f) == 3
    assert json.loads(f.requests[0].data) == {"project": "blog", "branch": "main"}


def test_terminal_prints_text(capsys):
    f = Fake(Resp({"text": "hello\n", "truncated": False}))
    assert fa.main(["terminal", "blog--x", "--lines", "5"], environ=ENV, opener=f) == 0
    assert capsys.readouterr().out == "hello\n"
    assert "pane=tty2" in f.requests[0].full_url and "lines=5" in f.requests[0].full_url

