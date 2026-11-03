<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * The HMAC both senders use: `X-Hub-Signature: sha256=<hex HMAC-SHA256(secret, raw body)>`.
 */
final class Signature
{
    public static function sign(string $body, #[\SensitiveParameter] string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * Constant-time check. A missing, malformed or wrong header is simply false.
     */
    public static function valid(string $body, ?string $header, #[\SensitiveParameter] string $secret): bool
    {
        if (null === $header || 1 !== preg_match('/^sha256=[0-9a-fA-F]{64}$/D', $header)) {
            return false;
        }

        return hash_equals(self::sign($body, $secret), 'sha256='.strtolower(substr($header, 7)));
    }
}
