"""Thin GnuPG wrapper for the encrypted secret store (FLE-21).

Everything goes through an injectable `gpg=` runner (default `run_gpg`) built
on `subprocess.run(capture_output=True)`: stdout and stderr stay separate and
input travels on stdin, so a plaintext value is never in argv. This
deliberately does NOT use `fleet.core.runner.run_streamed`, which merges
stderr into stdout and can echo or log lines.

The host key lives in `GNUPGHOME` (`$FLEET_HOME/gnupg`, 0700). It has no
passphrase because deploys are unattended; it is protected by file
permissions only (see docs/operations.md).
"""

from __future__ import annotations

import os
import re
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

from fleet.core.errors import FleetError

MAX_MESSAGE_BYTES = 64 * 1024

_GPG_TIMEOUT_SECONDS = 60
_FPR_RE = re.compile(r"^[0-9A-F]{40}$")
_KEYID_RE = re.compile(r"^[0-9A-F]{16}$")
_ARMOR_BEGIN = "-----BEGIN PGP MESSAGE-----"
_ARMOR_END = "-----END PGP MESSAGE-----"
_PUBLIC_BEGIN = "-----BEGIN PGP PUBLIC KEY BLOCK-----"
# `gpg --list-packets` (2.4): "# off=96 ctb=d2 tag=18 hlen=2 plen=71 new-ctb"
_PACKET_TAG_RE = re.compile(r"^# off=\d+ ctb=[0-9a-f]+ tag=(\d+)\b", re.MULTILINE)
# ":pubkey enc packet: version 3, algo 18, keyid 9455D04F6A22FDA9"
_PKESK_RE = re.compile(r"^:pubkey enc packet: .*\bkeyid ([0-9A-Fa-f]{16})\b", re.MULTILINE)
_TAG_PKESK = 1
_TAG_SKESK = 3
_TAG_SEIPD = 18
_DEAD_VALIDITY = ("r", "e", "d", "i")
_NO_KEY = "no host key yet: run `fleet keys init` first"
_RECOVER = "; remove the directory (`rm -r {home}`) and run `fleet keys init` again"


class PgpError(FleetError):
    """A GnuPG call failed or a message was rejected."""


@dataclass(frozen=True)
class GpgResult:
    returncode: int
    stdout: bytes = field(repr=False)
    stderr: str


GpgRunner = Callable[..., GpgResult]


def run_gpg(args: list[str], *, gnupghome: Path, input_bytes: bytes | None = None) -> GpgResult:
    """Run `gpg --batch --no-tty <args>` with GNUPGHOME=`gnupghome` (created
    0700 if missing). Never logs. A missing binary is a PgpError."""
    gnupghome.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(gnupghome, 0o700)
    try:
        proc = subprocess.run(
            ["gpg", "--batch", "--no-tty", *args],
            capture_output=True,
            env={**os.environ, "GNUPGHOME": str(gnupghome)},
            input=input_bytes,
            check=False,
            timeout=_GPG_TIMEOUT_SECONDS,
        )
    except FileNotFoundError as exc:
        raise PgpError("gpg is not installed (install the 'gnupg' package)") from exc
    except subprocess.TimeoutExpired:
        raise PgpError(f"gpg did not finish within {_GPG_TIMEOUT_SECONDS} seconds") from None
    return GpgResult(proc.returncode, proc.stdout, proc.stderr.decode("utf-8", "replace"))


@dataclass(frozen=True)
class HostKey:
    fingerprint: str  # 40 hex, uppercase (primary key)
    encryption_subkey_id: str  # 16 hex long key id, uppercase (cv25519 encr subkey)
    armored_public_key: str


def _summary(stderr: str) -> str:
    return " ".join(stderr.split())[:200]


def _checked(result: GpgResult, action: str) -> GpgResult:
    if result.returncode != 0:
        raise PgpError(f"gpg {action} failed (exit {result.returncode}): {_summary(result.stderr)}")
    return result


