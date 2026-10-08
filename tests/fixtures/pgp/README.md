# PGP test fixtures (FLE-22)

**Test-only. Never use these keys for anything real.** The secret key has no
passphrase and is public in this repository on purpose; secret scanners that flag
`test-host-secret.asc` can be told to ignore this directory.

| File | What it is |
|---|---|
| `test-host-public.asc` | v4 public key `FLE-22 test fixture <fixture@example.invalid>`: ed25519 sign/cert primary + cv25519 encryption subkey, no expiry (what `fleet keys init` creates) |
| `test-host-secret.asc` | its unprotected secret key |
| `interop-message.asc` | produced by OpenPGP.js (the vendored file) for the key above; plaintext `openpgpjs-interop-fixture-é` (UTF-8, no trailing newline). Decrypted with real gpg by `tests/test_pgp_interop.py` |

Regenerate the message after an OpenPGP.js upgrade:

    printf 'openpgpjs-interop-fixture-\xc3\xa9' | node tests/js/encrypt-cli.mjs \
      tests/fixtures/pgp/test-host-public.asc > tests/fixtures/pgp/interop-message.asc
