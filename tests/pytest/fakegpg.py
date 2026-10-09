"""A functional fake of the `gpg=` runner (fleet.core.pgp.GpgRunner) for unit
tests: no GnuPG needed. It answers the exact gpg invocations fleet.core.pgp
makes. "Encryption" is reversible base64 inside a PGP-looking armor, so tests
can check that plaintext only ever travels on stdin and never in argv."""

import base64
from pathlib import Path

from fleet.core.pgp import GpgResult

FINGERPRINT = "A33C8D0AAA32B515776352537E4F1249DB38D303"
SUBKEY_ID = "9455D04F6A22FDA9"
SUBKEY_FPR = "D650EF8CC764C7A3161087FC9455D04F6A22FDA9"
PUBLIC_KEY = "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nfake\n-----END PGP PUBLIC KEY BLOCK-----\n"

# Captured from `gpg --with-colons --list-secret-keys` (GnuPG 2.4.7).
SECRET_KEYS_COLONS = (
    "sec:u:255:22:7E4F1249DB38D303:1791482609:::u:::scESC:::+::ed25519:::0:\n"
    f"fpr:::::::::{FINGERPRINT}:\n"
    "grp:::::::::DE646DBBB6C1532D62C4F27DA3CC921301376686:\n"
    "uid:u::::1791482609::3201D53E11A977BD4FF807F9E9272D03F554F7D6::"
    "fleet host <fleet@localhost>::::::::::0:\n"
    f"ssb:u:255:18:{SUBKEY_ID}:1791482609::::::e:::+::cv25519::\n"
    f"fpr:::::::::{SUBKEY_FPR}:\n"
    "grp:::::::::6F8A7FD7CF14F4DB51694F6CF1BC4E3A2BE06C29:\n"
)

FAKE_BEGIN = "-----BEGIN PGP MESSAGE-----\n\nFAKE:"
FAKE_END = "\n-----END PGP MESSAGE-----\n"


def fake_armor(plaintext: str) -> str:
    return FAKE_BEGIN + base64.b64encode(plaintext.encode("utf-8")).decode("ascii") + FAKE_END


def _fake_plain(armored: str) -> str | None:
    if not (armored.startswith(FAKE_BEGIN) and armored.endswith(FAKE_END)):
        return None
    return base64.b64decode(armored[len(FAKE_BEGIN) : -len(FAKE_END)]).decode("utf-8")


def packets_output(*, keyids=(SUBKEY_ID,), symkey=False, trailing_tags=()) -> str:
    """`gpg --list-only --list-packets` output (GnuPG 2.4.7 format): one PKESK
    per keyid, optionally a password SKESK, then the SEIPDv1 packet, then any
    extra top-level packets (tag numbers) after it."""
    out = []
    off = 0
    for keyid in keyids:
        out.append(
            f"# off={off} ctb=84 tag=1 hlen=2 plen=94\n"
            f":pubkey enc packet: version 3, algo 18, keyid {keyid}\n"
            "\tdata: [263 bits]\n\tdata: [392 bits]\n"
        )
        off += 96
    if symkey:
        out.append(
            f"# off={off} ctb=8c tag=3 hlen=2 plen=13\n"
            ":symkey enc packet: version 4, cipher 9, aead 0, s2k 3, hash 10\n"
            "\tsalt FB1F6C46550DA27D, count 65011712 (255)\n"
        )
        off += 15
    out.append(
        f"# off={off} ctb=d2 tag=18 hlen=2 plen=71 new-ctb\n"
        ":encrypted data packet:\n\tlength: 71\n\tmdc_method: 2\n"
    )
    off += 73
    for tag in trailing_tags:
        out.append(
            f"# off={off} ctb=cb tag={tag} hlen=2 plen=10 new-ctb\n"
            ':literal data packet:\n\tmode b (62), created 0, name="",\n\traw data: 3 bytes\n'
        )
        off += 12
    return "".join(out)


class FakeGpg:
    def __init__(
        self,
        *,
        has_key: bool = False,
        packets: str | None = None,
        packets_returncode: int = 0,
        secret_listing: str | None = None,
        decrypt_result: str | None = None,
    ) -> None:
        self.has_key = has_key
        self.packets = packets
        self.packets_returncode = packets_returncode
        self.secret_listing = secret_listing
        self.decrypt_result = decrypt_result
        self.calls: list[list[str]] = []

    def install_key(self, gnupghome: Path) -> None:
        """Make the on-disk key material look present (SecretStore checks
        `<gnupghome>/private-keys-v1.d` before it will call gpg at all)."""
        keys_dir = gnupghome / "private-keys-v1.d"
        keys_dir.mkdir(parents=True, exist_ok=True)
        (keys_dir / "fake.key").write_bytes(b"x")
        self.has_key = True

    def __call__(self, args, *, gnupghome, input_bytes=None) -> GpgResult:
        self.calls.append(list(args))
        if "--quick-gen-key" in args:
            self.has_key = True
            return GpgResult(0, b"", "")
        if "--quick-add-key" in args:
            return GpgResult(0, b"", "")
        if "--list-secret-keys" in args:
            listing = (self.secret_listing or SECRET_KEYS_COLONS) if self.has_key else ""
            return GpgResult(0, listing.encode("utf-8"), "")
        if "--export" in args:
            return GpgResult(0, PUBLIC_KEY.encode("utf-8"), "")
        if "--encrypt" in args:
            return GpgResult(0, fake_armor((input_bytes or b"").decode("utf-8")).encode(), "")
        if "--decrypt" in args:
            plain = _fake_plain((input_bytes or b"").decode("utf-8"))
            if plain is None:
                return GpgResult(2, b"", "gpg: decryption failed: No secret key")
            result = self.decrypt_result if self.decrypt_result is not None else plain
            return GpgResult(0, result.encode("utf-8"), "")
        if "--list-packets" in args:
            text = self.packets if self.packets is not None else packets_output()
            return GpgResult(self.packets_returncode, text.encode("utf-8"), "")
        return GpgResult(1, b"", f"unexpected gpg call: {args[:1]}")
