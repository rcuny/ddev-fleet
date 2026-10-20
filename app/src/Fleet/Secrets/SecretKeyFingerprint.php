<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * A short public name for the secret key: a keyed hash of `keys/secret.key` under a fixed label, never the key itself.
 * A backup records it so a restore can tell that the sealed secrets in it belong to another key.
 */
final readonly class SecretKeyFingerprint
{
    private const string LABEL = 'ddev-fleet backup key fingerprint v1';

    public function __construct(private SecretKeyFile $keyFile)
    {
    }

    /**
     * @return string|null 32 hex characters, or null when there is no usable key on this host
     */
    public function current(): ?string
    {
        try {
            $key = $this->keyFile->key();
        } catch (\RuntimeException) {
            return null;
        }
        $fingerprint = substr(hash_hmac('sha256', self::LABEL, $key), 0, 32);
        sodium_memzero($key);

        return $fingerprint;
    }
}
