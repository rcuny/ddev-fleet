from pathlib import Path

from jinja2 import Environment, FileSystemLoader

TEMPLATE_DIR = Path(__file__).resolve().parents[1] / "ansible" / "roles" / "caddy" / "templates"


def _render() -> str:
    env = Environment(loader=FileSystemLoader(str(TEMPLATE_DIR)), keep_trailing_newline=True)
    return env.get_template("Caddyfile.j2").render(
        acme_email="contact@personal.example",
        fleet_domain="fleet.personal.example",
        fleet_daemon_port=8765,
        fleet_caddy_snippet_dir="/etc/caddy/fleet",
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


def test_caddyfile_admin_auth_imports_fleet_owned_snippet_not_inline_hash():
    out = _render()
    assert "import /etc/caddy/fleet/admin-auth.conf" in out
    # the hash must never be inlined directly in the rendered Caddyfile —
    # rotation must not require re-rendering/re-deploying this template
    assert "$2a$" not in out
    assert "$2b$" not in out


def test_caddyfile_admin_auth_import_is_inside_protected_basic_auth_block():
    out = _render()
    site_start = out.index("fleet.personal.example {")
    site_end = out.index("\n}", site_start)
    site_block = out[site_start:site_end]
    assert "basic_auth @protected {" in site_block
    assert "import /etc/caddy/fleet/admin-auth.conf" in site_block


def test_caddyfile_keeps_websocket_exemption_for_basic_auth():
    out = _render()
    assert "@protected not path /ws/*" in out


def test_caddyfile_instances_site_imports_per_instance_auth_snippets_via_glob():
    """Unlike the dashboard's literal admin-auth import, the per-instance
    import MUST be a glob — the instances/ dir can legitimately be empty
    (zero instances with auth enabled), and only a glob matching zero files
    is a silent Caddy no-op; a literal missing path is a hard error."""
    out = _render()
    site_start = out.index("*.fleet.personal.example {")
    site_end = out.index("\n}", site_start)
    site_block = out[site_start:site_end]
    assert "import /etc/caddy/fleet/instances/*.conf" in site_block


def test_caddyfile_instances_import_uses_wildcard_not_a_single_literal_file():
    """A literal single-file import (like the dashboard's admin-auth.conf)
    would be a hard error if no instance has auth enabled yet; the `*.conf`
    glob is required so an empty/missing instances/ dir is a no-op."""
    out = _render()
    assert "*.conf" in out
    assert "import /etc/caddy/fleet/instances/admin-auth.conf" not in out
