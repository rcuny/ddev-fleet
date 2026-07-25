import pytest

from fleet.core import caddyauth, instances
from fleet.core.errors import DeployError, TokenError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult, run_streamed
from fleet.core.secrets import read_secrets, write_secret


class HybridRunner:
    """Runs real `git` commands against the test fixture repo; fakes
    everything else (ddev, bash post_deploy) so tests never touch Docker."""

    def __init__(self):
        self.calls: list[dict] = []

    def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
        self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
        if cmd[0] in ("git", "rsync"):
            return run_streamed(cmd, cwd=cwd, env=env, log_path=log_path, echo=False)
        if cmd[:2] == ["caddy", "hash-password"]:
            # Fakes `caddy hash-password` for deploy()'s default-on instance
            # auth step (fleet.core.caddyauth) — never shells out to a real
            # caddy binary in tests.
            return RunResult(returncode=0, lines=["$2a$14$testhashtesthashtesthashtesthashtestha"])
        if cmd[0] == "tmux":
            # No tmux session in tests (mirrors the real environment: `fleet
            # tmux` was never run) — deploy()'s best-effort tmux tab hook
            # checks `tmux has-session` and must see it as absent.
            return RunResult(returncode=1, lines=[])
        return RunResult(returncode=0, lines=[])


def _registry_text(fleet_home, git_url):
    return f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_url}
    default_template: default
    templates:
      default:
        post_deploy:
          - echo hi
"""


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


def test_deploy_fresh_instance_runs_full_pipeline(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"

    instance_dir = paths.instances / "demo--develop"
    assert (instance_dir / "README.md").exists()

    command_names = [call["cmd"][0] for call in runner.calls]
    # git clone, then the default-on instance auth pipeline (caddy
    # hash-password, caddy validate, caddy reload — the latter talks to the
    # local Caddy admin API, no sudo involved), then the rest of the deploy
    # pipeline unchanged. The trailing "tmux" call is the best-effort
    # `tmux.session_exists()` check at the end of deploy() (no session in
    # tests, so no window is created).
    assert command_names == [
        "git",
        "caddy",
        "caddy",
        "caddy",
        "ddev",
        "ddev",
        "ddev",
        "bash",
        "tmux",
    ]
    assert runner.calls[0]["cmd"][:2] == ["git", "clone"]
    assert runner.calls[1]["cmd"][:2] == ["caddy", "hash-password"]
    assert runner.calls[2]["cmd"][:2] == ["caddy", "validate"]
    assert runner.calls[3]["cmd"][:2] == ["caddy", "reload"]
    assert runner.calls[4]["cmd"] == ["ddev", "start"]
    assert runner.calls[5]["cmd"] == ["ddev", "exec", "ssh-add", "-D"]
    assert runner.calls[6]["cmd"] == ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)]
    assert runner.calls[7]["cmd"] == ["bash", "-c", "echo hi"]
    assert runner.calls[7]["env"]["FLEET_INSTANCE_ID"] == "demo--develop"

    # Push-key setup runs AFTER ddev start and clears the shared agent
    # (ssh-add -D) before loading ONLY the push key, so a lingering read-only
    # deploy key can't shadow the write key on an in-container push.
    all_cmds = [call["cmd"] for call in runner.calls]
    ssh_add_clear = ["ddev", "exec", "ssh-add", "-D"]
    auth_ssh_cmd = ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)]
    assert all_cmds.index(["ddev", "start"]) < all_cmds.index(ssh_add_clear)
    assert all_cmds.index(ssh_add_clear) < all_cmds.index(auth_ssh_cmd)
    assert all_cmds.index(auth_ssh_cmd) < all_cmds.index(["bash", "-c", "echo hi"])

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    assert config_path.exists()
    assert "sk-ant-oat01-test" in config_path.read_text(encoding="utf-8")

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_content = exclude_path.read_text(encoding="utf-8")
    assert ".ddev/config.fleet.yaml" in exclude_content
    assert ".ddev/web-build/Dockerfile.fleet-claude" in exclude_content

    web_build_path = instance_dir / ".ddev" / "web-build" / "Dockerfile.fleet-claude"
    assert web_build_path.exists()
    assert "npm install -g @anthropic-ai/claude-code" in web_build_path.read_text(encoding="utf-8")

    instance_yaml = instance_dir / ".fleet" / "instance.yml"
    assert instance_yaml.exists()
    content = instance_yaml.read_text(encoding="utf-8")
    assert "project: demo" in content
    assert "instance: develop" in content
    assert "branch: main" in content
    assert "created-at" in content
    assert "last-deployed-at" in content

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert deploy_log.exists()
    assert deploy_log.stat().st_size > 0


def test_deploy_label_defaults_to_slugified_branch(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(paths, registry, "demo", "default", branch="main", runner=runner)

    assert url == "https://demo--main.fleet.example.test"
    assert (paths.instances / "demo--main").exists()


def test_deploy_missing_branch_without_default_raises(fleet_home, git_repo):
    """`demo` in _registry_text() sets default_template but no default_branch,
    so omitting branch (with template explicitly given) must exercise the
    missing-branch path specifically."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    with pytest.raises(DeployError):
        instances.deploy(paths, registry, "demo", "default", runner=runner)


