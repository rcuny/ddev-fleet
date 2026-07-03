import pytest

from fleet.core.errors import TokenError
from fleet.core.tokens import build_context, env_vars, substitute_file, substitute_text


def test_build_context_composes_fqdn():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert context == {
        "instance-id": "oak--develop",
        "project": "oak",
        "instance": "develop",
        "branch": "develop",
        "fleet-domain": "fleet.example.test",
        "instance-fqdn": "oak--develop.fleet.example.test",
    }


def test_substitute_text_replaces_known_tokens():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    text = "name: [[instance-id]]\nproject_tld: [[fleet-domain]]\n"
    result = substitute_text(text, context)
    assert result == "name: oak--develop\nproject_tld: fleet.example.test\n"


def test_substitute_text_raises_on_unresolved_token():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    with pytest.raises(TokenError) as exc_info:
        substitute_text("value=[[missing-token]]", context)
    assert "[[missing-token]]" in str(exc_info.value)


def test_substitute_file_skips_binary_content(tmp_path):
    path = tmp_path / "blob.bin"
    path.write_bytes(b"abc\x00def[[project]]")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is False
    assert path.read_bytes() == b"abc\x00def[[project]]"


def test_substitute_file_skips_large_files(tmp_path):
    path = tmp_path / "big.txt"
    with open(path, "wb") as fh:
        fh.truncate(1024 * 1024 + 1)  # size only — no real 1 MiB write
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is False
    assert path.stat().st_size == 1024 * 1024 + 1


def test_substitute_file_rewrites_small_text_file(tmp_path):
    path = tmp_path / "config.yaml"
    path.write_text("name: [[instance-id]]\n", encoding="utf-8")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is True
    assert path.read_text(encoding="utf-8") == "name: oak--develop\n"


def test_substitute_file_raises_names_the_file(tmp_path):
    path = tmp_path / "config.yaml"
    path.write_text("name: [[missing-token]]\n", encoding="utf-8")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    with pytest.raises(TokenError) as exc_info:
        substitute_file(path, context)
    assert str(path) in str(exc_info.value)
    assert "[[missing-token]]" in str(exc_info.value)


def test_env_vars_maps_context_to_fleet_prefixed_names():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert env_vars(context) == {
        "FLEET_INSTANCE_ID": "oak--develop",
        "FLEET_PROJECT": "oak",
        "FLEET_INSTANCE": "develop",
        "FLEET_BRANCH": "develop",
        "FLEET_INSTANCE_FQDN": "oak--develop.fleet.example.test",
        "FLEET_DOMAIN": "fleet.example.test",
    }
