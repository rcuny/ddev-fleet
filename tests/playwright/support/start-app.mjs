// Starts the Symfony app for the Playwright webServer: a throw-away FLEET_HOME, the build steps, then `php -S`.
// Used only when FLEET_E2E_BASE_URL is unset. Stops the server and removes .run/ on SIGTERM/SIGINT.
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const appDir = resolve(here, '../../../app');
const runDir = resolve(here, '../.run');
const fleetHome = join(runDir, 'fleet-home');
const port = process.env.FLEET_E2E_PORT || '8000';
const php = process.env.FLEET_PHP || (spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php');

rmSync(runDir, { recursive: true, force: true });
mkdirSync(fleetHome, { recursive: true });

const env = {
  ...process.env,
  APP_ENV: 'dev',
  APP_DEBUG: '1',
  FLEET_RUNNER: 'recording',
  FLEET_HOME: fleetHome,
  // The toolbar would cover the phone bottom bar.
  FLEET_PROFILER_TOOLBAR: '0',
};

function console_(args) {
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], { cwd: appDir, env, stdio: 'inherit' });
  if (result.status !== 0) {
    console.error(`start-app: "bin/console ${args.join(' ')}" failed (exit ${result.status})`);
    cleanup();
    process.exit(result.status ?? 1);
  }
}

function cleanup() {
  rmSync(runDir, { recursive: true, force: true });
}

console_(['importmap:install']);
console_(['tailwind:build']);
console_(['doctrine:migrations:migrate', '--allow-no-migration']);
console_(['messenger:setup-transports']);

const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public'], {
  cwd: appDir,
  env: { ...env, PHP_CLI_SERVER_WORKERS: '4' },
  // php -S logs every request to stderr; application errors are in app/var/log/dev.log.
  stdio: 'ignore',
});

let stopping = false;
function stop() {
  if (stopping) return;
  stopping = true;
  server.kill('SIGTERM');
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
server.on('exit', (code) => {
  cleanup();
  process.exit(stopping ? 0 : (code ?? 1));
});
