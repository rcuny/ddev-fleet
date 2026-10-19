// Starts the Symfony app for the Playwright webServer: a throw-away FLEET_HOME, the build steps, an initialised host
// with a fresh setup token for the wizard spec (.run/setup-url.txt), then `php -S`.
// Used only when FLEET_E2E_BASE_URL is unset. Stops the server and removes .run/ on SIGTERM/SIGINT.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
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
// The projects the instance specs deploy: `acme` (admin work, and the project a member must not see) and `demo`, the
// project of the stand-in member (its group is "demo", see .env.dev). Each has a default template.
for (const project of ['acme', 'demo']) {
  console_(['fleet:project:create', project, '--git=https://git.example.com/acme/blog.git', '--default-branch=main']);
  console_(['fleet:template:create', project, 'default']);
  console_(['fleet:project:update', project, '--default-template=default']);
}
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

// `-d variables_order=EGPCS`: some PHP builds (the CI image among them) ship `variables_order=GPCS`, so the
// server's requests would not see the environment this launcher sets (FLEET_HOME, …) and Symfony would fall back
// to the `.env` defaults: another database, without the migrations run above.
const server = spawn(php, ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${port}`, '-t', 'public'], {
  cwd: appDir,
  env: { ...env, PHP_CLI_SERVER_WORKERS: '4' },
  // php -S logs every request and every PHP error to stderr. Keep it: a 5xx must be diagnosable from the CI log.
  stdio: ['ignore', 'ignore', 'pipe'],
});

// On a 5xx the recent server output and the application's own error lines are printed (CI shows only this process'
// output, and a failed request otherwise leaves nothing to read). Capped, so a storm of errors stays readable.
const recent = [];
let dumps = 0;
let partial = '';
// Only what this run wrote: the log outlives the run.
const appLog = join(appDir, 'var/log/dev.log');
const appLogStart = existsSync(appLog) ? statSync(appLog).size : 0;
function appErrors() {
  if (!existsSync(appLog)) return [];
  return readFileSync(appLog)
    .subarray(appLogStart)
    .toString('utf8')
    .split('\n')
    .filter((line) => /\.(CRITICAL|ERROR|EMERGENCY|ALERT):/.test(line))
    .slice(-3)
    .map((line) => line.replace(/(token=)[^\s&"]+/g, '$1[redacted]').slice(0, 2500));
}
function dumpServerFailure() {
  if (dumps++ >= 5) return;
  console.error('start-app: the PHP server answered with a 5xx. Last server output:');
  console.error(recent.join('\n'));
  const errors = appErrors();
  console.error(errors.length ? `start-app: last application errors (var/log/dev.log):\n${errors.join('\n')}` : 'start-app: no application error in var/log/dev.log');
}
server.stderr.on('data', (chunk) => {
  const lines = (partial + chunk.toString()).split('\n');
  partial = lines.pop() ?? '';
  for (const line of lines) {
    // The setup link is a query string; never let it into the CI log.
    const clean = line.replace(/(token=)[^\s&]+/g, '$1[redacted]');
    recent.push(clean);
    if (recent.length > 40) recent.shift();
    if (/\[5\d\d\]:/.test(clean)) dumpServerFailure();
  }
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
