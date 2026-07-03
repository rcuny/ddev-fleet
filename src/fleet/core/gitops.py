"""Clone/fetch/reset and dirty-worktree/unpushed-commit checks (spec §6)."""

import subprocess
from pathlib import Path

from fleet.core.errors import DirtyWorktreeError, FleetError
from fleet.core.runner import run_streamed


def clone(git_url: str, branch: str, dest: Path, *, runner=run_streamed) -> None:
    result = runner(["git", "clone", "--branch", branch, git_url, str(dest)])
    if result.returncode != 0:
        raise FleetError(
            f"git clone --branch {branch} {git_url} {dest} "
            f"failed with exit code {result.returncode}"
        )


def is_dirty(repo: Path) -> bool:
    result = subprocess.run(
        ["git", "status", "--porcelain"],
        cwd=str(repo),
        capture_output=True,
        text=True,
        check=True,
    )
    return bool(result.stdout.strip())


def has_unpushed(repo: Path) -> bool:
    upstream = subprocess.run(
        ["git", "rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"],
        cwd=str(repo),
        capture_output=True,
        text=True,
    )
    if upstream.returncode != 0:
        return False

    result = subprocess.run(
        ["git", "rev-list", "@{u}..HEAD"],
        cwd=str(repo),
        capture_output=True,
        text=True,
        check=True,
    )
    return bool(result.stdout.strip())


def update(repo: Path, branch: str, *, force: bool = False, runner=run_streamed) -> None:
    if not force and (is_dirty(repo) or has_unpushed(repo)):
        raise DirtyWorktreeError(
            f"{repo}: worktree is dirty or has unpushed commits; refusing to update "
            "without --force"
        )

    result = runner(["git", "fetch", "origin"], cwd=repo)
    if result.returncode != 0:
        raise FleetError(f"git fetch origin failed in {repo} with exit code {result.returncode}")

    result = runner(["git", "checkout", branch], cwd=repo)
    if result.returncode != 0:
        raise FleetError(
            f"git checkout {branch} failed in {repo} with exit code {result.returncode}"
        )

    result = runner(["git", "reset", "--hard", f"origin/{branch}"], cwd=repo)
    if result.returncode != 0:
        raise FleetError(
            f"git reset --hard origin/{branch} failed in {repo} with exit code {result.returncode}"
        )
