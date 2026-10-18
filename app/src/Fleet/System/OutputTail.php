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

    private function __construct()
    {
    }

    public static function of(CommandResult $result): string
    {
        $text = '' !== trim($result->stderr) ? $result->stderr : $result->stdout;
        $text = (string) preg_replace('/\b(OVH_[A-Z_]+|FLEET_PROXY_SECRET|[A-Z_]*(?:SECRET|TOKEN|PASSWORD|KEY))=\S*/', '$1=[redacted]', $text);
        $text = trim($text);
        $text = (string) preg_replace('/[^\x20-\x7E\n\t]/', '?', $text);
        if (\strlen($text) > self::MAX_BYTES) {
            $text = '...'.substr($text, -self::MAX_BYTES);
        }

        return '' === $text ? '(no output)' : $text;
    }
}
