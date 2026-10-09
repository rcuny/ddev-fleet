import pytest

from fleet.core.assets import inject, push
from fleet.core.errors import FleetError, TokenError
from fleet.core.runner import RunResult
from fleet.core.tokens import build_context


def test_inject_returns_empty_list_when_assets_dir_missing(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    instance_dir = tmp_path / "instance"
    context = build_context("demo", "develop", "main", "fleet.example.test")

    assert inject(assets_dir, instance_dir, context) == []
    assert not instance_dir.exists()


def test_inject_copies_tree_including_dotdirs(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / ".ddev").mkdir(parents=True)
    (assets_dir / ".ddev" / "config.local.yaml").write_text("name: x\n", encoding="utf-8")
    (assets_dir / ".env").write_text("PROJECT=static\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    assert (instance_dir / ".ddev" / "config.local.yaml").exists()
    assert (instance_dir / ".env").exists()


def test_inject_substitutes_tokens_in_copied_text_file(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    (assets_dir / ".env").write_text("PROJECT=[[project]]\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    assert (instance_dir / ".env").read_text(encoding="utf-8") == "PROJECT=demo\n"


def test_inject_copies_binary_file_untouched(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    binary_content = b"binary\x00[[project]]\x00data"
    (assets_dir / "blob.bin").write_bytes(binary_content)
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    assert (instance_dir / "blob.bin").read_bytes() == binary_content


def test_inject_does_not_substitute_repo_only_files(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    (assets_dir / "asset-only.txt").write_text("token=[[project]]\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    (instance_dir / "repo-file.txt").write_text("token=[[project]]\n", encoding="utf-8")
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    assert (instance_dir / "asset-only.txt").read_text(encoding="utf-8") == "token=demo\n"
    assert (instance_dir / "repo-file.txt").read_text(encoding="utf-8") == "token=[[project]]\n"


def test_inject_returns_list_of_instance_side_paths(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    (assets_dir / "a.txt").write_text("static\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    copied = inject(assets_dir, instance_dir, context)

    assert copied == [instance_dir / "a.txt"]


def test_inject_raises_token_error_naming_the_file(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    (assets_dir / "bad.txt").write_text("token=[[nonexistent-token]]\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    try:
        inject(assets_dir, instance_dir, context)
        assert False, "expected TokenError"
    except TokenError as exc:
        assert str(instance_dir / "bad.txt") in str(exc)
        assert "[[nonexistent-token]]" in str(exc)


def test_inject_raises_when_rsync_fails(tmp_path):
    assets_dir = tmp_path / "assets"
    assets_dir.mkdir()
    (assets_dir / "x.txt").write_text("hi\n", encoding="utf-8")
    instance_dir = tmp_path / "inst"

    def failing_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=23, lines=["rsync: some error"])

    with pytest.raises(FleetError):
        inject(assets_dir, instance_dir, {}, runner=failing_runner)


def test_push_copies_file_into_assets_tree(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    src = tmp_path / "source" / "db.sql.gz"
    src.parent.mkdir(parents=True)
    src.write_bytes(b"dump-bytes")

    dest = push(assets_dir, src, "dumps/db.sql.gz")

    assert dest == assets_dir / "dumps" / "db.sql.gz"
    assert dest.read_bytes() == b"dump-bytes"


def test_push_raises_when_src_missing(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    src = tmp_path / "does-not-exist.sql"

    with pytest.raises(FleetError):
        push(assets_dir, src, "dumps/db.sql.gz")


def test_push_refuses_dest_rel_that_escapes_assets_dir(tmp_path):
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    src = tmp_path / "source.txt"
    src.write_text("x", encoding="utf-8")

    with pytest.raises(FleetError):
        push(assets_dir, src, "../../escaped.txt")


def test_inject_hard_links_dumps_instead_of_copying(tmp_path):
    """dumps/ must never be rsync-copied — it's shared via a hard link (same
    inode, zero extra disk) so multi-GB dumps aren't duplicated per instance."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    dump_src = assets_dir / "dumps" / "default.sql"
    dump_src.write_text("-- sql dump\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    dump_dest = instance_dir / "dumps" / "default.sql"
    assert dump_dest.read_text(encoding="utf-8") == "-- sql dump\n"
    assert dump_dest.stat().st_ino == dump_src.stat().st_ino


def test_inject_does_not_token_substitute_dumps_files(tmp_path):
    """Even a small dump file (under the token-substitution size limit) must
    never be rewritten in place — it would corrupt the shared inode backing
    every instance that hard-links it."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    (assets_dir / "dumps" / "default.sql").write_text("-- for [[project]]\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    dump_dest = instance_dir / "dumps" / "default.sql"
    assert dump_dest.read_text(encoding="utf-8") == "-- for [[project]]\n"


def test_inject_replaces_stale_dumps_copy_on_redeploy(tmp_path):
    """Backward compat: an existing instance with a stale full copy of the
    dump (from before dumps became shared) gets it replaced by a hard link
    on the next deploy, reclaiming the duplicated disk space."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    dump_src = assets_dir / "dumps" / "default.sql"
    dump_src.write_text("-- fresh dump\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    (instance_dir / "dumps").mkdir(parents=True)
    stale_dest = instance_dir / "dumps" / "default.sql"
    stale_dest.write_text("-- stale copy from an old deploy\n", encoding="utf-8")
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)

    assert stale_dest.read_text(encoding="utf-8") == "-- fresh dump\n"
    assert stale_dest.stat().st_ino == dump_src.stat().st_ino


def test_inject_leaves_already_linked_dumps_file_alone(tmp_path):
    """Idempotent: re-running inject() on an instance that's already
    correctly hard-linked must not error or needlessly relink."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    dump_src = assets_dir / "dumps" / "default.sql"
    dump_src.write_text("-- dump\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    inject(assets_dir, instance_dir, context)
    inject(assets_dir, instance_dir, context)  # second deploy, already linked

    dump_dest = instance_dir / "dumps" / "default.sql"
    assert dump_dest.stat().st_ino == dump_src.stat().st_ino


def test_inject_falls_back_to_copy_when_hard_link_fails(tmp_path, monkeypatch):
    """If hard-linking isn't possible (e.g. dumps end up on a different
    filesystem than instances), fall back to a real copy rather than
    leaving the dump missing."""
    import fleet.core.assets as assets_mod

    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    dump_src = assets_dir / "dumps" / "default.sql"
    dump_src.write_text("-- dump\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    def failing_link(*args, **kwargs):
        raise OSError("Invalid cross-device link")

    monkeypatch.setattr(assets_mod.os, "link", failing_link)

    inject(assets_dir, instance_dir, context)

    dump_dest = instance_dir / "dumps" / "default.sql"
    assert dump_dest.read_text(encoding="utf-8") == "-- dump\n"
    assert dump_dest.stat().st_ino != dump_src.stat().st_ino


def test_inject_with_no_dumps_dir_still_injects_other_assets(tmp_path):
    """A project with no dumps/ (e.g. installs via `drush si` instead of a
    DB import) must still deploy its other assets without error."""
    assets_dir = tmp_path / "assets" / "demo"
    assets_dir.mkdir(parents=True)
    (assets_dir / ".env").write_text("PROJECT=[[project]]\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    copied = inject(assets_dir, instance_dir, context)

    assert (instance_dir / ".env").read_text(encoding="utf-8") == "PROJECT=demo\n"
    assert not (instance_dir / "dumps").exists()
    assert copied == [instance_dir / ".env"]


def test_inject_dumps_paths_included_in_returned_copied_list(tmp_path):
    """The returned list feeds instances.py's git-exclude wiring — hard-linked
    dump files must still be excluded from the instance's git tree."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    (assets_dir / "dumps" / "default.sql").write_text("-- dump\n", encoding="utf-8")
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    context = build_context("demo", "develop", "main", "fleet.example.test")

    copied = inject(assets_dir, instance_dir, context)

    assert instance_dir / "dumps" / "default.sql" in copied


def test_inject_rsync_excludes_dumps_directory(tmp_path):
    """Sanity check on the rsync invocation itself: the dumps/ exclude flag
    is present so rsync never even attempts the multi-GB transfer."""
    assets_dir = tmp_path / "assets" / "demo"
    (assets_dir / "dumps").mkdir(parents=True)
    (assets_dir / "dumps" / "default.sql").write_text("x", encoding="utf-8")
    instance_dir = tmp_path / "instance"

    seen_cmds = []

    def spy_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        seen_cmds.append(cmd)
        return RunResult(returncode=0, lines=[])

    inject(assets_dir, instance_dir, {}, runner=spy_runner)

    assert any("--exclude=/dumps/" in c for c in seen_cmds[0])