def test_deploy_missing_template_without_default_raises(fleet_home, git_repo):
    """A project with a default_branch but no default_template must raise
    DeployError when no template is given — this is the path the old combined
    test never exercised, since its fixture always set default_template."""
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    with pytest.raises(DeployError):
        instances.deploy(paths, registry, "demo", runner=runner)


def test_deploy_uses_project_default_template_and_branch(fleet_home, git_repo):
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    url = instances.deploy(paths, registry, "demo", runner=runner)

    assert url == "https://demo--main.fleet.example.test"


def test_deploy_missing_claude_token_warns_and_proceeds(fleet_home, git_repo):
    """No CLAUDE_CODE_OAUTH_TOKEN in secrets must NOT fail the deploy — it
    should warn (into the instance's deploy log) and proceed without
    injecting the token into web_environment."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, str(git_repo["origin"])), encoding="utf-8")
    # Note: no write_secret() call — .secrets does not exist.
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"

    instance_dir = paths.instances / "demo--develop"
    config_content = (instance_dir / ".ddev" / "config.fleet.yaml").read_text(encoding="utf-8")
    assert "CLAUDE_CODE_OAUTH_TOKEN" not in config_content

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    log_content = deploy_log.read_text(encoding="utf-8")
    assert "WARNING" in log_content
    assert "CLAUDE_CODE_OAUTH_TOKEN" in log_content


def test_deploy_with_claude_token_injects_it(fleet_home, git_repo):
    """Regression guard: when the token IS present, behaviour is unchanged
    — it is still injected into web_environment."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    config_content = (instance_dir / ".ddev" / "config.fleet.yaml").read_text(encoding="utf-8")
    assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-test" in config_content


