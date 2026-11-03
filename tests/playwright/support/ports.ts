import { spawnSync } from 'node:child_process';
import { randomInt } from 'node:crypto';
import { fileURLToPath } from 'node:url';

/** Where the app under test keeps its data: the throw-away home of the webServer, or the container's FLEET_HOME. */
function fleetHome(): string {
  return process.env.FLEET_E2E_BASE_URL ? (process.env.FLEET_HOME as string) : fileURLToPath(new URL('../.run/fleet-home', import.meta.url));
}

export interface ConsoleResult {
  status: number;
  stdout: string;
  stderr: string;
}

/** Runs a console command of the app under test (dev environment, recording runner, the app's own FLEET_HOME). */
export function runConsole(args: string[]): ConsoleResult {
  const appDir = fileURLToPath(new URL('../../../app', import.meta.url));
  const php = spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php';
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], {
    cwd: appDir,
    env: { ...process.env, APP_ENV: 'dev', FLEET_RUNNER: 'recording', FLEET_HOME: fleetHome() },
    encoding: 'utf8',
  });
  return { status: result.status ?? -1, stdout: result.stdout, stderr: result.stderr };
}

export interface CreatedPort {
  name: string;
  publicPort: number;
  routerPort: number;
}

/**
 * Port numbers are unique across the whole catalogue and the suite runs in parallel on one database, so every call
 * draws random numbers in a range no migration or other spec uses and tries again when the draw collided.
 */
export function createPort(name: string): CreatedPort {
  let last = '';
  for (let attempt = 0; attempt < 8; attempt++) {
    const publicPort = randomInt(20000, 40000);
    const routerPort = randomInt(40000, 60000);
    const result = runConsole(['fleet:port:create', name, `--public=${publicPort}`, `--router=${routerPort}`]);
    if (0 === result.status) {
      return { name, publicPort, routerPort };
    }
    last = result.stderr + result.stdout;
    if (!/already used|Another port/.test(last)) {
      break;
    }
  }
  throw new Error(`fleet:port:create ${name} failed: ${last}`);
}

/** Removes a port from the catalogue; quiet when it is already gone or still used (made for `finally`). */
export function deletePort(name: string): void {
  runConsole(['fleet:port:delete', name, '--yes']);
}
