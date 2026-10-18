<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * libsodium secretbox (XSalsa20-Poly1305) over the key file. A fresh random nonce is drawn for every seal. The key
 * version is stored with each sealed value and checked on open, so a future key rotation can tell old from new.
 */
final readonly class SecretBox
{
    public const int KEY_VERSION = 1;

    public function __construct(private SecretKeyFile $keyFile)
    {
    }

    /** Seals $plaintext, creating the key file first when this is the first secret ever stored. */
    public function seal(string $plaintext): SealedSecret
    {
        $this->keyFile->ensure();
        $key = $this->keyFile->key();
        try {
            $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return new SealedSecret(sodium_crypto_secretbox($plaintext, $nonce, $key), $nonce, self::KEY_VERSION);
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * @throws SecretOpenFailed on a MAC failure (tampering or wrong key), an unknown key version, a malformed
     *                          nonce/ciphertext or an unusable key file; never returns partial data
     */
    public function open(SealedSecret $sealed): string
    {
        if (self::KEY_VERSION !== $sealed->keyVersion) {
            throw new SecretOpenFailed(\sprintf('Unknown secret key version %d.', $sealed->keyVersion));
        }
        if (\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== \strlen($sealed->nonce)
            || \strlen($sealed->ciphertext) < \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new SecretOpenFailed('The sealed secret is malformed.');
        }

        try {
            $key = $this->keyFile->key();
        } catch (\RuntimeException $e) {
            throw new SecretOpenFailed('The secret key is unavailable.', 0, $e);
        }

        try {
            $plaintext = sodium_crypto_secretbox_open($sealed->ciphertext, $sealed->nonce, $key);
        } finally {
            sodium_memzero($key);
        }
        if (false === $plaintext) {
            throw new SecretOpenFailed('The sealed secret failed authentication (tampered or sealed with another key).');
        }

        return $plaintext;
    }
}
