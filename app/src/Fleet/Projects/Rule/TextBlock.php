<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * Any multi-line text field (hostnames, command lists, ...) before it is split into lines: bounded in size so that a
 * megabyte pasted into a textarea is refused instead of stored or parsed, and free of NUL and invalid UTF-8.
 */
final class TextBlock
{
    public const int MAX = 10_000;

    public static function error(mixed $text): ?string
    {
        if (!\is_string($text)) {
            return 'Enter text.';
        }
        // 4 bytes per character at most: a cheap refusal before any multibyte function sees a huge string
        if (\strlen($text) > self::MAX * 4) {
            return self::tooLong();
        }
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            return 'The text contains characters that are not allowed.';
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX) {
            return self::tooLong();
        }

        return null;
    }

    private static function tooLong(): string
    {
        return \sprintf('The text must be at most %d characters.', self::MAX);
    }
}
