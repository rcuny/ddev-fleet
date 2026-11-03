<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * The persisted form of one secret: libsodium secretbox ciphertext (MAC included), its nonce and the version of the
 * key that sealed it.
 */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public int $keyVersion,
    ) {
    }
}
