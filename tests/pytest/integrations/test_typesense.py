import json

import pytest

from fleet.core.errors import TypesenseError
from fleet.core.instances import FleetPaths
from fleet.core.secrets import read_secrets
from fleet.core.secretstore import SecretStore
from fleet.core.typesense import (
    ensure_project_keys,
    generate_key,
    register_search_key,
)
from tests.pytest.fakegpg import FakeGpg


def test_generate_key_returns_hex_string_of_expected_length():
    key = generate_key()
    assert isinstance(key, str)
    assert len(key) == 48  # secrets.token_hex(24) -> 48 hex chars
    int(key, 16)  # must be valid hex


def test_generate_key_returns_different_keys_each_call():
    assert generate_key() != generate_key()


def _store(tmp_path, gpg=None):
    paths = FleetPaths.from_home(tmp_path / "home")
    return paths, SecretStore(paths, gpg=gpg or FakeGpg())


def test_ensure_project_keys_generates_and_persists_keys(tmp_path):
    paths, store = _store(tmp_path)

    admin_key, search_key = ensure_project_keys(store, "demo")

    assert admin_key
    assert search_key
    assert admin_key != search_key

    persisted = read_secrets(paths.project_secrets / "demo.env")
    assert persisted["TYPESENSE_API_KEY"] == admin_key
    assert persisted["FLEET_TYPESENSE_SEARCH_KEY"] == search_key


def test_ensure_project_keys_is_idempotent(tmp_path):
    _, store = _store(tmp_path)

    first_admin, first_search = ensure_project_keys(store, "demo")
    second_admin, second_search = ensure_project_keys(store, "demo")

    assert first_admin == second_admin
    assert first_search == second_search


def test_ensure_project_keys_preserves_other_secrets(tmp_path):
    paths, store = _store(tmp_path)
    store.set("demo", "SLACK_BOT_TOKEN", "xoxb-test")

    ensure_project_keys(store, "demo")

    assert read_secrets(paths.project_secrets / "demo.env")["SLACK_BOT_TOKEN"] == "xoxb-test"


def test_ensure_project_keys_writes_encrypted_when_a_host_key_exists(tmp_path):
    paths = FleetPaths.from_home(tmp_path / "home")
    gpg = FakeGpg()
    gpg.install_key(paths.gnupg)
    store = SecretStore(paths, gpg=gpg)

    admin_key, search_key = ensure_project_keys(store, "demo")

    assert not (paths.project_secrets / "demo.env").exists()
    assert store.names("demo") == [
        ("FLEET_TYPESENSE_SEARCH_KEY", "encrypted"),
        ("TYPESENSE_API_KEY", "encrypted"),
    ]
    assert ensure_project_keys(store, "demo") == (admin_key, search_key)


class _FakeResponse:
    def __init__(self, body: dict, status: int = 200):
        self._body = json.dumps(body).encode("utf-8")
        self.status = status

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class _FakeOpener:
    """Records every Request passed to .open() and returns scripted responses
    in order (or a single response reused for every call, if `responses` is
    given as a single-item list)."""

    def __init__(self, responses):
        self._responses = list(responses)
        self.requests = []

    def open(self, request, timeout=None):
        self.requests.append(request)
        return self._responses.pop(0)


def test_register_search_key_skips_post_when_key_already_present():
    get_response = _FakeResponse({"keys": [{"description": "fleet-search-only"}]})
    opener = _FakeOpener([get_response])

    register_search_key(
        http_port=8108,
        host="demo--develop.fleet.example.test",
        admin_key="admin-key-123",
        search_key="search-key-456",
        opener=opener,
    )

    assert len(opener.requests) == 1
    get_request = opener.requests[0]
    assert get_request.get_method() == "GET"
    assert get_request.full_url == "http://127.0.0.1:8108/keys"


def test_register_search_key_posts_when_key_absent():
    get_response = _FakeResponse({"keys": []})
    post_response = _FakeResponse({"id": 1})
    opener = _FakeOpener([get_response, post_response])

    register_search_key(
        http_port=8108,
        host="demo--develop.fleet.example.test",
        admin_key="admin-key-123",
        search_key="search-key-456",
        opener=opener,
    )

    assert len(opener.requests) == 2
    get_request, post_request = opener.requests

    assert get_request.get_method() == "GET"
    assert get_request.full_url == "http://127.0.0.1:8108/keys"
    assert get_request.get_header("X-typesense-api-key") == "admin-key-123"
    assert get_request.get_header("Host") == "demo--develop.fleet.example.test"

    assert post_request.get_method() == "POST"
    assert post_request.full_url == "http://127.0.0.1:8108/keys"
    assert post_request.get_header("X-typesense-api-key") == "admin-key-123"
    assert post_request.get_header("Host") == "demo--develop.fleet.example.test"

    body = json.loads(post_request.data.decode("utf-8"))
    assert body == {
        "value": "search-key-456",
        "description": "fleet-search-only",
        "actions": ["documents:search"],
        "collections": ["*"],
    }


def test_register_search_key_raises_typesense_error_on_connection_failure():
    class _FailingOpener:
        def open(self, request, timeout=None):
            raise OSError("connection refused")

    with pytest.raises(TypesenseError):
        register_search_key(
            http_port=8108,
            host="demo--develop.fleet.example.test",
            admin_key="admin-key-123",
            search_key="search-key-456",
            opener=_FailingOpener(),
        )
