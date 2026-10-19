<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * A template's command list (post-deploy and the two terminal panes). Each entry is exactly one line: it is later run as
 * argv or typed into a terminal pane, where an embedded newline would become a second command and a tab or an escape
 * sequence would change what is typed (decisions P6, D7). So every control character is refused, tab included, and the
 * Unicode line/paragraph separators and format characters with them.
 */
final class CommandLines
{
    public const int MAX_LINES = 50;
    public const int MAX_LINE = 2000;

    /**
     * Splits a textarea into lines, trims spaces and tabs at both ends and drops the blank lines. Nothing else is
     * removed: what error() must refuse is left in place.
     *
     * @return list<string>
     */
    public static function normalise(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line, " \t");
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public static function error(mixed $lines): ?string
    {
        if (!\is_array($lines) || !array_is_list($lines)) {
            return 'The commands must be a list of lines.';
        }
        if (\count($lines) > self::MAX_LINES) {
            return \sprintf('At most %d commands are allowed.', self::MAX_LINES);
        }
        foreach ($lines as $index => $line) {
            $n = $index + 1;
            if (!\is_string($line)) {
                return \sprintf('Command line %d is not text.', $n);
            }
            if (\strlen($line) > self::MAX_LINE * 4 || !mb_check_encoding($line, 'UTF-8')) {
                return \sprintf('Command line %d is too long or is not valid UTF-8.', $n);
            }
            if ('' === trim($line)) {
                return \sprintf('Command line %d is empty.', $n);
            }
            if (mb_strlen($line, 'UTF-8') > self::MAX_LINE) {
                return \sprintf('Command line %d is longer than %d characters.', $n, self::MAX_LINE);
            }
            if (1 === preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $line)) {
                return \sprintf('Command line %d contains a control character (a command is exactly one line, without tabs).', $n);
            }
        }

        return null;
    }
}
