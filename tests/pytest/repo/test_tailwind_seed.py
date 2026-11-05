"""FLE-193: install/lib/tailwind-seed.sh caches the Tailwind CLI so builds never need a live GitHub download."""

import hashlib
import os
import subprocess
from pathlib import Path

import pytest

SCRIPT = Path(__file__).resolve().parents[3] / "install" / "lib" / "tailwind-seed.sh"
VERSION = "v4.3.3"
NAME = "tailwindcss-linux-x64"
PAYLOAD = b"#!/bin/sh\necho tailwind\n"


def _release(root: Path, payload: bytes = PAYLOAD, sum_hash: str | None = None, sums: bool = True) -> str:
    """A fake GitHub releases/download tree served through file:// URLs; returns the base URL."""
    rel = root / "releases" / VERSION
    rel.mkdir(parents=True)
    (rel / NAME).write_bytes(payload)
    if sums:
        digest = sum_hash or hashlib.sha256(PAYLOAD).hexdigest()
        (rel / "sha256sums.txt").write_text(f"{digest}  ./{NAME}\n{'0' * 64}  ./tailwindcss-macos-arm64\n")
    return f"file://{root / 'releases'}"


def _run(tmp: Path, base: str, version: str = VERSION) -> subprocess.CompletedProcess:
    env = {
        **os.environ,
        "FLEET_TAILWIND_BASE_URL": base,
        "FLEET_TAILWIND_ATTEMPTS": "1",
        "FLEET_TAILWIND_RETRY_DELAY": "0",
        "FLEET_TAILWIND_ARCH": "x64",
    }
    return subprocess.run(
        ["bash", str(SCRIPT), version, str(tmp / "cache"), str(tmp / "app" / "var" / "tailwind")],
        env=env,
        capture_output=True,
        text=True,
    )


def test_miss_downloads_verifies_caches_and_seeds(tmp_path):
    result = _run(tmp_path, _release(tmp_path))
    assert result.returncode == 0, result.stderr
    dest = tmp_path / "app/var/tailwind" / VERSION / NAME
    assert dest.read_bytes() == PAYLOAD
    assert os.access(dest, os.X_OK)
    assert (tmp_path / "cache" / VERSION / NAME).read_bytes() == PAYLOAD
    assert (tmp_path / "cache" / VERSION / f"{NAME}.sha256").read_text().strip() == hashlib.sha256(PAYLOAD).hexdigest()


def test_cache_hit_works_with_github_unreachable(tmp_path):
    assert _run(tmp_path, _release(tmp_path)).returncode == 0
    (tmp_path / "app/var/tailwind" / VERSION / NAME).unlink()
    result = _run(tmp_path, "file:///nonexistent-releases")  # nothing to download from
    assert result.returncode == 0, result.stderr
    assert "cache hit" in result.stdout
    assert (tmp_path / "app/var/tailwind" / VERSION / NAME).read_bytes() == PAYLOAD


def test_checksum_mismatch_fails_and_caches_nothing(tmp_path):
    result = _run(tmp_path, _release(tmp_path, sum_hash="a" * 64))
    assert result.returncode != 0
    assert "checksum mismatch" in result.stdout
    assert not (tmp_path / "cache" / VERSION / NAME).exists()
    assert not (tmp_path / "app/var/tailwind" / VERSION / NAME).exists()


def test_missing_checksums_fail(tmp_path):
    assert _run(tmp_path, _release(tmp_path, sums=False)).returncode != 0
    assert not (tmp_path / "cache" / VERSION / NAME).exists()


def test_unreachable_without_cache_fails(tmp_path):
    result = _run(tmp_path, "file:///nonexistent-releases")
    assert result.returncode != 0
    assert not (tmp_path / "app/var/tailwind" / VERSION / NAME).exists()


def test_corrupt_cache_is_replaced(tmp_path):
    base = _release(tmp_path)
    assert _run(tmp_path, base).returncode == 0
    (tmp_path / "cache" / VERSION / NAME).write_bytes(b"corrupt")
    (tmp_path / "app/var/tailwind" / VERSION / NAME).write_bytes(b"")
    assert _run(tmp_path, base).returncode == 0
    assert (tmp_path / "cache" / VERSION / NAME).read_bytes() == PAYLOAD
    assert (tmp_path / "app/var/tailwind" / VERSION / NAME).read_bytes() == PAYLOAD


def test_version_bump_downloads_once_and_prunes_old_cache(tmp_path):
    assert _run(tmp_path, _release(tmp_path)).returncode == 0
    new = tmp_path / "bump"
    rel = new / "releases" / "v4.4.0"
    rel.mkdir(parents=True)
    (rel / NAME).write_bytes(PAYLOAD)
    (rel / "sha256sums.txt").write_text(f"{hashlib.sha256(PAYLOAD).hexdigest()}  {NAME}\n")
    assert _run(tmp_path, f"file://{new / 'releases'}", "v4.4.0").returncode == 0
    assert (tmp_path / "cache/v4.4.0" / NAME).is_file()
    assert not (tmp_path / "cache" / VERSION).exists()


@pytest.mark.parametrize("bad", ["4.3.3", "v4.3", "v4.3.3/../x", ""])
def test_rejects_invalid_version(tmp_path, bad):
    assert _run(tmp_path, "file:///x", bad).returncode == 64


def test_step_80_seeds_before_tailwind_build():
    step = (SCRIPT.parents[1] / "steps" / "80-code.sh").read_text()
    assert step.index("\n  seed_tailwind_binary\n") < step.index("fleet_console tailwind:build")
