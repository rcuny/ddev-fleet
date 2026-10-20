import { test as base } from '@playwright/test';
import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { createHash, generateKeyPairSync, randomBytes } from 'node:crypto';
import { cpSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer, connect, type Server, type Socket } from 'node:net';
import { DatabaseSync } from 'node:sqlite';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// A setup wizard of its own for every test. The wizard exists only on a host that is installed but not set up, and
// finishing it closes it for good, so the served app of the suite (support/start-app.mjs, whose one token the
// existing setup-wizard.desktop.spec.ts consumes) cannot be shared. Each test gets a throw-away FLEET_HOME copied from
// a prepared one (migrated, host initialised, token minted), its own `php -S` and a small forwarder in front of it.
//
// The forwarder exists for the token throttle: it is counted per client address in the app's cache, which every php
// server of the suite shares. The forwarder connects to the server from an address of its own (127.x.y.z, all of
// 127/8 is loopback), so a test's token attempts, and the 429 it provokes on purpose, never count against another
// test or against the served app.

const here = dirname(fileURLToPath(import.meta.url));
const appDir = resolve(here, '../../../app');
const php = process.env.FLEET_PHP || (spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php');

export interface HostOptions {
  /** False: migrated but never `fleet:host:init`ed, the state before the installer ran. */
  init: boolean;
  /** False: Finish cannot render the Caddy configuration (it needs the proxy secret), which is its refusal path. */
  proxySecret: boolean;
  /** `process` runs ssh-keygen and ssh-keyscan for real (the dev default records them and answers "not found"). */
  runner: 'recording' | 'process';
}

export interface WizardHost {
  /** The origin the browser uses (the forwarder). */
  baseURL: string;
  /** The one-time link of the prepared token, on `baseURL`. Empty on a host that was not initialised. */
  setupUrl: string;
  token: string;
  home: string;
  /** Runs a console command against this host's FLEET_HOME; returns stdout, stderr and the exit status. */
  console(args: string[]): { status: number; stdout: string; stderr: string };
  /** `fleet:setup:token`: a new token replaces the old one; returns the link on `baseURL`. */
  mint(): string;
  /** Sets an SQL statement on the host database (the state a clock or a shell would change). */
  sql(statement: string): void;
  /** Reads a value of the host database. */
  scalar(statement: string): unknown;
}

function baseEnv(home: string, proxySecret: boolean, runner: 'recording' | 'process' = 'recording'): NodeJS.ProcessEnv {
  const env: NodeJS.ProcessEnv = {
    ...process.env,
    APP_ENV: 'dev',
    APP_DEBUG: '1',
    FLEET_RUNNER: runner,
    FLEET_HOME: home,
    FLEET_PROFILER_TOOLBAR: '0',
  };
  if (proxySecret) {
    env.FLEET_PROXY_SECRET = randomBytes(24).toString('hex');
  } else {
    delete env.FLEET_PROXY_SECRET;
  }
  return env;
}

function runConsole(home: string, proxySecret: boolean, args: string[]): { status: number; stdout: string; stderr: string } {
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction', '--no-ansi'], { cwd: appDir, env: baseEnv(home, proxySecret), encoding: 'utf8' });
  return { status: result.status ?? 1, stdout: result.stdout, stderr: result.stderr };
}

function mustRun(home: string, args: string[]): string {
  const result = runConsole(home, true, args);
  if (result.status !== 0) {
    throw new Error(`bin/console ${args[0]} failed (exit ${result.status}): ${result.stderr || result.stdout}`);
  }
  return result.stdout;
}

/** A TCP port nobody listens on right now. */
function freePort(): Promise<number> {
  return new Promise((done, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address() as { port: number };
      probe.close(() => done(port));
    });
  });
}

