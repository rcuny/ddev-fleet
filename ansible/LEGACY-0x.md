---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# These Ansible roles are the 0.x host layout

Everything in this directory (the roles, `site.yml` and the scoped playbooks beside it) provisions a host for the
**0.x** (Python) product: the `fleet.service` daemon, the `fleet` Python package, `fleet.yml`, `fleet-boot` and
`fleet-tmux`. They were written for, and are tested against, that layout.

- **These roles are not used by the 1.x installer.** The 1.x line installs itself with `install/install.sh` (a pure bash installer,
  see [`../docs/installation.md`](../docs/installation.md)). No Ansible role runs in it and none is needed.
- **Must not deploy `develop`.** The roles clone the product and install an editable Python virtualenv, and the
  Python app is not on `develop` any more. Do not point them at a host that runs, or should run, 1.x.
- **0.x hosts only.** Run a role against a live 0.x host only through the scoped playbooks, `--check --diff` first;
  never the full `site.yml` against a live host (its `fleet_service` role rewrites the checkout's git remote).
  See the repository's `CLAUDE.md` and `docs/v0/` for the 0.x procedures.
- **Host hardening stays an operator decision.** The 1.x installer never touches `sshd`, the firewall (UFW, iptables),
  fail2ban or unattended-upgrades: that is deliberate, because a mistake locks you out of a remote host. The
  hardening roles here (`network_hardening`, `security_hardening` and `hardening.yml`) describe what a 0.x host
  applies; a 1.x hardening procedure is documented with the host operations milestone (M0.12).

The roles are kept as the reference for what the 1.x installer and later milestones re-implement, and are removed
once the 0.x line is retired.
