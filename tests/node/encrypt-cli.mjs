// Encrypts stdin to the armored public key in argv[2] with the vendored
// OpenPGP.js (through secrets-crypto.mjs, exactly what the browser runs) and
// prints the armored message. Plaintext comes in on stdin, never on argv.
import { readFileSync } from "node:fs";

import { encryptSecret } from "../../src/fleet/static/secrets-crypto.mjs";

const keyPath = process.argv[2];
if (!keyPath) {
  process.stderr.write("usage: encrypt-cli.mjs <public-key-file> < plaintext\n");
  process.exit(2);
}
const plaintext = readFileSync(0, "utf8");
process.stdout.write(await encryptSecret(readFileSync(keyPath, "utf8"), plaintext));
