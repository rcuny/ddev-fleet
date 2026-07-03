import subprocess

import pytest

from fleet.core import gitops
from fleet.core.errors import DirtyWorktreeError, FleetError

try:
    from .conftest import FakeRunner
except ImportError:
    from conftest import FakeRunner


def _run_git(cmd, cwd):
    subprocess.run(cmd, cwd=str(cwd), check=True, capture_output=True, text=True)


def test_clone_checks_out_requested_branch(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    assert (dest / "README.md").read_text(encoding="utf-8") == "hello\n"


def test_is_dirty_false_on_clean_clone(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    assert gitops.is_dirty(dest) is False


def test_is_dirty_true_after_local_modification(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    (dest / "README.md").write_text("changed\n", encoding="utf-8")
    assert gitops.is_dirty(dest) is True


def test_has_unpushed_false_with_no_local_commits(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    assert gitops.has_unpushed(dest) is False


def test_has_unpushed_true_after_local_commit(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    (dest / "new.txt").write_text("new\n", encoding="utf-8")
    _run_git(["git", "add", "new.txt"], cwd=dest)
    _run_git(["git", "config", "user.email", "test@example.test"], cwd=dest)
    _run_git(["git", "config", "user.name", "Test"], cwd=dest)
    _run_git(["git", "commit", "-m", "local only"], cwd=dest)
    assert gitops.has_unpushed(dest) is True


def test_has_unpushed_false_with_no_upstream_configured(tmp_path):
    solo = tmp_path / "solo"
    solo.mkdir()
    _run_git(["git", "init", "--initial-branch=main", str(solo)], cwd=tmp_path)
    _run_git(["git", "config", "user.email", "test@example.test"], cwd=solo)
    _run_git(["git", "config", "user.name", "Test"], cwd=solo)
    (solo / "f.txt").write_text("x\n", encoding="utf-8")
    _run_git(["git", "add", "f.txt"], cwd=solo)
    _run_git(["git", "commit", "-m", "init"], cwd=solo)
    assert gitops.has_unpushed(solo) is False


def test_update_refuses_dirty_worktree_without_force(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    (dest / "README.md").write_text("dirty\n", encoding="utf-8")

    import pytest

    from fleet.core.errors import DirtyWorktreeError

    with pytest.raises(DirtyWorktreeError):
        gitops.update(dest, "main")


def test_update_dirty_refusal_happens_before_any_runner_call(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    (dest / "README.md").write_text("dirty\n", encoding="utf-8")

    fake = FakeRunner()
    with pytest.raises(DirtyWorktreeError):
        gitops.update(dest, "main", runner=fake)
    assert fake.calls == []


def test_update_proceeds_with_force_and_resets_to_origin_tip(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    (dest / "README.md").write_text("dirty\n", encoding="utf-8")

    # Push a new commit from a second clone so origin/main moves ahead.
    second = tmp_path / "second"
    gitops.clone(str(git_repo["origin"]), "main", second)
    (second / "README.md").write_text("updated upstream\n", encoding="utf-8")
    _run_git(["git", "config", "user.email", "test@example.test"], cwd=second)
    _run_git(["git", "config", "user.name", "Test"], cwd=second)
    _run_git(["git", "commit", "-am", "upstream update"], cwd=second)
    _run_git(["git", "push", "origin", "main"], cwd=second)

    gitops.update(dest, "main", force=True)

    assert (dest / "README.md").read_text(encoding="utf-8") == "updated upstream\n"
    assert gitops.is_dirty(dest) is False


def test_is_dirty_raises_fleet_error_on_non_repo_dir(tmp_path):
    not_a_repo = tmp_path / "not-a-repo"
    not_a_repo.mkdir()
    with pytest.raises(FleetError):
        gitops.is_dirty(not_a_repo)


def test_has_unpushed_false_on_detached_head(git_repo, tmp_path):
    dest = tmp_path / "cloned"
    gitops.clone(str(git_repo["origin"]), "main", dest)
    _run_git(["git", "checkout", "--detach", "HEAD"], cwd=dest)
    assert gitops.has_unpushed(dest) is False
