<?php

declare(strict_types=1);

namespace App\Fleet\Host;

/**
 * What a Claude token may look like, for the host settings form and `fleet claude:token:set` alike: printable ASCII
 * without spaces, 1 to 512 characters.
 */
final class ClaudeTokenRule
{
    public const string TOKEN_SHAPE = '/^[\x21-\x7E]{1,512}$/D';
    public const string MESSAGE = 'A token has no spaces or control characters and is at most 512 characters. Copy it again from "claude setup-token".';

    private function __construct()
    {
    }

    public static function isValid(#[\SensitiveParameter] string $token): bool
    {
        return 1 === preg_match(self::TOKEN_SHAPE, $token);
    }
}
