from pathlib import Path

from fleet.core.instances import read_instance_branch


def _write_yaml(tmp_path: Path, text: str) -> Path:
    d = tmp_path / "oak--click-3"
    (d / ".fleet").mkdir(parents=True)
    (d / ".fleet" / "instance.yml").write_text(text, encoding="utf-8")
    return d


def test_read_instance_branch_returns_stored_branch(tmp_path):
    d = _write_yaml(tmp_path, "project: oak\nbranch: feature/OAKS-1762-make-card-clickable\n")
    assert read_instance_branch(d) == "feature/OAKS-1762-make-card-clickable"


def test_read_instance_branch_missing_file_returns_empty(tmp_path):
    d = tmp_path / "oak--click-3"
    d.mkdir()
    assert read_instance_branch(d) == ""


def test_read_instance_branch_no_branch_key_returns_empty(tmp_path):
    d = _write_yaml(tmp_path, "project: oak\n")
    assert read_instance_branch(d) == ""


def test_read_instance_branch_bare_scalar_returns_empty(tmp_path):
    d = _write_yaml(tmp_path, "just a string\n")
    assert read_instance_branch(d) == ""


def test_read_instance_branch_list_returns_empty(tmp_path):
    d = _write_yaml(tmp_path, "- one\n- two\n")
    assert read_instance_branch(d) == ""
