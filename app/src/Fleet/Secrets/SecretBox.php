<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * libsodium XChaCha20-Poly1305 (IETF AEAD) over the key file. A fresh random 192-bit nonce is drawn for every seal.
 *
 * Every value is bound to a caller-supplied context (for stored secrets: scope, scope id and name, see
 * SecretStore::context()) that is authenticated as additional data, so a ciphertext copied into another row, or
 * another scope, fails to open. The key version is stored with each sealed value and checked on open; version 1 was
 * the unbound secretbox format and is deliberately not readable.
 */
final readonly class SecretBox
{
    public const int KEY_VERSION = 2;
    public const int NONCE_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(private SecretKeyFile $keyFile)
    {
    }

    /** Seals $plaintext for $context, creating the key file first when this is the first secret ever stored. */
    public function seal(#[\SensitiveParameter] string $plaintext, string $context): SealedSecret
    {
        self::assertContext($context);
        $this->keyFile->ensure();
        $key = $this->keyFile->key();
        try {
            $nonce = random_bytes(self::NONCE_BYTES);

            return new SealedSecret(
                sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key),
                $nonce,
                self::KEY_VERSION,
            );
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * @throws SecretOpenFailed on an authentication failure (tampering, wrong key or wrong context), an unknown
     *                          key version, a malformed nonce/ciphertext or an unusable key file; never returns
     *                          partial data
     */
    public function open(SealedSecret $sealed, string $context): string
    {
        self::assertContext($context);
        if (self::KEY_VERSION !== $sealed->keyVersion) {
            throw new SecretOpenFailed(\sprintf('Unknown secret key version %d.', $sealed->keyVersion));
        }
        if (self::NONCE_BYTES !== \strlen($sealed->nonce)
            || \strlen($sealed->ciphertext) < \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new SecretOpenFailed('The sealed secret is malformed.');
        }

        try {
            $key = $this->keyFile->key();
        } catch (\RuntimeException $e) {
            throw new SecretOpenFailed('The secret key is unavailable.', 0, $e);
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed->ciphertext, $context, $sealed->nonce, $key);
        } finally {
            sodium_memzero($key);
        }
        if (false === $plaintext) {
            throw new SecretOpenFailed('The sealed secret failed authentication (tampered, wrong context or sealed with another key).');
        }

        return $plaintext;
    }

    private static function assertContext(string $context): void
    {
        if ('' === $context) {
            throw new \InvalidArgumentException('A secret needs a non-empty context.');
        }
    }
}
