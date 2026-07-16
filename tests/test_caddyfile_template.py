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
        fleet_typesense_public_port=9108,
    )


def test_caddyfile_no_longer_exposes_typesense_path_route():
    out = _render()
    assert "/_typesense" not in out
    assert "handle /_typesense/* {" not in out


def test_caddyfile_keeps_web_catchall():
    out = _render()
    assert "reverse_proxy 127.0.0.1:8080" in out
    # fleet UI vhost unchanged
    assert "reverse_proxy 127.0.0.1:8765" in out


def test_caddyfile_exposes_dedicated_typesense_public_port_site():
    out = _render()
    assert "*.fleet.personal.example:9108 {" in out
    assert "reverse_proxy 127.0.0.1:8108" in out


def test_caddyfile_typesense_site_has_on_demand_tls():
    out = _render()
    ts_site_start = out.index("*.fleet.personal.example:9108 {")
    ts_site_end = out.index("\n}", ts_site_start)
    ts_site_block = out[ts_site_start:ts_site_end]
    assert "on_demand" in ts_site_block
