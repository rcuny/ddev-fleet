from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import JiraHookRule, PortProfile, Registry


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


def test_resolve_explicit_label_is_normalised(fleet_home, sample_registry_text):
    """An explicit --label is slugified just like a branch-derived one — a
    ticket-style label like 'ABC-1234' must not be rejected."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "main", label="ABC-1234")

    assert resolved.label == "abc-1234"
    assert resolved.instance_id == "demo--abc-1234"


@pytest.mark.parametrize(
    "raw_label",
    ["ABC_1234", "ABC 1234", "-abc-1234-", "abc--1234"],
)
def test_resolve_explicit_label_variants_normalise_to_same_slug(
    fleet_home, sample_registry_text, raw_label
):
    """Underscores, spaces, leading/trailing dashes, and a doubled dash (which
    would otherwise be a hard validate_part rejection as the id separator)
    all normalise to the same slug."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "main", label=raw_label)

    assert resolved.label == "abc-1234"


def test_resolve_already_valid_label_passes_through_unchanged(fleet_home, sample_registry_text):
    """An already-canonical label is not surprise-rewritten (byte-identical)."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "main", label="already-canonical")

    assert resolved.label == "already-canonical"


def test_resolve_label_that_slugifies_to_empty_raises_naming_original(
    fleet_home, sample_registry_text
):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError) as exc_info:
        registry.resolve("demo", "default", "main", label="!!!")

    assert "'!!!'" in str(exc_info.value)


def test_resolve_label_normalisation_still_enforces_instance_id_length_limit(
    fleet_home, sample_registry_text
):
    """slugify() must not quietly bypass the 63-char DNS label limit that
    instance_id() enforces."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("demo", "default", "main", label="X" * 80)


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


@pytest.mark.parametrize(
    "bad",
    [
        "News",  # uppercase
        "news.example",  # dot — must be a bare DNS label
        "-news",  # leading dash
        "news-",  # trailing dash
        "news_site",  # underscore
        "",  # empty
    ],
)
def test_additional_hostnames_rejects_invalid_dns_labels(fleet_home, bad):
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - {bad!r}
    templates:
      default: {{}}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    with pytest.raises(RegistryError, match=r"projects\.demo\.additional_hostnames"):
        Registry.load(path)


def test_additional_hostnames_rejects_non_string_entry(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - 123
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    with pytest.raises(RegistryError, match=r"projects\.demo\.additional_hostnames"):
        Registry.load(path)


def test_additional_hostnames_accepts_valid_dns_labels(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - news
      - es2
      - a-b-c
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)
    assert registry.additional_hostnames("demo") == ["news", "es2", "a-b-c"]


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


def test_issue_id_regexp_invalid_pattern_raises_naming_the_project(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    issue_id_regexp: "OAKS-[0-9"
    templates:
      default: {}
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.issue_id_regexp"):
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))


def test_issue_id_regexp_non_string_raises(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    issue_id_regexp: 12345
    templates:
      default: {}
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.issue_id_regexp"):
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))


def test_issue_id_regexp_valid_is_returned(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    issue_id_regexp: "OAKS-[0-9]+"
    templates:
      default: {}
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))
    assert registry.issue_id_regexp("oak") == "OAKS-[0-9]+"