async function waitForHealth(origin: string, server: ChildProcess): Promise<void> {
  for (let attempt = 0; attempt < 150; attempt += 1) {
    if (server.exitCode !== null) {
      throw new Error('the wizard host php server exited before it was ready');
    }
    try {
      const response = await fetch(`${origin}/health`);
      if (response.status < 500) {
        return;
      }
    } catch {
      // not listening yet
    }
    await new Promise((later) => setTimeout(later, 100));
  }
  throw new Error('the wizard host php server did not answer /health');
}

/** A copy-ready FLEET_HOME: migrated, and (unless `init` is false) host initialised with a fresh token. */
export function prepareHome(init: boolean): { home: string; setupLink: string } {
  const home = mkdtempSync(join(tmpdir(), 'fleet-wizard-pristine-'));
  mustRun(home, ['doctrine:migrations:migrate', '--allow-no-migration']);
  if (!init) {
    return { home, setupLink: '' };
  }
  mustRun(home, ['fleet:host:init', '--domain=fleet.example.com', '--tls-mode=http01']);
  const line = mustRun(home, ['fleet:setup:token']).split('\n').find((candidate) => /\/setup\?token=/.test(candidate));
  if (!line) {
    throw new Error('fleet:setup:token printed no setup link');
  }
  return { home, setupLink: line.trim() };
}

interface Running {
  host: WizardHost;
  stop(): Promise<void>;
}

