# ddev-fleet demo project

The simplest project the fleet can deploy: a generic DDEV `type: php` app with a
single landing page and **no database**. It demonstrates the whole loop —
deploy → serve → destroy — without a private config repo or any network access.

On a fresh install the provisioner turns this directory into a local bare git
repo at `/srv/fleet/_demo.git` and seeds a `demo` project into the registry
(`/srv/fleet/config/fleet.yml`), so you can prove the fleet with one command:

```bash
sudo -u fleet fleet deploy demo          # -> https://demo--main.<your-domain>/
sudo -u fleet fleet destroy demo--main   # tear it back down
```

The seeded registry is never overwritten once it exists, so this only applies
before you point the fleet at your own projects. Copy this directory as a
starting point for the minimal shape of a fleet-deployable project.

Contents:

- `.ddev/config.yaml` — minimal DDEV project (`type: php`, `omit_containers: [db]`;
  the fleet overrides `name` per instance).
- `web/index.php` — the landing page (shows the instance host + PHP version).
