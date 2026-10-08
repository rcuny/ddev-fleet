# Client-side secret encryption

Since FLE-22 the dashboard's **Secrets** page (`/secrets`) encrypts a secret in
your browser before it is sent. The daemon, the logs, the backups and the files at
rest only ever see an OpenPGP message addressed to the host's GnuPG key (so the host
key can open it); decryption happens at deploy time on the host. This page explains the design, what
it protects, and, just as important, what it does not.

It builds on the encrypted secret store of FLE-21 (`fleet keys`, `fleet secret`,
`secrets/<project>/<KEY>.asc`); its operator side (layout, migration from plaintext,
backing up the key, the systemd sandbox check) is in
[`docs/operations.md`](operations.md#encrypted-project-secrets-fle-21) and the
commands are in [`docs/cli.md`](cli.md). This page does not repeat them.

## Data flow

```
 browser (Secrets page)                         fleet host
 ----------------------                         ----------
 1. GET /secrets  ----------------------------> daemon renders the host PUBLIC key
    <pre id="host-public-key" hidden> + fingerprint (no secret material;
    nothing in the browser checks the key against the fingerprint)
 2. you type NAME and VALUE
 3. secrets.js: submit -> preventDefault
    secrets-form.mjs -> secrets-crypto.mjs
    OpenPGP.js (vendored): readKey, refuse
    non-v4 keys, encrypt to the key's
    encryption subkey
    -> armored PGP MESSAGE (v3 PKESK + SEIPDv1)
 4. the value input is cleared; the hidden
    field `armored` holds the ciphertext
 5. htmx POST /ui/secrets/<project>
    form fields: key, armored (and the
    project name)  ---------------------------> same-origin check (FLE-23), then
    (the value input has no `name`:              SecretStore.set_armored:
     it can never be submitted)                  pgp.inspect_message = packet listing
                                                 only (gpg --list-only --list-packets),
                                                 never a decrypt; must be a single
                                                 armored PGP MESSAGE <= 64 KiB, addressed
                                                 to the host's encryption subkey (it may
                                                 also list other recipients), with
                                                 no password-only packet and no packet
                                                 other than the key-encrypted-session-key
                                                 and the encrypted data
                                              -> secrets/<project>/<KEY>.asc (0600)
 ...later...
 fleet deploy <project>  -------------------> SecretStore.read_all -> gpg --decrypt
                                              -> values into the deploy context
                                                 (asset files, post_deploy and tty
                                                 command lines, as [[token]]s)
```

Details worth knowing:

- The browser encrypts the **exact UTF-8 bytes you typed**, as a binary literal
  (no newline normalisation, no trimming), so what `gpg --decrypt` returns at deploy
  time is byte-for-byte what was in the field. Multi-line values are fine.
- The value input is cleared as soon as the ciphertext exists, i.e. **before** the
  request is sent. After a failed or rejected save the value is gone from the page
  and must be retyped. The **Name** field is kept on a rejection (the form is only
  reset after a 2xx), so you can correct it.
- The server cannot tell whether a stored message will decrypt: `inspect_message`
  is structural (right recipient, right packets), not a trial decryption. A bad
  message addressed to the host key would only fail at deploy time.
- The public key shown to the browser is exported from the host's GnuPG keyring by
  the daemon on each page load. `$FLEET_HOME/host-public-key.asc` (written by
  `fleet keys init|show`, mode 0644) is a convenience copy for humans; the page does
  not read it.
- `fleet secret set` uses the same store but encrypts **on the host** with `gpg`:
  the value passes through the CLI process, so the browser path is the one where the
  server never holds the plaintext before deploy time. With no host key, the CLI
  falls back to a legacy plaintext `secrets/<project>.env` (with a warning); the web
  page does not: it shows no form until a key exists.
- Existing legacy plaintext names are listed on the page as `plaintext`; setting the
  same name from the page stores the encrypted `.asc` and drops the legacy entry.

## Components

| File | Role |
|---|---|
| `src/fleet/static/openpgp.min.mjs` | OpenPGP.js 6.3.2, vendored unmodified, sha256-pinned by `tests/test_vendored_openpgp.py` (see `docs/vendored-assets.md`) |
| `src/fleet/static/secrets-crypto.mjs` | `encryptSecret(armoredPublicKey, plaintext)`; the only importer of OpenPGP.js; refuses non-v4 and private keys and empty values |
| `src/fleet/static/secrets-form.mjs` | `encryptForm`: reads the form, encrypts, fills `armored`, clears the value, fires `fleet:encrypted` |
| `src/fleet/static/secrets.js` | `type="module"` entry: submit / project-change / after-request wiring |
| `src/fleet/daemon.py` | `GET /secrets`, `GET /ui/secrets/{project}`, `POST /ui/secrets/{project}`, `POST /ui/secrets/{project}/{key}/delete` |
| `src/fleet/core/pgp.py`, `src/fleet/core/secretstore.py` | the GnuPG wrapper and the encrypted store (FLE-21) |
| `src/fleet/websecurity.py` | the Content-Security-Policy and the same-origin rule (FLE-23) |

## Key management

- Create the host key once, on the server, as the fleet user: `sudo -u fleet fleet keys init`.
  It makes an ed25519 sign/cert primary key with a cv25519 encryption subkey, no
  expiry and **no passphrase**, in `$FLEET_HOME/gnupg` (mode 0700). `fleet keys show`
  prints the fingerprint (and the encryption subkey id); the Secrets page shows the
  same fingerprint. Comparing the two by eye detects a wrong host or a stale key; it is
  **not** a defence against a malicious page, because the same page supplies both the
  fingerprint it displays and the key it encrypts to, and nothing in the browser
  verifies one against the other. The only out-of-band check is `fleet keys show` on the
  server, and nothing enforces it. `fleet keys init` refuses to run if a key
  already exists.
- **Why OpenPGP version 4 keys.** GnuPG 2.2 and 2.4 (Debian 12/13) cannot read
  OpenPGP v6 keys or the v2 (AEAD) encrypted-data packet. The browser therefore
  refuses any key or subkey that is not version 4 and pins `aeadProtect: false`, so
  what it produces is a v3 PKESK + SEIPDv1 message that GnuPG 2.1 or newer (so 2.2 and 2.4) reads.
  `tests/test_pgp_interop.py` proves this against a real `gpg`, including a committed
  OpenPGP.js ciphertext. `tests/js/secrets-form.test.mjs` covers the form logic against a
  fake htmx, and `tests/e2e/test_secrets_page.py` drives the real page in headless
  Chromium (CSP enforced, real htmx and OpenPGP.js) against a real daemon: save, empty
  value, invalid name, delete and the no-host-key page, ending with `gpg` decrypting what
  the browser encrypted. Both exist because the fake-htmx test alone missed FLE-24 (an
  emptied `required` field made htmx drop the request silently).
- **No passphrase** is deliberate: deploys run unattended (daemon, webhooks,
  `fleet redeploy`), so nobody is there to type one. The key is protected by file
  permissions only; see the threat model.
- **Rotation.** A new host key cannot open messages made for the old one, and
  `fleet keys init` will not overwrite an existing key. Move the old `gnupg`
  directory aside, run `fleet keys init`, set every secret again (page or
  `fleet secret set`), and delete the old directory once a deploy has been verified.
  Back up `$FLEET_HOME/gnupg` separately from `$FLEET_HOME/secrets` (see
  `docs/operations.md`).
- Limits: a message is at most 64 KiB (`pgp.MAX_MESSAGE_BYTES`); names must match
  `^[A-Z][A-Z0-9_]{0,63}$` (letters, digits and underscores, starting with a capital
  letter, at most 64 characters).

## Threat model

| Threat | Protected? | Why / mitigation |
|---|---|---|
| Plaintext in the request body behind TLS termination (Caddy, Authelia, access or debug logs, the daemon's own logs) | **Yes** | only an OpenPGP message is sent; the value input has no `name` |
| Plaintext on disk (`secrets/`, snapshots, backups, `rsync` copies, git) | **Yes**, for secrets set through the page or after `fleet secret migrate` | stored as `<KEY>.asc`. A server without a host key still falls back to the legacy plaintext `.env` for CLI-set secrets until you run `fleet keys init` and `fleet secret migrate --all` |
| Someone who steals a backup or the `secrets/` directory without `gnupg/` | **Yes** | ciphertext only |
| A message made only for another key, or password-only, or plain text, planted through the form | **Yes** | `inspect_message` rejects it before it is stored; nothing is decrypted at upload. A message that is addressed to the host subkey *and* to other keys passes (harmless to the host: it can still be opened with the host key) |
| Cross-site request forging a secret write | **Yes** | same-origin check on every state-changing `/ui/*` request: `Sec-Fetch-Site` must be `same-origin` (`same-site` is refused too, because DDEV instances on sibling subdomains run third-party code); without that header, `Origin` must equal `Host`; a request with neither header (curl, scripts) is allowed (FLE-23) |
| Cross-site scripting reaching the page | Reduced | a strict `Content-Security-Policy` on every HTML response (FLE-23, below), no inline script or style, htmx `allowEval:false` |
| Tampered vendored OpenPGP.js | Reduced | file pinned by sha256 in a test, vendored from the npm tarball after checking the registry's integrity hash, served same-origin only, `script-src 'self'` (see "Why no SRI") |
| **root on the host** | **No** | root can read the unpassphrased key and every `.asc`, or read secrets from a running deploy |
| **Any process running as the fleet user** | **No** | the host key has no passphrase and is readable by that user, and deploys inject the decrypted values into instances anyway |
| **A compromised dashboard page** (a malicious or XSS-modified response, a modified `secrets.js`, a malicious browser extension) | **No** | the page holds the plaintext while you type and chooses which public key to encrypt to. CSP makes injecting script harder, not impossible. The displayed fingerprint does **not** help here: the page supplies both it and the key, and nothing in the browser verifies the key against it. Comparing it with `fleet keys show` on the server only detects a wrong host or stale key |
| **Plaintext after decryption** | **No** | at deploy time values reach the instance's asset files, `post_deploy` command lines and tty commands (as `[[token]]` substitutions) exactly as before; encryption protects them in transit and at rest only |
| Loss of the host key | n/a | the secrets cannot be recovered; keep a backup of `gnupg/` |

### The Content-Security-Policy

Every `text/html` response (pages and htmx fragments) carries this header, with the
request's `Host` substituted into `connect-src` when it is a plain host[:port]:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self' wss://<Host>; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'
```

together with `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`.
For this page that means: only scripts served by the daemon itself run (no inline
script, no `eval`, no third-party origin), the page cannot be framed, and a form cannot
post elsewhere. It does not stop a compromised daemon or a malicious browser extension.
The source is `src/fleet/websecurity.py`; `tests/test_csp_templates.py` fails if a
template reintroduces inline script or style.

## Why no Subresource Integrity (SRI)

SRI protects against a third party (a CDN) serving a different file than the one the page
author reviewed. Here every script is served by the daemon itself from `/static/`, and an
attacker able to change the file could change the HTML carrying the `integrity` attribute
just as easily. The real guards are: the pinned sha256 in `tests/test_vendored_openpgp.py`
(any change fails CI; the current pin, also in `docs/vendored-assets.md`, is
`7d3285efa6dfedbb34a136d8b5ad21c28fb973269df0b2818dcb74dfb40b59d9`), vendoring from the
npm tarball after verifying its registry hash, `script-src 'self'` (no third-party script
can load), and review of every `docs/vendored-assets.md` bump.

## Verifying a deployment

- The fingerprint on the page equals the one printed by `sudo -u fleet fleet keys show`
  (this detects a wrong host or a stale key, not a malicious page; see "Key management").
- After saving a secret, `GNUPGHOME=$FLEET_HOME/gnupg gpg --list-only --list-packets secrets/<project>/<KEY>.asc`
  (as the fleet user; keep `--list-only`, without it gpg would decrypt) shows one `:pubkey enc packet:` (version 3) addressed to the
  encryption subkey and an `:encrypted data packet:` with `mdc_method: 2`, and no
  `:symkey enc packet:`.
- The response header `Content-Security-Policy` contains `script-src 'self'`
  (`curl -sI` on the dashboard URL, through your normal auth).
- `grep -r "<a value you typed>" $FLEET_HOME/secrets` finds nothing, and neither do the
  daemon and reverse-proxy access logs. Do not grep all of `$FLEET_HOME` after a deploy
  that uses the secret: deploys write the substituted value into the instance's asset
  files and may print it in command lines or logs, which is expected.

## Browser requirements

A current browser with ES modules (`<script type="module">`). Without JavaScript the
page cannot encrypt and stores nothing: a native submit is only a
`GET /secrets?project=...&key=...&armored=` (empty `armored`), because the value input
has no `name` and is never sent.
