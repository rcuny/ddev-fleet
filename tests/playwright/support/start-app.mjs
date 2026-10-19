// Starts the Symfony app for the Playwright webServer: a throw-away FLEET_HOME, the build steps, an initialised host
// with a fresh setup token for the wizard spec (.run/setup-url.txt), then `php -S`.
// Used only when FLEET_E2E_BASE_URL is unset. Stops the server and removes .run/ on SIGTERM/SIGINT.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
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
  // Finish renders the Caddy configuration, which embeds the proxy secret (32+ characters); a throw-away one per run.
  FLEET_PROXY_SECRET: randomBytes(24).toString('hex'),
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

// Runs a console command and returns its stdout (not echoed: the setup link is a credential until it is used).
function consoleOutput(args) {
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], { cwd: appDir, env, encoding: 'utf8' });
  if (result.status !== 0) {
    console.error(`start-app: "bin/console ${args.join(' ')}" failed (exit ${result.status})`);
    cleanup();
    process.exit(result.status ?? 1);
  }
  return result.stdout;
}

function cleanup() {
  rmSync(runDir, { recursive: true, force: true });
}

console_(['importmap:install']);
console_(['tailwind:build']);
console_(['doctrine:migrations:migrate', '--allow-no-migration']);
console_(['messenger:setup-transports']);
// The throttle on token guesses lives in the app cache, which outlives .run/: start every run with a clean count.
console_(['cache:pool:clear', 'cache.rate_limiter']);
// A host that is installed but not set up yet (what install.sh leaves behind), so /setup is open for the wizard spec.
// FLEET_CADDY_DIR stays at its default under FLEET_HOME: the gate writer refuses a directory outside it.
console_(['fleet:host:init', '--domain=fleet.example.com', '--tls-mode=http01']);
const tokenLine = consoleOutput(['fleet:setup:token']).split('\n').find((line) => /\/setup\?token=/.test(line));
if (!tokenLine) {
  console.error('start-app: fleet:setup:token printed no setup link');
  cleanup();
  process.exit(1);
}
// The command prints the link for the configured domain; the browser reaches the local server instead.
const setupUrl = new URL(tokenLine.trim());
const localOrigin = new URL(`http://127.0.0.1:${port}`);
setupUrl.protocol = localOrigin.protocol;
setupUrl.host = localOrigin.host;
writeFileSync(join(runDir, 'setup-url.txt'), `${setupUrl.href}\n`, { mode: 0o600 });

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
