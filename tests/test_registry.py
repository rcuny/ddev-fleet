from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import PortProfile, Registry


def _write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


def test_resolve_uses_project_default_template_post_deploy(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "main")

    assert resolved.project == "demo"
    assert resolved.template == "default"
    assert resolved.branch == "main"
    assert resolved.label == "main"
    assert resolved.post_deploy == ["echo project-default"]
    assert resolved.instance_id == "demo--main"


def test_resolve_uses_named_template_post_deploy(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "custom", "feature-x")

    assert resolved.branch == "feature-x"
    assert resolved.label == "feature-x"
    assert resolved.post_deploy == ["echo instance-override"]
    assert resolved.instance_id == "demo--feature-x"


def test_resolve_label_defaults_to_slugified_branch(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "feature/Foo Bar")

    assert resolved.label == "feature-foo-bar"
    assert resolved.instance_id == "demo--feature-foo-bar"


def test_resolve_explicit_label_overrides_slugified_branch(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "feature/foo", label="mylabel")

    assert resolved.branch == "feature/foo"
    assert resolved.label == "mylabel"
    assert resolved.instance_id == "demo--mylabel"


def test_registry_properties(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    assert registry.domain == "fleet.example.test"
    assert registry.project_keys() == ["demo"]
    assert registry.has_project("demo") is True
    assert registry.has_project("nope") is False
    assert set(registry.template_keys("demo")) == {"default", "custom"}
    assert registry.git_url("demo") == "git@example.test:org/demo.git"
    assert registry.project_defaults("demo") == ("default", "main")
    assert registry.additional_hostnames("demo") == []


def test_project_defaults_are_none_when_not_configured(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  bare:
    git: git@example.test:org/bare.git
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    assert registry.project_defaults("bare") == (None, None)


def test_additional_hostnames_returns_configured_list(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - www
      - api
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    assert registry.additional_hostnames("demo") == ["www", "api"]


def test_typesense_enabled(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    typesense: true
    templates:
      default:
        post_deploy:
          - echo hi
  plain:
    git: git@example.test:org/plain.git
    default_template: default
    templates:
      default:
        post_deploy:
          - echo hi
"""
    path = _write(fleet_home / "fleet.yml", text)
    registry = Registry.load(path)
    assert registry.typesense_enabled("demo") is True
    assert registry.typesense_enabled("plain") is False


def test_load_missing_git_key_names_the_key(fleet_home, sample_registry_text):
    broken = sample_registry_text.replace("    git: git@example.test:org/demo.git\n", "")
    path = _write(fleet_home / "fleet.yml", broken)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "projects.demo.git" in str(exc_info.value)


def test_load_invalid_project_key_names_the_key(fleet_home, sample_registry_text):
    broken = sample_registry_text.replace("  demo:\n", "  Bad_Project:\n").replace(
        "    git: git@example.test:org/demo.git\n",
        "    git: git@example.test:org/demo.git\n",
    )
    path = _write(fleet_home / "fleet.yml", broken)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "Bad_Project" in str(exc_info.value)


def test_load_template_with_branch_key_raises(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default:
        branch: main
"""
    path = _write(fleet_home / "fleet.yml", registry_text)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "projects.demo.templates.default.branch" in str(exc_info.value)


def test_resolve_unknown_project_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("nonexistent", "default", "main")


def test_resolve_unknown_template_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("demo", "nonexistent", "main")


def test_resolve_missing_branch_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("demo", "default", "")


def test_resolve_with_no_post_deploy_returns_empty_list(fleet_home):
    """When the resolved template defines no post_deploy, resolve returns an
    empty list."""
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  testproj:
    git: git@example.test:org/testproj.git
    templates:
      no-deploy: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("testproj", "no-deploy", "main")

    assert resolved.post_deploy == []


def test_git_bot_defaults_from_domain(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    assert registry.git_bot() == ("ddev-fleet bot", "bot@fleet.example.test")


def test_git_bot_uses_explicit_name_and_email(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test
  git_bot_name: Custom Bot
  git_bot_email: custom@bot.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    assert registry.git_bot() == ("Custom Bot", "custom@bot.example.test")


def test_git_bot_project_override_returns_project_identity(fleet_home):
    """A project-level `git_bot: {name, email}` overrides the fleet default
    for that project only; other projects keep the fleet identity."""
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    git_bot:
      name: Sample Developer
      email: sample@dev.example.test
    templates:
      default: {}
  other:
    git: git@example.test:org/other.git
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))

    assert registry.git_bot("oak") == ("Sample Developer", "sample@dev.example.test")
    assert registry.git_bot("other") == ("ddev-fleet bot", "bot@fleet.example.test")
    assert registry.git_bot() == ("ddev-fleet bot", "bot@fleet.example.test")


def test_git_bot_project_partial_override_falls_back_to_default(fleet_home):
    """A project `git_bot` mapping that sets only one of name/email inherits
    the fleet default for the missing field."""
    registry_text = """\
fleet:
  domain: fleet.example.test
  git_bot_name: Base Bot
  git_bot_email: base@bot.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    git_bot:
      email: sample@dev.example.test
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))

    assert registry.git_bot("oak") == ("Base Bot", "sample@dev.example.test")


def test_git_bot_project_opt_out_returns_none(fleet_home):
    """A project-level `git_bot: false` disables env injection for that
    project (option 1) so the project's own git config can take effect."""
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    git_bot: false
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))

    assert registry.git_bot("oak") is None


def test_git_bot_fleet_global_opt_out_returns_none(fleet_home):
    """`fleet.git_bot: false` disables env injection fleet-wide, but a project
    override still wins."""
    registry_text = """\
fleet:
  domain: fleet.example.test
  git_bot: false

projects:
  oak:
    git: git@example.test:org/oak.git
    git_bot:
      name: Sample Developer
      email: sample@dev.example.test
    templates:
      default: {}
  other:
    git: git@example.test:org/other.git
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))

    assert registry.git_bot() is None
    assert registry.git_bot("other") is None
    assert registry.git_bot("oak") == ("Sample Developer", "sample@dev.example.test")


def test_git_bot_invalid_shape_raises_registry_error(fleet_home):
    """A project `git_bot` that is neither a mapping nor `false` is a config
    error caught at load time with an actionable message."""
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    git_bot: "sample"
    templates:
      default: {}
"""
    with pytest.raises(RegistryError, match="projects.oak.git_bot"):
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))


