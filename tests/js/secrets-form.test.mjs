import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

import * as openpgp from "../../src/fleet/static/openpgp.min.mjs";
import { encryptForm, shouldResetForm } from "../../src/fleet/static/secrets-form.mjs";

const fixtures = join(dirname(fileURLToPath(import.meta.url)), "..", "fixtures", "pgp");
const publicKey = readFileSync(join(fixtures, "test-host-public.asc"), "utf8");
const secretKey = readFileSync(join(fixtures, "test-host-secret.asc"), "utf8");

async function decryptWithFixtureKey(armoredMessage) {
  const { data } = await openpgp.decrypt({
    message: await openpgp.readMessage({ armoredMessage }),
    decryptionKeys: await openpgp.readPrivateKey({ armoredKey: secretKey }),
    format: "binary",
  });
  return new TextDecoder().decode(data);
}

// A just-enough stand-in for the form: only querySelector, which is all
// encryptForm uses. Elements are plain objects with the properties it touches.
function fakeForm({ value, hostKeyText = publicKey, withHostKey = true }) {
  const elements = {
    "#secret-value": { value },
    "#secret-armored": { value: "" },
    "#host-public-key": withHostKey ? { textContent: hostKeyText } : null,
    "#secret-client-error": { hidden: true, textContent: "" },
  };
  return { elements, querySelector: (selector) => elements[selector] ?? null };
}

// `trigger` snapshots the form's fields at call time: htmx reads the form when the
// event fires, so the plaintext must already be cleared and the ciphertext set then.
function fakeHtmx() {
  const calls = [];
  const atTrigger = [];
  return {
    calls,
    atTrigger,
    trigger: (elt, name) => {
      calls.push([elt, name]);
      atTrigger.push({
        plain: elt.elements["#secret-value"].value,
        armored: elt.elements["#secret-armored"].value,
      });
    },
  };
}

test("success: ciphertext in the hidden field, plaintext cleared, event fired", async () => {
  const form = fakeForm({ value: "hunter2-sentinel" });
  const htmx = fakeHtmx();

  const ok = await encryptForm(form, htmx);

  assert.equal(ok, true);
  assert.match(form.elements["#secret-armored"].value, /^-----BEGIN PGP MESSAGE-----/);
  assert.ok(!form.elements["#secret-armored"].value.includes("hunter2"));
  assert.equal(form.elements["#secret-value"].value, "");
  assert.equal(form.elements["#secret-client-error"].hidden, true);
  assert.deepEqual(htmx.calls, [[form, "fleet:encrypted"]]);
  assert.equal(htmx.atTrigger.length, 1);
  assert.match(htmx.atTrigger[0].armored, /^-----BEGIN PGP MESSAGE-----/);
});

test("an empty value shows an error and sends nothing", async () => {
  const form = fakeForm({ value: "" });
  const htmx = fakeHtmx();

  const ok = await encryptForm(form, htmx);

  assert.equal(ok, false);
  assert.match(form.elements["#secret-client-error"].textContent, /empty/);
  assert.equal(form.elements["#secret-client-error"].hidden, false);
  assert.equal(form.elements["#secret-armored"].value, "");
  assert.deepEqual(htmx.calls, []);
});

test("an unreadable host key keeps the typed value so the user can retry", async () => {
  const form = fakeForm({ value: "keep-me", hostKeyText: "garbage" });
  const htmx = fakeHtmx();

  const ok = await encryptForm(form, htmx);

  assert.equal(ok, false);
  assert.match(form.elements["#secret-client-error"].textContent, /could not be read/);
  assert.equal(form.elements["#secret-value"].value, "keep-me");
  assert.equal(form.elements["#secret-armored"].value, "");
  assert.deepEqual(htmx.calls, []);
});

test("a page without the host key element reports it instead of throwing", async () => {
  const form = fakeForm({ value: "x", withHostKey: false });
  const htmx = fakeHtmx();

  assert.equal(await encryptForm(form, htmx), false);
  assert.match(form.elements["#secret-client-error"].textContent, /not available/);
  assert.deepEqual(htmx.calls, []);
});

test("a stale ciphertext from an earlier attempt is cleared", async () => {
  const form = fakeForm({ value: "" });
  form.elements["#secret-armored"].value = "stale";

  await encryptForm(form, fakeHtmx());

  assert.equal(form.elements["#secret-armored"].value, "");
});

test("the plaintext is encrypted exactly as typed: no trimming of spaces or newlines", async () => {
  const value = "  pa ss\r\nword with trailing newline\n ";
  const form = fakeForm({ value });

  assert.equal(await encryptForm(form, fakeHtmx()), true);

  const roundTripped = await decryptWithFixtureKey(form.elements["#secret-armored"].value);
  assert.equal(roundTripped, value);
});

test("on success the plaintext survives in no property of any form element", async () => {
  const value = "plaintext-leak-sentinel";
  const form = fakeForm({ value });

  assert.equal(await encryptForm(form, fakeHtmx()), true);

  for (const [selector, element] of Object.entries(form.elements)) {
    if (element === null) continue;
    for (const [prop, held] of Object.entries(element)) {
      assert.ok(
        !String(held).includes(value),
        `${selector}.${prop} still holds the plaintext`,
      );
    }
  }
});

test("shouldResetForm is true only for a real 2xx status", () => {
  for (const status of [200, 201, 204, 299]) {
    assert.equal(shouldResetForm({ xhr: { status }, successful: true }), true, String(status));
  }
  for (const status of [0, 199, 300, 302, 400, 404, 422, 500]) {
    // successful: true mimics htmx after ui-errors.js clears isError for 4xx.
    assert.equal(shouldResetForm({ xhr: { status }, successful: true }), false, String(status));
  }
});

test("shouldResetForm is false when detail or xhr is missing", () => {
  assert.equal(shouldResetForm(undefined), false);
  assert.equal(shouldResetForm(null), false);
  assert.equal(shouldResetForm({}), false);
  assert.equal(shouldResetForm({ successful: true }), false);
});
