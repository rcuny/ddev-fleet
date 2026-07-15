from pathlib import Path

from jinja2 import Environment, FileSystemLoader

TEMPLATE_DIR = Path(__file__).resolve().parents[1] / "ansible" / "roles" / "caddy" / "templates"


def _render() -> str:
    env = Environment(loader=FileSystemLoader(str(TEMPLATE_DIR)), keep_trailing_newline=True)
    return env.get_template("Caddyfile.j2").render(
        acme_email="contact@personal.example",
        fleet_domain="fleet.personal.example",
        fleet_daemon_port=8765,
        fleet_admin_user="admin",
        fleet_admin_bcrypt_hash="$2a$14$abcdefghijklmnopqrstuv",
        ddev_router_http_port=8080,
        ddev_router_https_port=8443,
        ddev_typesense_http_port=8108,
    )


def test_caddyfile_exposes_typesense_path_route():
    out = _render()
    assert "handle /_typesense/* {" in out
    assert "uri strip_prefix /_typesense" in out
    assert "reverse_proxy 127.0.0.1:8108" in out


def test_caddyfile_keeps_web_catchall():
    out = _render()
    assert "reverse_proxy 127.0.0.1:8080" in out
    # fleet UI vhost unchanged
    assert "reverse_proxy 127.0.0.1:8765" in out
