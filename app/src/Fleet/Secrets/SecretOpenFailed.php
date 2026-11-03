<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * A sealed secret could not be opened: bad MAC (tampered ciphertext or wrong key), unknown key version, malformed
 * nonce/ciphertext or an unusable key file. Messages never contain plaintext, ciphertext or key material.
 */
final class SecretOpenFailed extends \RuntimeException
{
}