def test_issue_id_regexp_absent_returns_none(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    assert registry.issue_id_regexp("demo") is None


def _display_submodule_registry(fleet_home, value_yaml: str | None) -> Path:
    """A one-project fleet.yml, optionally carrying `display_submodule_branch:`
    with the given raw YAML value."""
    line = f"    display_submodule_branch: {value_yaml}\n" if value_yaml is not None else ""
    text = (
        "fleet:\n  domain: fleet.example.test\n\n"
        "projects:\n  oak:\n    git: git@example.test:org/oak.git\n"
        f"{line}"
        "    templates:\n      default: {}\n"
    )
    return _write(fleet_home / "fleet.yml", text)


def test_display_submodule_branch_valid_is_returned(fleet_home):
    registry = Registry.load(_display_submodule_registry(fleet_home, "libs/core"))
    assert registry.display_submodule_branch("oak") == "libs/core"


def test_display_submodule_branch_absent_returns_none(fleet_home):
    registry = Registry.load(_display_submodule_registry(fleet_home, None))
    assert registry.display_submodule_branch("oak") is None


@pytest.mark.parametrize(
    "value_yaml",
    ['"/etc"', '"../sibling"', '"libs/../../x"', '"a/.."', '""', '"  "', "12345", "[core]"],
)
def test_display_submodule_branch_invalid_raises_naming_the_project(fleet_home, value_yaml):
    path = _display_submodule_registry(fleet_home, value_yaml)
    with pytest.raises(RegistryError, match=r"projects\.oak\.display_submodule_branch"):
        Registry.load(path)


def _jira_hooks_registry(fleet_home, hooks_yaml: str) -> Path:
    """A one-project fleet.yml with a `jira-work` template and the given
    `jira_hooks:` block (already indented under the project, or empty)."""
    text = (
        "fleet:\n"
        "  domain: fleet.example.test\n"
        "\n"
        "projects:\n"
        "  p:\n"
        "    git: git@example.test:org/p.git\n"
        "    templates:\n"
        "      jira-work: {}\n" + hooks_yaml
    )
    return _write(fleet_home / "fleet.yml", text)


def test_jira_hooks_valid(fleet_home):
    path = _jira_hooks_registry(
        fleet_home,
        "    jira_hooks:\n"
        "      - on_status: Dispatched\n"
        "        action: deploy\n"
        "        template: jira-work\n"
        "      - on_status: Review\n"
        "        action: deploy\n"
        "        template: jira-work\n"
        "        branch: develop\n",
    )
    registry = Registry.load(path)

    assert registry.jira_hooks("p") == [
        JiraHookRule("Dispatched", "deploy", "jira-work", None),
        JiraHookRule("Review", "deploy", "jira-work", "develop"),
    ]


def test_jira_hooks_absent_is_empty(fleet_home):
    registry = Registry.load(_jira_hooks_registry(fleet_home, ""))
    assert registry.jira_hooks("p") == []


def test_jira_hooks_unknown_project_raises(fleet_home):
    registry = Registry.load(_jira_hooks_registry(fleet_home, ""))
    with pytest.raises(RegistryError, match="unknown project"):
        registry.jira_hooks("nope")


@pytest.mark.parametrize(
    "hooks_yaml",
    [
        pytest.param("    jira_hooks: {}\n", id="not-a-list"),
        pytest.param("    jira_hooks: [x]\n", id="rule-not-a-mapping"),
        pytest.param(
            "    jira_hooks:\n      - {action: deploy, template: jira-work}\n",
            id="missing-on-status",
        ),
        pytest.param(
            '    jira_hooks:\n      - {on_status: "", action: deploy, template: jira-work}\n',
            id="empty-on-status",
        ),
        pytest.param(
            "    jira_hooks:\n      - {on_status: Done, action: destroy, template: jira-work}\n",
            id="bad-action",
        ),
        pytest.param(
            "    jira_hooks:\n      - {on_status: Done, action: deploy}\n",
            id="missing-template",
        ),
        pytest.param(
            "    jira_hooks:\n      - {on_status: Done, action: deploy, template: nope}\n",
            id="unknown-template",
        ),
        pytest.param(
            "    jira_hooks:\n"
            "      - {on_status: Done, action: deploy, template: jira-work, when: x}\n",
            id="unknown-key",
        ),
        pytest.param(
            "    jira_hooks:\n"
            "      - {on_status: Done, action: deploy, template: jira-work, branch: 3}\n",
            id="branch-not-a-string",
        ),
        pytest.param(
            "    jira_hooks:\n"
            "      - {on_status: Dispatched, action: deploy, template: jira-work}\n"
            "      - {on_status: dispatched, action: deploy, template: jira-work}\n",
            id="duplicate-on-status-case-insensitive",
        ),
    ],
)
def test_jira_hooks_invalid(fleet_home, hooks_yaml):
    path = _jira_hooks_registry(fleet_home, hooks_yaml)
    with pytest.raises(RegistryError, match=r"projects\.p\.jira_hooks"):
        Registry.load(path)


def test_tty1_not_a_list_raises_with_full_path(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    templates:
      jira-pull:
        tty1: "ddev exec claude [[issue-id]]"
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.templates\.jira-pull\.tty1"):
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))