def test_instance_yaml_created_at_survives_redeploy(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    info_path = paths.instances / "demo--develop" / ".fleet" / "instance.yml"
    first = info_path.read_text(encoding="utf-8")

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    second = info_path.read_text(encoding="utf-8")

    import re

    created_first = re.search(r"created-at: (\S+)", first).group(1)
    created_second = re.search(r"created-at: (\S+)", second).group(1)
    assert created_first == created_second


def test_deploy_git_excludes_asset_injected_files(fleet_home, git_repo):
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / ".env").write_text("FOO=bar\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    assert (instance_dir / ".env").exists()

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_content = exclude_path.read_text(encoding="utf-8")
    assert ".ddev/config.fleet.yaml" in exclude_content
    assert ".env" in exclude_content


def test_deploy_writes_git_bot_identity_into_web_environment(fleet_home, git_repo):
    """The deployed instance's config.fleet.yaml must carry the fleet's bot
    git identity (GIT_AUTHOR_*/GIT_COMMITTER_*) in web_environment, derived
    from the registry's fleet.domain when no explicit git_bot_name/email is
    configured."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    config_path = paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert "GIT_AUTHOR_NAME=ddev-fleet bot" in content
    assert "GIT_AUTHOR_EMAIL=bot@fleet.example.test" in content
    assert "GIT_COMMITTER_NAME=ddev-fleet bot" in content
    assert "GIT_COMMITTER_EMAIL=bot@fleet.example.test" in content


def test_deploy_uses_project_git_bot_override_in_web_environment(fleet_home, git_repo):
    """A project-level `git_bot` override must place that identity (not the
    fleet default bot) into the instance's config.fleet.yaml web_environment."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    git_bot:
      name: Sample Developer
      email: sample@dev.example.test
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    content = (paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml").read_text(
        encoding="utf-8"
    )
    assert "GIT_AUTHOR_NAME=Sample Developer" in content
    assert "GIT_AUTHOR_EMAIL=sample@dev.example.test" in content
    assert "GIT_COMMITTER_EMAIL=sample@dev.example.test" in content
    assert "bot@fleet.example.test" not in content


def test_deploy_omits_git_bot_env_when_project_opts_out(fleet_home, git_repo):
    """A project with `git_bot: false` must get NO GIT_AUTHOR_*/GIT_COMMITTER_*
    injected, so the project's own git config (e.g. from a post-start hook)
    resolves the commit identity instead."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    git_bot: false
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    content = (paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml").read_text(
        encoding="utf-8"
    )
    assert "GIT_AUTHOR_NAME" not in content
    assert "GIT_COMMITTER_EMAIL" not in content


def test_deploy_writes_additional_fqdns_from_project_hostnames(fleet_home, git_repo):
    """A project declaring `additional_hostnames` must have those hostnames
    resolved to full per-instance FQDNs and written into the instance's
    config.fleet.yaml as `additional_fqdns`."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    additional_hostnames:
      - albania
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    config_path = paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert "additional_fqdns:" in content
    assert "albania.demo--develop.fleet.example.test" in content


def test_deploy_writes_typesense_env_when_enabled(fleet_home, git_repo):
    """A project with `typesense: true` gets FLEET_TYPESENSE_* injected into
    its instance config.fleet.yaml web_environment."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    typesense: true
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    config_path = paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert "FLEET_TYPESENSE_HOST=demo--develop.fleet.example.test" in content
    assert "FLEET_TYPESENSE_PORT=9108" in content
    assert "FLEET_TYPESENSE_PATH" not in content


def test_deploy_uses_explicit_fleet_ports_typesense_override(fleet_home, git_repo):
    """An explicit fleet.ports.typesense entry must override the built-in
    9108/8108 legacy default — proves the port now comes from the
    registry, not a hardcoded Python constant."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    typesense: {{ public: 9200, router: 8200 }}

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    typesense: true
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    config_path = paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert "FLEET_TYPESENSE_PORT=9200" in content


def test_deploy_generates_and_registers_typesense_keys_when_enabled(
    monkeypatch, fleet_home, git_repo
):
    """A project with `typesense: true` must get admin/search keys generated
    and persisted to the per-project secrets file, `.ddev/.env` written with
    the admin key (for the Typesense container), and the search-only key
    registered against the running instance's Typesense after `ddev start`."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    typesense: true
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    register_calls = []

    def fake_register_search_key(*, http_port, host, admin_key, search_key, opener=None):
        register_calls.append(
            {
                "http_port": http_port,
                "host": host,
                "admin_key": admin_key,
                "search_key": search_key,
            }
        )

    monkeypatch.setattr(instances.typesense, "register_search_key", fake_register_search_key)

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"

    project_secrets = read_secrets(paths.project_secrets / "demo.env")
    admin_key = project_secrets["TYPESENSE_API_KEY"]
    search_key = project_secrets["FLEET_TYPESENSE_SEARCH_KEY"]
    assert admin_key
    assert search_key
    assert admin_key != search_key

    ddev_env_path = instance_dir / ".ddev" / ".env"
    assert ddev_env_path.exists()
    assert f"TYPESENSE_API_KEY={admin_key}" in ddev_env_path.read_text(encoding="utf-8")

    exclude_content = (instance_dir / ".git" / "info" / "exclude").read_text(encoding="utf-8")
    assert ".ddev/.env" in exclude_content

    assert len(register_calls) == 1
    assert register_calls[0]["host"] == "demo--develop.fleet.example.test"
    assert register_calls[0]["admin_key"] == admin_key
    assert register_calls[0]["search_key"] == search_key

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert f"TYPESENSE_API_KEY={admin_key}" in content
    assert f"FLEET_TYPESENSE_SEARCH_KEY={search_key}" in content


def test_deploy_without_typesense_does_not_generate_keys_or_register(
    monkeypatch, fleet_home, git_repo
):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    register_calls = []
    monkeypatch.setattr(
        instances.typesense,
        "register_search_key",
        lambda **kwargs: register_calls.append(kwargs),
    )

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    assert not (instance_dir / ".ddev" / ".env").exists()
    assert not (paths.project_secrets / "demo.env").exists()
    assert register_calls == []


def test_deploy_tolerates_register_search_key_failure(monkeypatch, fleet_home, git_repo):
    """If Typesense isn't reachable yet, key registration failing must not
    fail the whole deploy — it logs a warning instead."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    typesense: true
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    from fleet.core.errors import TypesenseError

    def failing_register(**kwargs):
        raise TypesenseError("not reachable yet")

    monkeypatch.setattr(instances.typesense, "register_search_key", failing_register)

    url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"
    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert "WARNING" in deploy_log.read_text(encoding="utf-8")


def test_deploy_independent_labels_do_not_clobber_each_other(fleet_home, git_repo):
    """Two deploys for different labels of the same project must both end up
    on disk as independent instances."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="one", runner=HybridRunner()
    )
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="two", runner=HybridRunner()
    )

    assert (paths.instances / "demo--one").exists()
    assert (paths.instances / "demo--two").exists()


def test_deploy_excludes_token_config_before_asset_injection_fails(fleet_home, git_repo):
    """If asset token substitution raises TokenError, the live token file
    written earlier in deploy() must already be git-excluded — closing the
    window where a TokenError between write_fleet_config() and the final
    ensure_git_exclude() would leave config.fleet.yaml committable."""
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / "broken.txt").write_text("[[nope]]\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    with pytest.raises(TokenError):
        instances.deploy(
            paths, registry, "demo", "default", branch="main", label="develop", runner=runner
        )

    instance_dir = paths.instances / "demo--develop"
    exclude_path = instance_dir / ".git" / "info" / "exclude"
    assert ".ddev/config.fleet.yaml" in exclude_path.read_text(encoding="utf-8")


def test_deploy_recovers_from_partial_stub_left_by_prior_destroy(fleet_home, git_repo, monkeypatch):
    """If `instance_dir` exists but isn't a git checkout (a partial stub left
    behind by a prior destroy that couldn't fully remove it — e.g. a bare
    `.ddev/` dir with no `.git`), deploy must clean it up and clone fresh
    instead of handing it to gitops.update() (which would fail with a
    cryptic 'fatal: not a git repository')."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    instance_dir = paths.instances / "demo--develop"
    stub_ddev_dir = instance_dir / ".ddev"
    stub_ddev_dir.mkdir(parents=True)
    (stub_ddev_dir / "config.yaml").write_text("name: stub\n", encoding="utf-8")

    real_clone = instances.gitops.clone
    real_update = instances.gitops.update
    clone_calls = []
    update_calls = []

    def spy_clone(*args, **kwargs):
        clone_calls.append((args, kwargs))
        return real_clone(*args, **kwargs)

    def spy_update(*args, **kwargs):
        update_calls.append((args, kwargs))
        return real_update(*args, **kwargs)

    monkeypatch.setattr(instances.gitops, "clone", spy_clone)
    monkeypatch.setattr(instances.gitops, "update", spy_update)

    runner = HybridRunner()
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert len(clone_calls) == 1
    assert update_calls == []
    assert (instance_dir / "README.md").exists()
    assert not (instance_dir / ".ddev" / "config.yaml").exists()

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert "recovered from a partial instance directory" in deploy_log.read_text(encoding="utf-8")


