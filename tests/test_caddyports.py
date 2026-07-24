from fleet.core import caddyports
from fleet.core.registry import PortProfile


def test_render_port_snippet_shape():
    out = caddyports.render_port_snippet(
        "fleet.example.test", PortProfile(name="playwright", public=9324, router=8323)
    )
    assert out == (
        "*.fleet.example.test:9324 {\n"
        "    reverse_proxy 127.0.0.1:8323\n"
        "    tls { on_demand }\n"
        "}\n"
    )


def test_write_port_snippet_is_atomic_leaves_no_tmp_file_behind(tmp_path):
    snippet_dir = tmp_path / "ports"
    snippet_dir.mkdir()
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    leftover = [p for p in snippet_dir.iterdir() if p.name != "playwright.conf"]
    assert leftover == []


def test_write_port_snippet_creates_parent_directory(tmp_path):
    snippet_dir = tmp_path / "does" / "not" / "exist"
    path = caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    assert path.exists()
    assert path == snippet_dir / "playwright.conf"


def test_remove_port_snippet_returns_false_when_nothing_to_remove(tmp_path):
    snippet_dir = tmp_path / "ports"
    assert caddyports.remove_port_snippet("ghost", snippet_dir=snippet_dir) is False


def test_remove_port_snippet_returns_true_when_removed(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    assert caddyports.remove_port_snippet("playwright", snippet_dir=snippet_dir) is True
    assert not (snippet_dir / "playwright.conf").exists()
