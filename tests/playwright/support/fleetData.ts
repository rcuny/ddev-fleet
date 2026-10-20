import { expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { externalBaseURL } from './env';

// The data of the app under test, reached from a spec without a browser: its console commands (webhook rules and
// templates have no page yet) and a few rows no page creates. Throw-away home of the webServer, or the container's
// FLEET_HOME for the served dev app.

const here = dirname(fileURLToPath(import.meta.url));
const appDir = resolve(here, '../../../app');

export function fleetHome(): string {
  return externalBaseURL ? (process.env.FLEET_HOME ?? '/tmp/sqlite/fleet-home') : join(resolve(here, '..'), '.run', 'fleet-home');
}

function php(): string {
  return spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php';
}

export interface ConsoleResult {
  status: number | null;
  stdout: string;
  /** stdout and stderr together: field errors are printed on either, depending on the command. */
  text: string;
}

/** Runs a `bin/console` command of the app under test and returns its exit status and output (any status). */
export function runConsole(args: string[]): ConsoleResult {
  const result = spawnSync(php(), ['bin/console', ...args, '--no-interaction'], {
    cwd: appDir,
    env: { ...process.env, APP_ENV: 'dev', FLEET_RUNNER: 'recording', FLEET_HOME: fleetHome() },
    encoding: 'utf8',
  });
  return { status: result.status, stdout: result.stdout, text: `${result.stdout}${result.stderr}` };
}

/** Runs a console command that must succeed and returns its output. */
export function consoleOk(args: string[]): string {
  const result = runConsole(args);
  expect(result.status, `bin/console ${args.join(' ')}: ${result.text}`).toBe(0);
  return result.text;
}

/** Runs a console command with `--json` that must succeed and returns the decoded document. */
export function consoleJson<T = Record<string, unknown>>(args: string[]): T {
  const result = runConsole([...args, '--json']);
  expect(result.status, `bin/console ${args.join(' ')} --json: ${result.text}`).toBe(0);
  return JSON.parse(result.stdout) as T;
}

/** Inserts (or, with `delete`, removes) a Stopped instance (no Stop button for other specs to trip over) of an existing project, bypassing the application. */
export function instanceRow(action: 'insert' | 'delete', project: string, label: string): void {
  const script = `
    $pdo = new PDO('sqlite:' . $argv[1] . '/var/fleet.sqlite');
    $pdo->exec('PRAGMA busy_timeout=10000');
    $id = $argv[3] . '--' . $argv[4];
    if ($argv[2] === 'delete') { $pdo->prepare('DELETE FROM instance WHERE id = ?')->execute([$id]); exit(0); }
    $p = $pdo->prepare('SELECT id FROM project WHERE name = ?'); $p->execute([$argv[3]]);
    $projectId = $p->fetchColumn();
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO instance (id, label, template_name, branch, state, requested_by, created_at, updated_at, project_id) VALUES (?, ?, 'full', 'develop', 'stopped', 'admin', ?, ?, ?)")
        ->execute([$id, $argv[4], $now, $now, $projectId]);
  `;
  // SQLite answers "database is locked" at once (busy_timeout does not apply) when a reader upgrades to a writer under
  // load, for example while the app starts: try a few times.
  let result = spawnSync(php(), ['-r', script, fleetHome(), action, project, label], { encoding: 'utf8' });
  for (let attempt = 0; attempt < 5 && result.status !== 0 && /database is locked/.test(result.stderr + result.stdout); attempt++) {
    spawnSync('sleep', ['1']);
    result = spawnSync(php(), ['-r', script, fleetHome(), action, project, label], { encoding: 'utf8' });
  }
  expect(result.status, `instance ${action}: ${result.stderr}${result.stdout}`).toBe(0);
}
