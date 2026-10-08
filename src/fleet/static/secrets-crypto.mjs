// Encrypts one secret value in the browser to the host's GnuPG public key.
// This is the only file that imports OpenPGP.js (vendored unmodified).
import { createMessage, encrypt, readKey } from "./openpgp.min.mjs";

// GnuPG 2.2/2.4 cannot read OpenPGP v6 keys or v2 (AEAD) encrypted-data packets,
// so a v6 host key would produce ciphertext the host can never decrypt. Refuse
// it here, loudly, instead of storing something undecryptable.
const SUPPORTED_KEY_VERSION = 4;

export async function encryptSecret(armoredPublicKey, plaintext) {
  if (typeof plaintext !== "string" || plaintext.length === 0) {
    throw new Error("The secret value is empty.");
  }
  let key;
  try {
    key = await readKey({ armoredKey: armoredPublicKey });
  } catch {
    throw new Error("The host public key could not be read.");
  }
  if (key.isPrivate()) {
    throw new Error("Refusing to encrypt: the host key is a private key.");
  }
  const versions = [key.keyPacket.version, ...key.subkeys.map((s) => s.keyPacket.version)];
  if (versions.some((v) => v !== SUPPORTED_KEY_VERSION)) {
    throw new Error("Unsupported host key: GnuPG needs an OpenPGP version 4 key.");
  }
  // Binary, not text: text mode canonicalises line endings to CRLF inside the
  // packet, and gpg --decrypt returns packet bytes as-is. Encrypt the exact UTF-8 bytes.
  const message = await createMessage({ binary: new TextEncoder().encode(plaintext) });
  return encrypt({
    message,
    encryptionKeys: key,
    format: "armored",
    // Pinned (it is already the default) so a library default change cannot
    // silently switch to SEIPDv2/AEAD, which GnuPG 2.2 cannot read.
    config: { aeadProtect: false },
  });
}