def _secret_records(gnupghome: Path, gpg: GpgRunner) -> list[list[str]]:
    result = _checked(
        gpg(["--with-colons", "--list-secret-keys"], gnupghome=gnupghome), "key listing"
    )
    text = result.stdout.decode("utf-8", "replace")
    return [line.split(":") for line in text.splitlines() if line]


def _usable_encryption_subkey(rec: list[str]) -> bool:
    return len(rec) > 11 and rec[1] not in _DEAD_VALIDITY and "e" in rec[11].lower()


def _parse_host_ids(records: list[list[str]]) -> tuple[str, str] | None:
    """(primary fingerprint, encryption subkey long id) of the FIRST secret
    key in a `--with-colons --list-secret-keys` listing, or None."""
    fingerprint: str | None = None
    subkey: str | None = None
    seen_primary = False
    want_fpr = False
    for rec in records:
        tag = rec[0]
        if tag == "sec":
            if seen_primary:
                break
            seen_primary = want_fpr = True
        elif tag == "fpr" and want_fpr and len(rec) > 9:
            fingerprint = rec[9].upper()
            want_fpr = False
        elif tag == "ssb" and seen_primary and subkey is None and _usable_encryption_subkey(rec):
            subkey = rec[4].upper()
    if not fingerprint or not subkey:
        return None
    if not _FPR_RE.match(fingerprint) or not _KEYID_RE.match(subkey):
        return None
    return fingerprint, subkey


def _host_ids(gnupghome: Path, gpg: GpgRunner) -> tuple[str, str] | None:
    return _parse_host_ids(_secret_records(gnupghome, gpg))


def _primary_fingerprint(gnupghome: Path, gpg: GpgRunner) -> str | None:
    """Fingerprint of the first secret key, even before it has an encryption
    subkey (`_parse_host_ids` needs both)."""
    want_fpr = False
    for rec in _secret_records(gnupghome, gpg):
        if rec[0] == "sec":
            want_fpr = True
        elif rec[0] == "fpr" and want_fpr and len(rec) > 9:
            return rec[9].upper()
    return None


def host_key(gnupghome: Path, *, gpg: GpgRunner = run_gpg) -> HostKey | None:
    ids = _host_ids(gnupghome, gpg)
    if ids is None:
        return None
    fingerprint, subkey_id = ids
    exported = _checked(
        gpg(["--armor", "--export", fingerprint], gnupghome=gnupghome), "public key export"
    )
    armored = exported.stdout.decode("utf-8", "replace")
    if not armored.startswith(_PUBLIC_BEGIN):
        raise PgpError("gpg exported no public key for the host key")
    return HostKey(fingerprint, subkey_id, armored)


def init_host_key(gnupghome: Path, uid: str, *, gpg: GpgRunner = run_gpg) -> HostKey:
    """Create the host key: ed25519 sign/cert primary plus a cv25519 encryption
    subkey, no passphrase, no expiry (v4 keys, readable by GnuPG 2.2 and 2.4).
    Refuses if a secret key already exists in `gnupghome`."""
    uid = uid.strip()
    if not uid or uid.startswith("-") or any(ord(ch) < 32 or ord(ch) == 0x7F for ch in uid):
        raise PgpError(
            "invalid host key user id: use a non-empty single line that does not start with '-'"
        )
    if any(rec[0] == "sec" for rec in _secret_records(gnupghome, gpg)):
        raise PgpError(
            f"a GnuPG key already exists in {gnupghome}; refusing to create another "
            "(see `fleet keys show`)"
        )
    loopback = ["--pinentry-mode", "loopback", "--passphrase", ""]
    _checked(
        gpg(
            [*loopback, "--quick-gen-key", uid, "ed25519", "sign,cert", "never"],
            gnupghome=gnupghome,
        ),
        "key generation",
    )
    fingerprint = _primary_fingerprint(gnupghome, gpg)
    if fingerprint is None or not _FPR_RE.match(fingerprint):
        raise PgpError(
            f"the key was generated but its fingerprint could not be read; inspect {gnupghome}"
            f"{_RECOVER.format(home=gnupghome)}"
        )
    _checked(
        gpg(
            [*loopback, "--quick-add-key", fingerprint, "cv25519", "encr", "never"],
            gnupghome=gnupghome,
        ),
        "encryption subkey generation",
    )
    key = host_key(gnupghome, gpg=gpg)
    if key is None:
        raise PgpError(
            f"the key was generated but has no usable encryption subkey; inspect {gnupghome}"
            f"{_RECOVER.format(home=gnupghome)}"
        )
    return key


