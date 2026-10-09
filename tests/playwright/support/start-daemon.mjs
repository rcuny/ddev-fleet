#!/usr/bin/env node
/**
 * Starts one fleet daemon for the browser E2E suite and cleans up after it.
 *
 * Playwright's `webServer` runs this (see playwright.config.ts), one process per daemon:
 *   node support/start-daemon.mjs --port 18791 --state .run/daemon-key.json [--no-key]
 *
 * It builds a short FLEET_HOME under /tmp (gpg-agent sockets live inside it and unix socket
 * paths are limited to about 100 characters), writes the registry, creates the host key with
 * the product's own CLI (`python -m fleet.cli keys init`, a black box), then runs uvicorn on
 * `fleet.daemon:app`. The state file tells the tests where FLEET_HOME is. On SIGTERM/SIGINT or
 * when uvicorn exits it stops uvicorn, kills the gpg-agent and removes the home and the state
 * file. Plain .mjs on purpose: it runs on any Node Playwright supports, without a TS loader.
 *
 * Python: $FLEET_PYTHON, else <repo>/.venv/bin/python when it exists, else python3.
 */
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const REGISTRY_YAML = `fleet:
  domain: fleet.example.test   # Wildcard DNS root

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo project-default
      custom:
        post_deploy:
          - echo instance-override
`;

const { values } = parseArgs({
  options: {
    port: { type: 'string' },
    state: { type: 'string' },
    'no-key': { type: 'boolean', default: false },
  },
});
if (!values.port || !values.state) {
  console.error('usage: start-daemon.mjs --port N --state FILE [--no-key]');
  process.exit(2);
}
const port = Number(values.port);
const stateFile = resolve(values.state);

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const venvPython = join(repoRoot, '.venv', 'bin', 'python');
const python = process.env.FLEET_PYTHON || (existsSync(venvPython) ? venvPython : 'python3');

const home = mkdtempSync(join(existsSync('/tmp') ? '/tmp' : tmpdir(), 'fe-'));
let child = null;
let cleaned = false;

function cleanup() {
  if (cleaned) return;
  cleaned = true;
  try {
    execFileSync('gpgconf', ['--homedir', join(home, 'gnupg'), '--kill', 'gpg-agent'], {
      stdio: 'ignore',
      timeout: 30_000,
    });
  } catch {
    // no agent was started, or gpgconf is missing: nothing to stop
  }
  rmSync(home, { recursive: true, force: true });
  rmSync(stateFile, { force: true });
}

function stop(signal) {
  if (child && child.exitCode === null && child.signalCode === null) {
    child.kill('SIGTERM');
    setTimeout(() => child.kill('SIGKILL'), 10_000).unref();
  } else {
    cleanup();
    process.exit(signal ? 0 : 1);
  }
}
for (const signal of ['SIGTERM', 'SIGINT', 'SIGHUP']) process.on(signal, () => stop(signal));

try {
  mkdirSync(join(home, 'config', 'assets'), { recursive: true });
  for (const name of ['instances', 'logs', 'locks']) mkdirSync(join(home, name));
  writeFileSync(join(home, 'config', 'fleet.yml'), REGISTRY_YAML, 'utf8');

  const env = { ...process.env, FLEET_HOME: home };
  let fingerprint = '';
  if (!values['no-key']) {
    const out = execFileSync(
      python,
      ['-m', 'fleet.cli', 'keys', 'init', '--uid', 'fleet e2e <e2e@example.test>'],
      { env, encoding: 'utf8', cwd: repoRoot },
    );
    const match = out.match(/^fingerprint:\s+(\S+)/m);
    if (!match) throw new Error(`could not read the fingerprint from "fleet keys init":\n${out}`);
    fingerprint = match[1].replace(/\s/g, '').toUpperCase();
  }

  mkdirSync(dirname(stateFile), { recursive: true });
  writeFileSync(stateFile, JSON.stringify({ home, fingerprint, port }), 'utf8');

  child = spawn(
    python,
    ['-m', 'uvicorn', 'fleet.daemon:app', '--host', '127.0.0.1', '--port', String(port), '--log-level', 'warning'],
    { env, stdio: ['ignore', 'pipe', 'pipe'], cwd: repoRoot },
  );
  // uvicorn's output is only interesting when it fails (it also logs harmless startup
  // warnings about /etc/caddy on a machine without Caddy), so keep the tail and print it then.
  let log = '';
  const keep = (chunk) => {
    log = (log + chunk).slice(-65_536);
  };
  child.stdout.on('data', keep);
  child.stderr.on('data', keep);
  child.on('error', (error) => {
    console.error(`could not start uvicorn (${python}): ${error.message}`);
    cleanup();
    process.exit(1);
  });
  child.on('exit', (code, signal) => {
    if (code !== 0 && signal !== 'SIGTERM' && log) console.error(log);
    cleanup();
    process.exit(code ?? 0);
  });
} catch (error) {
  console.error(error instanceof Error ? error.message : error);
  cleanup();
  process.exit(1);
}
