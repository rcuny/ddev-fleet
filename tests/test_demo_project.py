"""The bundled demo project (repo `demo/`) — shipped so `fleet deploy demo`
works out of the box on a fresh install. These guard its shape and that a
registry mirroring the fresh-install seed resolves it."""

from pathlib import Path

import yaml

from fleet.core.registry import Registry

REPO_ROOT = Path(__file__).resolve().parents[1]
DEMO_DIR = REPO_ROOT / "demo"


def test_demo_source_files_present_and_minimal():
    # The landing page and its minimal DDEV config both ship.
    assert (DEMO_DIR / "web" / "index.php").is_file()
    cfg = yaml.safe_load((DEMO_DIR / ".ddev" / "config.yaml").read_text())
    assert cfg["type"] == "php"
    assert cfg["docroot"] == "web"
    # Simplest/fastest: no database container.
    assert cfg.get("omit_containers") == ["db"]


def test_seeded_demo_registry_resolves(tmp_path):
    # Mirrors the registry the fleet_user Ansible role seeds on first provision.
    reg = tmp_path / "fleet.yml"
    reg.write_text(
        "fleet:\n"
        "  domain: fleet.example.test\n"
        "projects:\n"
        "  demo:\n"
        "    git: file:///srv/fleet/_demo.git\n"
        "    default_branch: main\n"
        "    default_template: default\n"
        "    templates:\n"
        "      default:\n"
        "        post_deploy: [ddev start]\n"
    )
    registry = Registry.load(reg)
    resolved = registry.resolve("demo", "default", "main")
    assert resolved.instance_id == "demo--main"
    assert resolved.post_deploy == ["ddev start"]
