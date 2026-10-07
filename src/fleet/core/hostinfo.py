"""Identity of the host the daemon runs on, shown in the web UI: the server
hostname (page title and heading) and the Fleet version (footer).

Both are computed once per app by ``create_app`` -- neither can change without
a daemon restart. Neither lookup raises: the UI must render even on a box
without git or package metadata, so failures degrade to ``"unknown"``.
"""

import importlib.metadata
import socket
import subprocess
from pathlib import Path

# src/fleet/core/hostinfo.py -> repo root
_REPO_DIR = Path(__file__).resolve().parents[3]


def hostname() -> str:
    """The machine's hostname, or ``"unknown"`` if it is empty."""
    return socket.gethostname() or "unknown"


def fleet_version(repo_dir: Path = _REPO_DIR) -> str:
    """The Fleet version this checkout is running, e.g. ``v0.9.2``.

    The git tag is preferred over package metadata: the product is an editable
    install whose metadata is frozen at install time and carries the pyproject
    version, not necessarily what is checked out, whereas ``git describe``
    reflects the checkout and also shows commits past the tag on ``develop``
    (``v0.9.2-3-gabc1234``). Falls back to the package version (prefixed
    ``v``) when there is no usable git checkout, then to ``"unknown"``.
    """
    if (repo_dir / ".git").exists():
        try:
            result = subprocess.run(
                ["git", "describe", "--tags"],
                cwd=repo_dir,
                capture_output=True,
                text=True,
                timeout=5,
            )
        except (OSError, subprocess.TimeoutExpired):
            pass
        else:
            described = result.stdout.strip()
            if result.returncode == 0 and described:
                return described
    try:
        return "v" + importlib.metadata.version("ddev-fleet")
    except importlib.metadata.PackageNotFoundError:
        return "unknown"