def encrypt(gnupghome: Path, plaintext: str, *, gpg: GpgRunner = run_gpg) -> str:
    """Encrypt `plaintext` to the host key; returns the armored message. The
    plaintext goes to gpg on stdin only."""
    ids = _host_ids(gnupghome, gpg)
    if ids is None:
        raise PgpError(_NO_KEY)
    fingerprint, _ = ids
    result = _checked(
        gpg(
            ["--trust-model", "always", "--armor", "--encrypt", "--recipient", fingerprint],
            gnupghome=gnupghome,
            input_bytes=plaintext.encode("utf-8"),
        ),
        "encryption",
    )
    armored = result.stdout.decode("ascii", "replace")
    if not armored.startswith(_ARMOR_BEGIN):
        raise PgpError("gpg produced no armored message")
    return armored


def decrypt(gnupghome: Path, armored: str, *, gpg: GpgRunner = run_gpg) -> str:
    """Decrypt an armored message with the host key. Plaintext comes from
    gpg's stdout only; error text is built from gpg's stderr and never
    contains the plaintext or the armored input."""
    result = _checked(
        gpg(["--decrypt"], gnupghome=gnupghome, input_bytes=armored.encode("utf-8")),
        "decryption",
    )
    try:
        return result.stdout.decode("utf-8")
    except UnicodeDecodeError:
        raise PgpError("the decrypted secret is not valid UTF-8") from None


def inspect_message(gnupghome: Path, armored: str, *, gpg: GpgRunner = run_gpg) -> None:
    """Accept `armored` only if it is a single armored PGP MESSAGE (at most
    MAX_MESSAGE_BYTES) that is encrypted to this host's encryption subkey and
    contains nothing else: no password-only (SKESK) packet, no extra packets.
    Uses `--list-only --list-packets`, which never decrypts. The error text
    never echoes `armored`."""
    try:
        raw = armored.encode("utf-8")
    except UnicodeEncodeError:
        raise PgpError("not an ASCII-armored PGP MESSAGE") from None
    if len(raw) > MAX_MESSAGE_BYTES:
        raise PgpError(f"the encrypted message is larger than {MAX_MESSAGE_BYTES} bytes")
    text = armored.strip()
    if (
        not text.startswith(_ARMOR_BEGIN)
        or not text.endswith(_ARMOR_END)
        or text.count(_ARMOR_BEGIN) != 1
    ):
        raise PgpError("not an ASCII-armored PGP MESSAGE")
    ids = _host_ids(gnupghome, gpg)
    if ids is None:
        raise PgpError(_NO_KEY)
    _, subkey_id = ids
    result = gpg(["--list-only", "--list-packets"], gnupghome=gnupghome, input_bytes=raw)
    if result.returncode != 0:
        raise PgpError("not a valid OpenPGP message")
    listing = result.stdout.decode("utf-8", "replace")
    tags = [int(tag) for tag in _PACKET_TAG_RE.findall(listing)]
    if _TAG_SKESK in tags:
        raise PgpError("the message is password-encrypted; it must be encrypted to the host key")
    if not tags or any(tag not in (_TAG_PKESK, _TAG_SEIPD) for tag in tags):
        raise PgpError("the message contains unexpected packets")
    if tags.count(_TAG_SEIPD) != 1 or tags[-1] != _TAG_SEIPD:
        raise PgpError("the message contains unexpected packets")
    recipients = {keyid.upper() for keyid in _PKESK_RE.findall(listing)}
    if subkey_id not in recipients:
        raise PgpError("the message is not encrypted to this host's key")
