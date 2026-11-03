<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

/**
 * What a managed secret's value may be. The value is substituted verbatim into text files, so it must be text: valid
 * UTF-8, no NUL, 1 byte to 64 KiB. A browser textarea sends CRLF and people paste a trailing newline; both are
 * normalised before anything is checked or stored.
 *
 * A value wrapped in one pair of quotes is refused rather than silently changed: asset files usually quote the
 * placeholder themselves (`TOKEN="[[my-token]]"`), so a quoted value would land as `""abc""` (the 0.x trap).
 */
final class SecretValue
{
    public static function normalise(string $value): string
    {
        return rtrim(str_replace("\r\n", "\n", $value), "\r\n");
    }

    public static function error(#[\SensitiveParameter] mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return 'Enter a value.';
        }
        if (\strlen($value) > SecretStore::MAX_VALUE_BYTES) {
            return \sprintf('The value must be at most %d KiB.', intdiv(SecretStore::MAX_VALUE_BYTES, 1024));
        }
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            return 'The value must be text (UTF-8, no NUL bytes).';
        }
        if (\strlen($value) >= 2 && $value[0] === $value[-1] && ('"' === $value[0] || "'" === $value[0])) {
            return 'Enter the value without surrounding quotes: the asset file that uses the placeholder adds them if it needs them.';
        }

        return null;
    }
}
