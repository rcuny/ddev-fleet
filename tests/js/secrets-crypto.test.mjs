import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

import * as openpgp from "../../src/fleet/static/openpgp.min.mjs";
import { encryptSecret } from "../../src/fleet/static/secrets-crypto.mjs";

const fixtures = join(dirname(fileURLToPath(import.meta.url)), "..", "fixtures", "pgp");
const publicKey = readFileSync(join(fixtures, "test-host-public.asc"), "utf8");
const secretKey = readFileSync(join(fixtures, "test-host-secret.asc"), "utf8");
const SENTINEL = "s3cr3t-sentinel-ÿ-value";

async function decryptWithFixtureKey(armoredMessage) {
  const { data } = await openpgp.decrypt({
    message: await openpgp.readMessage({ armoredMessage }),
    decryptionKeys: await openpgp.readPrivateKey({ armoredKey: secretKey }),
    format: "binary",
  });
  return new TextDecoder().decode(data);
}

test("returns an armored PGP message that does not contain the plaintext", async () => {
  const armored = await encryptSecret(publicKey, SENTINEL);
  assert.match(armored, /^-----BEGIN PGP MESSAGE-----/);
  assert.match(armored.trim(), /-----END PGP MESSAGE-----$/);
  assert.ok(!armored.includes(SENTINEL));
  assert.ok(!armored.includes("sentinel"));
});

test("the host private key decrypts it back to the exact plaintext", async () => {
  const armored = await encryptSecret(publicKey, SENTINEL);
  assert.equal(await decryptWithFixtureKey(armored), SENTINEL);
});

test("multi-line values keep their newlines", async () => {
  const value = "a\nb\r\nc\n  indented ";
  assert.equal(await decryptWithFixtureKey(await encryptSecret(publicKey, value)), value);
});

test("is addressed to the encryption subkey only and uses SEIPDv1 (what GnuPG 2.2 reads)", async () => {
  const armored = await encryptSecret(publicKey, SENTINEL);
  const message = await openpgp.readMessage({ armoredMessage: armored });
  const key = await openpgp.readKey({ armoredKey: publicKey });
  assert.deepEqual(
    message.getEncryptionKeyIDs().map((id) => id.toHex()),
    [key.subkeys[0].getKeyID().toHex()],
  );
  const seipd = message.packets.findPacket(openpgp.enums.packet.symEncryptedIntegrityProtectedData);
  assert.ok(seipd, "expected a SEIPD packet");
  assert.equal(seipd.version, 1);
  assert.equal(message.packets.findPacket(openpgp.enums.packet.aeadEncryptedData), undefined);
});

test("two encryptions of the same value differ (fresh session key)", async () => {
  assert.notEqual(await encryptSecret(publicKey, SENTINEL), await encryptSecret(publicKey, SENTINEL));
});

test("refuses an empty value and a non-string value", async () => {
  await assert.rejects(encryptSecret(publicKey, ""), /empty/);
  await assert.rejects(encryptSecret(publicKey, undefined), /empty/);
});

test("refuses an OpenPGP v6 key (GnuPG 2.2/2.4 cannot decrypt for it)", async () => {
  const { publicKey: v6Public } = await openpgp.generateKey({
    type: "curve25519",
    userIDs: [{ name: "v6 test" }],
    format: "armored",
    config: { v6Keys: true },
  });
  await assert.rejects(encryptSecret(v6Public, SENTINEL), /version 4/);
});

test("refuses a private key and unparseable key text", async () => {
  await assert.rejects(encryptSecret(secretKey, SENTINEL), /private key/);
  await assert.rejects(encryptSecret("not a key", SENTINEL), /could not be read/);
  await assert.rejects(encryptSecret("", SENTINEL), /could not be read/);
});

test("error messages never contain the plaintext", async () => {
  for (const key of ["not a key", secretKey]) {
    await assert.rejects(encryptSecret(key, SENTINEL), (err) => !err.message.includes(SENTINEL));
  }
});
