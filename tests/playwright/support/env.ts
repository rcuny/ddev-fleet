/**
 * Where the two test daemons live. The ports are overridable so a busy machine can run the
 * suite next to a real fleet daemon; `start-daemon.mjs` writes one state file per daemon
 * (its FLEET_HOME and the host key fingerprint), which the tests read back.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const PACKAGE_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');

export type DaemonKind = 'key' | 'nokey';

export const DAEMONS: Record<DaemonKind, { port: number; stateFile: string }> = {
  key: {
    port: Number(process.env.FLEET_E2E_PORT ?? 18791),
    stateFile: join(PACKAGE_DIR, '.run', 'daemon-key.json'),
  },
  nokey: {
    port: Number(process.env.FLEET_E2E_PORT_NOKEY ?? 18792),
    stateFile: join(PACKAGE_DIR, '.run', 'daemon-nokey.json'),
  },
};

export interface DaemonState {
  /** The daemon's FLEET_HOME (a short /tmp directory, owned by the launcher). */
  home: string;
  /** Host key fingerprint, upper-case without spaces; empty for the daemon without a key. */
  fingerprint: string;
  port: number;
}

export function readDaemonState(kind: DaemonKind): DaemonState {
  return JSON.parse(readFileSync(DAEMONS[kind].stateFile, 'utf8')) as DaemonState;
}