async function startHost(pristine: { home: string; setupLink: string }, options: HostOptions): Promise<Running> {
  const home = mkdtempSync(join(tmpdir(), 'fleet-wizard-'));
  cpSync(pristine.home, home, { recursive: true });

  const phpPort = await freePort();
  const origin = `http://127.0.0.1:${phpPort}`;
  const server = spawn(php, ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${phpPort}`, '-t', 'public'], {
    cwd: appDir,
    env: { ...baseEnv(home, options.proxySecret, options.runner), PHP_CLI_SERVER_WORKERS: '4' },
    stdio: 'ignore',
  });

  // 127.<random>: unique per host, and never 127.0.0.1 (the served app) or the .0.0.0/.255.* edges.
  const source = `127.${1 + (randomBytes(1)[0] % 254)}.${randomBytes(1)[0]}.${1 + (randomBytes(1)[0] % 254)}`;
  const sockets = new Set<Socket>();
  const forwarder: Server = createServer((client) => {
    const upstream = connect({ host: '127.0.0.1', port: phpPort, localAddress: source });
    sockets.add(client).add(upstream);
    const drop = (): void => {
      client.destroy();
      upstream.destroy();
      sockets.delete(client);
      sockets.delete(upstream);
    };
    client.on('error', drop).on('close', drop);
    upstream.on('error', drop).on('close', drop);
    client.pipe(upstream);
    upstream.pipe(client);
  });
  await new Promise<void>((done) => forwarder.listen(0, '127.0.0.1', done));
  const forwarded = `http://127.0.0.1:${(forwarder.address() as { port: number }).port}`;

  const stop = async (): Promise<void> => {
    for (const socket of sockets) {
      socket.destroy();
    }
    await new Promise<void>((done) => forwarder.close(() => done()));
    server.kill('SIGTERM');
    rmSync(home, { recursive: true, force: true });
  };

  try {
    await waitForHealth(origin, server);
  } catch (error) {
    await stop();
    throw error;
  }

  const onForwarder = (link: string): string => {
    const url = new URL(link);
    const target = new URL(forwarded);
    url.protocol = target.protocol;
    url.host = target.host;
    return url.href;
  };
  const database = (): DatabaseSync => new DatabaseSync(join(home, 'var', 'fleet.sqlite'));
  const setupUrl = pristine.setupLink ? onForwarder(pristine.setupLink) : '';

  const host: WizardHost = {
    baseURL: forwarded,
    setupUrl,
    token: setupUrl ? (new URL(setupUrl).searchParams.get('token') ?? '') : '',
    home,
    console: (args) => runConsole(home, options.proxySecret, args),
    mint: () => {
      const line = mustRun(home, ['fleet:setup:token']).split('\n').find((candidate) => /\/setup\?token=/.test(candidate));
      if (!line) {
        throw new Error('fleet:setup:token printed no setup link');
      }
      return onForwarder(line.trim());
    },
    sql: (statement) => {
      const db = database();
      try {
        db.exec(statement);
      } finally {
        db.close();
      }
    },
    scalar: (statement) => {
      const db = database();
      try {
        const row = db.prepare(statement).get() as Record<string, unknown> | undefined;
        return row ? Object.values(row)[0] : undefined;
      } finally {
        db.close();
      }
    },
  };
  return { host, stop };
}

/**
 * `host` is the test's own wizard host, `baseURL` points the page at it. A describe block chooses the starting state
 * with `test.use({ hostOptions: { init: false } })`.
 */
export const test = base.extend<{ hostOptions: HostOptions; host: WizardHost }, { pristine: { initialised: { home: string; setupLink: string }; bare: { home: string; setupLink: string } } }>({
  hostOptions: [{ init: true, proxySecret: true, runner: 'recording' }, { option: true }],
  pristine: [
    // eslint-disable-next-line no-empty-pattern
    async ({}, use) => {
      const initialised = prepareHome(true);
      const bare = prepareHome(false);
      await use({ initialised, bare });
      rmSync(initialised.home, { recursive: true, force: true });
      rmSync(bare.home, { recursive: true, force: true });
    },
    { scope: 'worker' },
  ],
  host: async ({ pristine, hostOptions }, use) => {
    const running = await startHost(hostOptions.init ? pristine.initialised : pristine.bare, hostOptions);
    try {
      await use(running.host);
    } finally {
      await running.stop();
    }
  },
  baseURL: async ({ host }, use) => {
    await use(host.baseURL);
  },
});

export { expect } from '@playwright/test';

/** A new Ed25519 private key in the OpenSSH format, unencrypted, with the fingerprint the page must show for it. */
export function opensshEd25519Key(comment = 'wizard-e2e'): { pem: string; fingerprint: string; publicLine: string } {
  const { privateKey } = generateKeyPairSync('ed25519');
  const jwk = privateKey.export({ format: 'jwk' }) as { d: string; x: string };
  const seed = Buffer.from(jwk.d, 'base64url');
  const pub = Buffer.from(jwk.x, 'base64url');
  const str = (data: Buffer | string): Buffer => {
    const bytes = Buffer.isBuffer(data) ? data : Buffer.from(data);
    const length = Buffer.alloc(4);
    length.writeUInt32BE(bytes.length);
    return Buffer.concat([length, bytes]);
  };
  const u32 = (value: number): Buffer => {
    const bytes = Buffer.alloc(4);
    bytes.writeUInt32BE(value);
    return bytes;
  };
  const publicBlob = Buffer.concat([str('ssh-ed25519'), str(pub)]);
  const check = randomBytes(4);
  let privateBlock = Buffer.concat([check, check, str('ssh-ed25519'), str(pub), str(Buffer.concat([seed, pub])), str(comment)]);
  for (let pad = 1; privateBlock.length % 8 !== 0; pad += 1) {
    privateBlock = Buffer.concat([privateBlock, Buffer.from([pad])]);
  }
  const body = Buffer.concat([Buffer.from('openssh-key-v1\0'), str('none'), str('none'), str(''), u32(1), str(publicBlob), str(privateBlock)]);
  const lines = body.toString('base64').match(/.{1,70}/g) ?? [];
  return {
    pem: `-----BEGIN OPENSSH PRIVATE KEY-----\n${lines.join('\n')}\n-----END OPENSSH PRIVATE KEY-----\n`,
    fingerprint: `SHA256:${createHash('sha256').update(publicBlob).digest('base64').replace(/=+$/, '')}`,
    publicLine: `ssh-ed25519 ${publicBlob.toString('base64')}`,
  };
}