def test_deploy_substitutes_project_secret_token_into_asset(fleet_home, git_repo):
    """A per-project secret file (<home>/secrets/<project>.env) must be
    exposed as a [[token]] during asset injection, without touching the
    global .secrets (Claude token) file."""
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    slack_dir = assets_dir / ".ddev" / "slack"
    slack_dir.mkdir(parents=True, exist_ok=True)
    (slack_dir / ".env").write_text("SLACK_BOT_TOKEN=[[slack-bot-token]]\n", encoding="utf-8")

    project_secrets_path = paths.project_secrets / "demo.env"
    project_secrets_path.parent.mkdir(parents=True, exist_ok=True)
    project_secrets_path.write_text("SLACK_BOT_TOKEN=xoxb-test123\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    injected = instance_dir / ".ddev" / "slack" / ".env"
    content = injected.read_text(encoding="utf-8")
    assert "xoxb-test123" in content
    assert "[[slack-bot-token]]" not in content


def test_deploy_shares_dump_via_hard_link_not_copy(fleet_home, git_repo):
    """The multi-GB DB dump in a project's asset tree must never be
    duplicated per instance — deploy() should leave it hard-linked (same
    inode as the shared project-level file), and still injects/substitutes
    every other asset normally."""
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    (assets_dir / "dumps").mkdir(parents=True, exist_ok=True)
    dump_src = assets_dir / "dumps" / "default.sql"
    dump_src.write_text("-- shared dump\n", encoding="utf-8")
    (assets_dir / ".env").write_text("PROJECT=[[project]]\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    dump_dest = instance_dir / "dumps" / "default.sql"
    assert dump_dest.read_text(encoding="utf-8") == "-- shared dump\n"
    assert dump_dest.stat().st_ino == dump_src.stat().st_ino
    assert (instance_dir / ".env").read_text(encoding="utf-8") == "PROJECT=demo\n"

    exclude_content = (instance_dir / ".git" / "info" / "exclude").read_text(encoding="utf-8")
    assert "dumps/default.sql" in exclude_content

    # rsync must never be asked to transfer the dumps tree.
    rsync_calls = [c for c in runner.calls if c["cmd"][0] == "rsync"]
    assert rsync_calls
    assert "--exclude=/dumps/" in rsync_calls[0]["cmd"]


def test_deploy_without_any_dump_still_succeeds(fleet_home, git_repo):
    """A project with no dumps/ in its asset tree at all (e.g.
    another-drupal-site, which installs via `drush si` in post_deploy
    instead of importing a dump) must still deploy cleanly."""
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / ".env").write_text("PROJECT=[[project]]\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"
    instance_dir = paths.instances / "demo--develop"
    assert not (instance_dir / "dumps").exists()
    assert (instance_dir / ".env").read_text(encoding="utf-8") == "PROJECT=demo\n"


# --- per-instance Caddy basic auth (default ON) ---


def test_deploy_writes_instance_auth_snippet_by_default(fleet_home, git_repo):
    """Basic auth is ON by default (password 'fleet') — a fresh deploy must
    write the instance's own Caddy snippet, scoped to its FQDN, without any
    explicit auth_enabled=True from the caller."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    snippet_path = caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR / "demo--develop.conf"
    assert snippet_path.exists()
    content = snippet_path.read_text(encoding="utf-8")
    assert "@auth-demo--develop host demo--develop.fleet.example.test" in content
    assert "basic_auth @auth-demo--develop {" in content
    assert "fleet " in content  # default username

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert "basic auth enabled" in deploy_log.read_text(encoding="utf-8")


def test_deploy_with_auth_disabled_writes_no_snippet(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        auth_enabled=False,
        runner=HybridRunner(),
    )

    snippet_path = caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR / "demo--develop.conf"
    assert not snippet_path.exists()

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert "basic auth disabled" in deploy_log.read_text(encoding="utf-8")


def test_deploy_uses_custom_auth_password(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        auth_password="s3cret",
        runner=runner,
    )

    hash_calls = [c for c in runner.calls if c["cmd"][:2] == ["caddy", "hash-password"]]
    assert hash_calls == [
        {
            "cmd": ["caddy", "hash-password", "--plaintext", "s3cret"],
            "cwd": None,
            "env": None,
            "log_path": None,
        }
    ]


def test_deploy_raises_deploy_error_when_caddy_validate_fails(fleet_home, git_repo):
    """If the auth-snippet's Caddyfile validation fails, deploy() must fail
    loudly with an actionable DeployError — never continue on to ddev start
    and leave an instance running with an unknown/half-applied auth state."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    class FailingValidateRunner(HybridRunner):
        def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
            if cmd[:2] == ["caddy", "validate"]:
                self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
                return RunResult(returncode=1, lines=["Caddyfile:5: broken"])
            return super().__call__(cmd, cwd=cwd, env=env, log_path=log_path, echo=echo)

    runner = FailingValidateRunner()
    with pytest.raises(DeployError, match="basic auth"):
        instances.deploy(
            paths, registry, "demo", "default", branch="main", label="develop", runner=runner
        )

    # Caddy was never reloaded, so nothing was pushed live with this broken
    # config — and the pipeline never got to ddev start.
    reload_or_ddev_calls = [
        c for c in runner.calls if c["cmd"][:2] == ["caddy", "reload"] or c["cmd"][:1] == ["ddev"]
    ]
    assert reload_or_ddev_calls == []


def test_deploy_writes_caddy_port_snippet_for_project_ports(fleet_home, git_repo):
    from fleet.core import caddyports

    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test
  ports:
    playwright: {{ public: 9324, router: 8323 }}

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    ports: [playwright]
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    snippet_path = caddyports.DEFAULT_PORTS_SNIPPET_DIR / "playwright.conf"
    assert snippet_path.exists()
    assert "9324" in snippet_path.read_text(encoding="utf-8")


def test_destroy_removes_caddy_port_snippet_when_registry_no_longer_declares_it(
    fleet_home, git_repo
):
    # NOTE: `Registry` is declarative and read-only at runtime (core/registry.py) —
    # `all_port_profiles()` reflects what `fleet.yml` *currently* declares for a
    # project, not which instances of that project happen to exist on disk.
    # `sync()` reconciles the snippet dir to that declaration. So "unsubscribing"
    # a port is a registry edit (dropping it from a project's `ports:` list and
    # reloading), not a side effect of destroying the last instance — a project
    # with zero deployed instances but a live `ports:` entry still keeps its
    # snippet, which is correct: the Caddy site block is fleet-wide and harmless
    # to leave up, and another instance of the project may be deployed at any
    # moment. Do not "fix" this back to an instance-count check.
    from fleet.core import caddyports

    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text_with_port = f"""\
fleet:
  domain: fleet.example.test
  ports:
    playwright: {{ public: 9324, router: 8323 }}

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    ports: [playwright]
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text_with_port, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    snippet_path = caddyports.DEFAULT_PORTS_SNIPPET_DIR / "playwright.conf"
    assert snippet_path.exists()

    # Operator un-subscribes project `demo` from the `playwright` port profile.
    registry_text_without_port = f"""\
fleet:
  domain: fleet.example.test
  ports:
    playwright: {{ public: 9324, router: 8323 }}

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text_without_port, encoding="utf-8")
    registry = Registry.load(paths.registry)

    instances.destroy(paths, registry, "demo--develop", runner=HybridRunner())

    assert not snippet_path.exists()
