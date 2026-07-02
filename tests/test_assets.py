from fleet.core.assets import inject
from fleet.core.errors import TokenError
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