def test_tty2_list_with_non_string_element_raises(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    templates:
      jira-pull:
        tty2:
          - ddev drush watchdog:tail
          - 42
"""
    with pytest.raises(RegistryError, match=r"projects\.oak\.templates\.jira-pull\.tty2"):
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))


def test_tty3_key_raises_mentioning_tty1_and_tty2(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    templates:
      jira-pull:
        tty3:
          - echo hi
"""
    with pytest.raises(RegistryError, match=r"tty1 and tty2") as exc_info:
        Registry.load(_write(fleet_home / "fleet.yml", registry_text))
    assert "projects.oak.templates.jira-pull.tty3" in str(exc_info.value)


def test_resolve_populates_tty1_and_tty2(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    issue_id_regexp: "OAKS-[0-9]+"
    templates:
      jira-pull:
        post_deploy:
          - ddev init --no-interactive
        tty1:
          - ddev exec claude "/jira pull [[issue-id]] --create-branch"
        tty2:
          - ddev drush watchdog:tail
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", registry_text))
    resolved = registry.resolve("oak", "jira-pull", "OAKS-1781")

    assert resolved.tty1 == ['ddev exec claude "/jira pull [[issue-id]] --create-branch"']
    assert resolved.tty2 == ["ddev drush watchdog:tail"]


def test_resolve_with_no_tty_commands_gives_two_empty_lists(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    resolved = registry.resolve("demo", "default", "main")

    assert resolved.tty1 == []
    assert resolved.tty2 == []


# --- FLE-6: post_deploy as list shorthand OR {exec, tty1, tty2} mapping -----


def _template_registry(template_body: str) -> str:
    return (
        "fleet:\n  domain: fleet.example.test\n\nprojects:\n  oak:\n"
        "    git: git@example.test:org/oak.git\n    templates:\n      jira:\n"
        + "".join(f"        {line}\n" for line in template_body.strip().splitlines())
    )


def test_post_deploy_list_shorthand_is_exec(fleet_home):
    registry = Registry.load(
        _write(fleet_home / "fleet.yml", _template_registry("post_deploy:\n  - echo a\n  - echo b"))
    )
    resolved = registry.resolve("oak", "jira", "main")

    assert resolved.post_deploy == ["echo a", "echo b"]
    assert resolved.tty1 == [] and resolved.tty2 == []


def test_post_deploy_mapping_exec_tty1_tty2(fleet_home):
    body = """
post_deploy:
  exec:
    - ddev init --no-interactive
  tty1:
    - echo one
  tty2:
    - ddev exec claude "/jira work [[issue-id]]"
    - echo two
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))
    resolved = registry.resolve("oak", "jira", "main")

    assert resolved.post_deploy == ["ddev init --no-interactive"]
    assert resolved.tty1 == ["echo one"]
    assert resolved.tty2 == ['ddev exec claude "/jira work [[issue-id]]"', "echo two"]


def test_post_deploy_mapping_all_keys_optional(fleet_home):
    registry = Registry.load(
        _write(fleet_home / "fleet.yml", _template_registry("post_deploy:\n  tty2:\n    - echo x"))
    )
    resolved = registry.resolve("oak", "jira", "main")

    assert resolved.post_deploy == []
    assert resolved.tty1 == []
    assert resolved.tty2 == ["echo x"]


def test_post_deploy_mapping_unknown_key_raises_naming_the_template(fleet_home):
    body = "post_deploy:\n  exec:\n    - echo a\n  tty3:\n    - echo b"
    with pytest.raises(RegistryError, match=r"projects\.oak\.templates\.jira\.post_deploy\.tty3"):
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))

    body = "post_deploy:\n  after:\n    - echo b"
    with pytest.raises(RegistryError, match=r"projects\.oak\.templates\.jira\.post_deploy\.after"):
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))


def test_post_deploy_mapping_values_must_be_string_lists(fleet_home):
    body = "post_deploy:\n  tty1: echo not-a-list"
    with pytest.raises(RegistryError, match=r"jira\.post_deploy\.tty1: must be a list of strings"):
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))

    body = "post_deploy:\n  exec:\n    - echo a\n    - 42"
    with pytest.raises(RegistryError, match=r"jira\.post_deploy\.exec"):
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))


def test_post_deploy_scalar_raises(fleet_home):
    with pytest.raises(RegistryError, match=r"jira\.post_deploy: must be a list of strings"):
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry("post_deploy: echo hi")))


def test_legacy_template_level_tty_still_works_with_one_deprecation_warning(fleet_home, caplog):
    body = """
post_deploy:
  - echo a
tty1:
  - echo one
tty2:
  - echo two
"""
    with caplog.at_level("WARNING"):
        registry = Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))
    resolved = registry.resolve("oak", "jira", "main")

    assert resolved.post_deploy == ["echo a"]
    assert resolved.tty1 == ["echo one"]
    assert resolved.tty2 == ["echo two"]
    deprecations = [r for r in caplog.records if "deprecated" in r.message and "tty" in r.message]
    assert len(deprecations) == 1  # once per template, not per pane
    assert "projects.oak.templates.jira" in deprecations[0].message


def test_legacy_tty_alongside_mapping_post_deploy_for_other_pane_is_fine(fleet_home, caplog):
    body = "post_deploy:\n  tty1:\n    - echo new\ntty2:\n  - echo old"
    with caplog.at_level("WARNING"):
        registry = Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))
    resolved = registry.resolve("oak", "jira", "main")

    assert resolved.tty1 == ["echo new"]
    assert resolved.tty2 == ["echo old"]


def test_new_syntax_emits_no_deprecation_warning(fleet_home, caplog):
    with caplog.at_level("WARNING"):
        Registry.load(
            _write(
                fleet_home / "fleet.yml", _template_registry("post_deploy:\n  tty1:\n    - echo x")
            )
        )
    assert not [r for r in caplog.records if "tty" in r.message]


def test_same_pane_at_template_level_and_under_post_deploy_is_an_error(fleet_home):
    body = "post_deploy:\n  tty1:\n    - echo new\ntty1:\n  - echo old"
    with pytest.raises(RegistryError, match=r"projects\.oak\.templates\.jira\.tty1") as exc_info:
        Registry.load(_write(fleet_home / "fleet.yml", _template_registry(body)))
    assert "post_deploy.tty1" in str(exc_info.value)


def test_auth_mode_defaults_to_basic_with_no_host_config(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    assert registry.auth_mode == "basic"


def test_auth_mode_defaults_to_basic_when_host_yml_has_no_key(fleet_home, sample_registry_text):
    registry_path = _write(fleet_home / "fleet.yml", sample_registry_text)
    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("domain: fleet.example.test\n", encoding="utf-8")
    registry = Registry.load(registry_path, host_config_path=host_config_path)
    assert registry.auth_mode == "basic"


def test_auth_mode_reads_authelia_from_host_yml(fleet_home, sample_registry_text):
    registry_path = _write(fleet_home / "fleet.yml", sample_registry_text)
    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode: authelia\n", encoding="utf-8")
    registry = Registry.load(registry_path, host_config_path=host_config_path)
    assert registry.auth_mode == "authelia"


def test_auth_mode_rejects_unknown_value(fleet_home, sample_registry_text):
    registry_path = _write(fleet_home / "fleet.yml", sample_registry_text)
    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode: ldap\n", encoding="utf-8")
    with pytest.raises(RegistryError, match="auth_mode"):
        Registry.load(registry_path, host_config_path=host_config_path)


def test_auth_mode_defaults_to_basic_when_host_yml_key_is_blank(fleet_home, sample_registry_text):
    registry_path = _write(fleet_home / "fleet.yml", sample_registry_text)
    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode:\n", encoding="utf-8")
    registry = Registry.load(registry_path, host_config_path=host_config_path)
    assert registry.auth_mode == "basic"


# --- load_host_auth_mode() — reads ONLY host.yml, no fleet.yml required ---


def test_load_host_auth_mode_defaults_to_basic_with_no_host_config(fleet_home):
    from fleet.core.registry import load_host_auth_mode

    assert load_host_auth_mode(fleet_home / "host.yml") == "basic"


def test_load_host_auth_mode_reads_authelia_from_host_yml(fleet_home):
    from fleet.core.registry import load_host_auth_mode

    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode: authelia\n", encoding="utf-8")
    assert load_host_auth_mode(host_config_path) == "authelia"


def test_load_host_auth_mode_rejects_unknown_value(fleet_home):
    from fleet.core.registry import load_host_auth_mode

    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode: ldap\n", encoding="utf-8")
    with pytest.raises(RegistryError, match="auth_mode"):
        load_host_auth_mode(host_config_path)


def test_load_host_auth_mode_never_touches_fleet_yml(fleet_home):
    """No fleet.yml at all — load_host_auth_mode must not need or read
    one (the whole point: it backs break-glass admin-password commands)."""
    from fleet.core.registry import load_host_auth_mode

    assert not (fleet_home / "fleet.yml").exists()
    host_config_path = fleet_home / "host.yml"
    host_config_path.write_text("auth_mode: authelia\n", encoding="utf-8")
    assert load_host_auth_mode(host_config_path) == "authelia"


# --- fleet.yml projects.<project>.users (Authelia mode) ---

_USERS_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    users:
      - name: fleet
        password: fleet
  fern: &fern
    git: git@example.test:org/fern.git
    users:
      - name: fleet
        password: fleet
      - name: fern
        password: fern
  oak: *fern
"""


def test_users_alias_project_shares_the_same_users_and_gets_its_own_group(fleet_home):
    registry = Registry.load(_write(fleet_home / "fleet.yml", _USERS_REGISTRY))
    assert registry.project_users("fern") == [("fleet", "fleet"), ("fern", "fern")]
    assert registry.project_users("oak") == [("fleet", "fleet"), ("fern", "fern")]

    by_name = {u.name: u for u in registry.all_users()}
    assert set(by_name["fleet"].groups) == {"demo", "fern", "oak"}
    assert set(by_name["fern"].groups) == {"fern", "oak"}


def test_users_rejects_invalid_charset(fleet_home):
    bad = _USERS_REGISTRY.replace(
        "name: fleet\n        password: fleet",
        "name: Fleet!\n        password: fleet",
        1,
    )
    with pytest.raises(RegistryError, match="users"):
        Registry.load(_write(fleet_home / "fleet.yml", bad))


def test_users_rejects_empty_password(fleet_home):
    bad = _USERS_REGISTRY.replace(
        "name: fleet\n        password: fleet",
        "name: fleet\n        password: ''",
        1,
    )
    with pytest.raises(RegistryError, match="password"):
        Registry.load(_write(fleet_home / "fleet.yml", bad))


def test_users_rejects_duplicate_name_in_same_project(fleet_home):
    bad = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    users:
      - name: fleet
        password: fleet
      - name: fleet
        password: other
"""
    with pytest.raises(RegistryError, match="duplicate"):
        Registry.load(_write(fleet_home / "fleet.yml", bad))


def test_users_rejects_reserved_admins_name(fleet_home):
    bad = _USERS_REGISTRY.replace(
        "name: fern\n        password: fern", "name: admins\n        password: fern"
    )
    with pytest.raises(RegistryError, match="admins"):
        Registry.load(_write(fleet_home / "fleet.yml", bad))


def test_users_rejects_password_mismatch_across_projects(fleet_home):
    bad = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    users:
      - name: fleet
        password: alpha
  other:
    git: git@example.test:org/other.git
    users:
      - name: fleet
        password: beta
"""
    with pytest.raises(RegistryError) as exc:
        Registry.load(_write(fleet_home / "fleet.yml", bad))
    assert "demo" in str(exc.value) and "other" in str(exc.value)


def test_all_users_returns_empty_list_when_no_project_declares_any(
    fleet_home, sample_registry_text
):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))
    assert registry.all_users() == []


def test_load_missing_file_raises_actionable_registry_error(fleet_home):
    """A nonexistent fleet.yml must raise RegistryError with guidance,
    not a raw FileNotFoundError traceback in the CLI."""
    path = fleet_home / "does-not-exist" / "fleet.yml"

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "fleet init" in str(exc_info.value)


# --- fleet.auth_bypass_cidrs (per-instance basic-auth whitelist) ---

_BYPASS_REGISTRY = """\
fleet:
  domain: fleet.example.test
  auth_bypass_cidrs:
    - 203.0.113.31/32
    - 203.0.113.80/29
    - 198.51.100.191/29      # host bits set — normalised to .184/29
    - 203.0.113.80/29       # duplicate
    - 9.9.9.9                # bare address -> /32

projects:
  demo:
    git: git@example.test:org/demo.git
"""


def test_auth_bypass_cidrs_normalises_masks_and_dedupes(fleet_home):
    registry = Registry.load(_write(fleet_home / "fleet.yml", _BYPASS_REGISTRY))

    assert registry.auth_bypass_cidrs == [
        "203.0.113.31/32",
        "203.0.113.80/29",
        "198.51.100.184/29",
        "9.9.9.9/32",
    ]


def test_auth_bypass_cidrs_defaults_to_empty(fleet_home, sample_registry_text):
    registry = Registry.load(_write(fleet_home / "fleet.yml", sample_registry_text))

    assert registry.auth_bypass_cidrs == []


def test_auth_bypass_cidrs_deprecated_warns_but_does_not_raise(fleet_home, caplog):
    bad = _BYPASS_REGISTRY.replace("203.0.113.31/32", "not-an-ip")

    with caplog.at_level("WARNING"):
        registry = Registry.load(_write(fleet_home / "fleet.yml", bad))

    assert any(
        "auth_bypass_cidrs" in r.message and "deprecated" in r.message for r in caplog.records
    )
    # the one malformed entry is skipped; the rest still normalise
    assert "not-an-ip" not in "".join(registry.auth_bypass_cidrs)
    assert "203.0.113.80/29" in registry.auth_bypass_cidrs


def test_auth_bypass_cidrs_non_list_value_ignored_returns_empty(fleet_home):
    bad = """\
fleet:
  domain: fleet.example.test
  auth_bypass_cidrs: 203.0.113.31/32

projects:
  demo:
    git: git@example.test:org/demo.git
"""
    registry = Registry.load(_write(fleet_home / "fleet.yml", bad))
    assert registry.auth_bypass_cidrs == []


def test_auth_bypass_cidrs_warns_once_when_present_and_well_formed(fleet_home, caplog):
    with caplog.at_level("WARNING"):
        registry = Registry.load(_write(fleet_home / "fleet.yml", _BYPASS_REGISTRY))
    assert registry.auth_bypass_cidrs == [
        "203.0.113.31/32",
        "203.0.113.80/29",
        "198.51.100.184/29",
        "9.9.9.9/32",
    ]
    deprecation_warnings = [r for r in caplog.records if "auth_bypass_cidrs" in r.message]
    assert len(deprecation_warnings) == 1
