# install/ansible

The host's playbook (`site.yml`) and its roles. `sudo fleet host:drift` runs it read-only (`--check --diff`) and
reports per role what would change.

## Drift convention (FLE-261)

A check run shows a changed task as `changed`. Some drift a check run cannot show as a task (a download, a build):
the role says it with a debug task whose message starts with `DRIFT: `, only in check mode:

```yaml
- name: Report a custom build that is too old (a check run cannot download one)
  ansible.builtin.debug:
    msg: "DRIFT: the custom Caddy would be refreshed to {{ wanted }} (installed {{ installed }})"
  when: not current | bool and ansible_check_mode
```

The `fleet_drift` callback (`callback_plugins/fleet_drift.py`) collects those, the changed tasks (with their diff) and
the failed tasks into one JSON report. Rules for roles: keep secrets out of diffs with `no_log: true` (the report shows
"hidden" instead), and never rely on a `command` step to be seen: check mode skips it, so the report states that
limit (firewall rules, for one).

## Idempotency test (FLE-301)

`ci/ansible-idempotency.sh` runs `site.yml` twice in a Debian 13 systemd container in Fleet CI and fails unless the
second run reports `changed=0`. It skips the roles that cannot run in a container by tag (`docker`, `ddev`, `app_code`,
`units`, `hardening`), so keep a new role's tag unique and decide there whether it can run in a container. Details:
`docs/contributing/03-ci.md`.
