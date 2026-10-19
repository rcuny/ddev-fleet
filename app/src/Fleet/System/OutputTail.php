<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Fleet\Shell\CommandResult;

/**
 * The end of a failed command's output, made safe to put in an exception message: bounded, without control
 * characters and with anything that looks like a credential assignment blanked out.
 */
final class OutputTail
{
    private const int MAX_BYTES = 800;

    /**
     * A credential-looking name (any case, so `ovh_application_secret` and `Token` count too) followed by `=` or `:`
     * and its value, bare or quoted: `NAME=value`, `name: value`, `"name": "value"`, `name='a b'`. The name and the
     * separator stay, so the output still says which value was hidden.
     */
    private const string CREDENTIAL_ASSIGNMENT = '/\b((?:ovh_[a-z0-9_]+|fleet_proxy_secret|[a-z0-9_.-]*(?:secret|token|password|passwd|key)))(["\']?[ \t]*[=:][ \t]*)("[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;]*)/i';

    private function __construct()
    {
    }

    public static function of(CommandResult $result): string
    {
        return self::text('' !== trim($result->stderr) ? $result->stderr : $result->stdout);
    }

    /**
     * The same treatment for any text that may end up in front of a person (an exception message from a setup step).
     */
    public static function text(string $text): string
    {
        $text = (string) preg_replace(self::CREDENTIAL_ASSIGNMENT, '$1$2[redacted]', $text);
        $text = trim($text);
        $text = (string) preg_replace('/[^\x20-\x7E\n\t]/', '?', $text);
        if (\strlen($text) > self::MAX_BYTES) {
            $text = '...'.substr($text, -self::MAX_BYTES);
        }

        return '' === $text ? '(no output)' : $text;
    }
}
