<?php

declare(strict_types=1);

namespace App\Api\Terminal;

use App\Fleet\Workspace\Tmux;

/**
 * What the API may type into an agent pane (FLE-286): one line of plain text. An allow-list, because the text reaches a
 * terminal program: valid UTF-8; no control character at all (a newline would submit, an escape would drive the terminal),
 * no C1 control, format character (bidi overrides, zero-width), line or paragraph separator, private-use or surrogate code
 * point; at most Tmux::COMMAND_MAX bytes; not blank; and it must not start with `!`, `/` or `#`, which the agent reads as a
 * shell command, a slash command or a memory note. Raw key names (`C-c`) are plain text here, never keys: the text goes
 * through `tmux send-keys -l`.
 */
final class TerminalInput
{
    /**
     * @return string|null why the text is refused (a fixed sentence, never the text), null when it is acceptable
     */
    public static function problem(string $text): ?string
    {
        if ('' === trim($text)) {
            return 'Must be a non-blank string.';
        }
        if (\strlen($text) > Tmux::COMMAND_MAX) {
            return \sprintf('Must be at most %d bytes.', Tmux::COMMAND_MAX);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return 'Must be valid UTF-8.';
        }
        if (1 === preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Cn}\p{Zl}\p{Zp}]/u', $text)) {
            return 'Must not contain control, format, separator or unassigned characters (no newline: one line of text).';
        }
        if (1 === preg_match('/^[\p{Z}]*[!\/#]/u', $text)) {
            return 'Must not start with "!", "/" or "#": the agent would read it as a command.';
        }

        return null;
    }
}