def test_port_profile_returns_explicit_entry(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test
  ports:
    playwright: { public: 9324, router: 8323 }

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))
    assert registry.port_profile("playwright") == PortProfile(
        name="playwright", public=9324, router=8323
    )


def test_port_profile_typesense_falls_back_to_legacy_default_when_undefined(
    fleet_home, sample_registry_text
):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    assert registry.port_profile("typesense") == PortProfile(
        name="typesense", public=9108, router=8108
    )


def test_port_profile_explicit_typesense_entry_overrides_legacy_default(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test
  ports:
    typesense: { public: 9200, router: 8200 }

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))
    assert registry.port_profile("typesense") == PortProfile(
        name="typesense", public=9200, router=8200
    )


def test_port_profile_unknown_name_raises(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    with pytest.raises(RegistryError, match="unknown port name 'bogus'"):
        registry.port_profile("bogus")


def test_fleet_ports_invalid_name_format_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    Bad_Name: { public: 9200, router: 8200 }

projects: {}
"""
    with pytest.raises(RegistryError, match="fleet.ports.Bad_Name"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_wrong_shape_missing_router_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    ts-dashboard: { public: 9111 }

projects: {}
"""
    with pytest.raises(RegistryError, match="fleet.ports.ts-dashboard"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_wrong_shape_extra_key_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    bad: { public: 9200, router: 8200, proto: udp }

projects: {}
"""
    with pytest.raises(RegistryError, match="fleet.ports.bad"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_out_of_range_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    toohigh: { public: 70000, router: 8200 }

projects: {}
"""
    with pytest.raises(RegistryError, match="fleet.ports.toohigh.public"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


@pytest.mark.parametrize("value", [0, 65536])
def test_fleet_ports_public_range_boundary_raises(fleet_home, value):
    text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    bad: {{ public: {value}, router: 9200 }}

projects: {{}}
"""
    with pytest.raises(RegistryError, match="fleet.ports.bad.public"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


@pytest.mark.parametrize("value", [0, 65536])
def test_fleet_ports_router_range_boundary_raises(fleet_home, value):
    text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    bad: {{ public: 9200, router: {value} }}

projects: {{}}
"""
    with pytest.raises(RegistryError, match="fleet.ports.bad.router"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


@pytest.mark.parametrize("value", [1, 65535])
def test_fleet_ports_public_range_boundary_loads(fleet_home, value):
    text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    ok: {{ public: {value}, router: 9200 }}

projects: {{}}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.port_profile("ok") == PortProfile(name="ok", public=value, router=9200)


@pytest.mark.parametrize("value", [1, 65535])
def test_fleet_ports_router_range_boundary_loads(fleet_home, value):
    text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    ok: {{ public: 9200, router: {value} }}

projects: {{}}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.port_profile("ok") == PortProfile(name="ok", public=9200, router=value)


@pytest.mark.parametrize("reserved", [22, 80, 443, 8765])
def test_fleet_ports_reserved_public_port_raises(fleet_home, reserved):
    text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    bad: {{ public: {reserved}, router: 8200 }}

projects: {{}}
"""
    with pytest.raises(RegistryError, match="reserved"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_router_collides_with_ddev_router_http_port_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    bad: { public: 9200, router: 8080 }

projects: {}
"""
    with pytest.raises(RegistryError, match="8080"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_router_collides_with_ddev_router_https_port_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    bad: { public: 9200, router: 8443 }

projects: {}
"""
    with pytest.raises(RegistryError, match="8443"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_self_collision_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    bad: { public: 9200, router: 9200 }

projects: {}
"""
    with pytest.raises(RegistryError, match="must differ"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_duplicate_public_ports_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    one: { public: 9200, router: 8200 }
    two: { public: 9200, router: 8201 }

projects: {}
"""
    with pytest.raises(RegistryError, match="already used by fleet.ports.one"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_duplicate_router_ports_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    one: { public: 9200, router: 8200 }
    two: { public: 9201, router: 8200 }

projects: {}
"""
    with pytest.raises(RegistryError, match="already used by fleet.ports.one"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_fleet_ports_absent_is_valid(fleet_home, sample_registry_text):
    """A registry with no `fleet.ports` key at all (legacy-only) must stay valid."""
    Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))


def test_project_ports_resolves_named_list(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    playwright: { public: 9324, router: 8323 }
    ts-dashboard: { public: 9111, router: 8110 }

projects:
  oak:
    git: git@example.test:org/oak.git
    ports: [playwright, ts-dashboard]
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.project_ports("oak") == [
        PortProfile(name="playwright", public=9324, router=8323),
        PortProfile(name="ts-dashboard", public=9111, router=8110),
    ]


def test_project_ports_dedupes_legacy_typesense_overlap(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    typesense: { public: 9108, router: 8108 }

projects:
  oak:
    git: git@example.test:org/oak.git
    typesense: true
    ports: [typesense]
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.project_ports("oak") == [
        PortProfile(name="typesense", public=9108, router=8108)
    ]


def test_project_ports_adds_synthetic_typesense_when_enabled_and_not_listed(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    typesense: true
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.project_ports("oak") == [
        PortProfile(name="typesense", public=9108, router=8108)
    ]


def test_project_ports_empty_when_unsubscribed(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    assert registry.project_ports("demo") == []


def test_project_ports_unknown_reference_raises(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    ports: [ghost]
    templates:
      default: {}
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.ports.*ghost"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_project_ports_typesense_in_list_without_explicit_entry_is_unknown_reference(fleet_home):
    """Listing 'typesense' explicitly in `ports:` is NOT the legacy boolean
    — it must resolve against a real fleet.ports.typesense entry like any
    other name (§3.1's unknown-reference rule draws no exception here)."""
    text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    ports: [typesense]
    templates:
      default: {}
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.ports.*typesense"):
        Registry.load(_write(fleet_home / "fleet.yml", text))


def test_all_port_profiles_unions_dedupes_and_sorts(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    playwright: { public: 9324, router: 8323 }
    ts-dashboard: { public: 9111, router: 8110 }

projects:
  oak:
    git: git@example.test:org/oak.git
    typesense: true
    ports: [playwright, ts-dashboard]
    templates:
      default: {}
  other:
    git: git@example.test:org/other.git
    ports: [playwright]
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert [p.name for p in registry.all_port_profiles()] == [
        "playwright",
        "ts-dashboard",
        "typesense",
    ]


def test_public_ports_in_use_returns_sorted_dedup_public_ports(fleet_home):
    text = """\
fleet:
  domain: fleet.example.test
  ports:
    playwright: { public: 9324, router: 8323 }
    ts-dashboard: { public: 9111, router: 8110 }

projects:
  oak:
    git: git@example.test:org/oak.git
    typesense: true
    ports: [playwright, ts-dashboard]
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", text))
    assert registry.public_ports_in_use() == [9108, 9111, 9324]


def test_load_missing_file_raises_actionable_registry_error(fleet_home):
    """A nonexistent fleet.yml must raise RegistryError with guidance,
    not a raw FileNotFoundError traceback in the CLI."""
    path = fleet_home / "does-not-exist" / "fleet.yml"

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "fleet init" in str(exc_info.value)
