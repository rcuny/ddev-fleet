<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * The Claude Code usage-limit dialog in an agent pane (FLE-368): "What do you want to do?" with the options "Stop and wait for
 * limit to reset" and "Wait here, then continue automatically". Detected from the plain capture of the pane; text that merely
 * mentions a usage limit is not the dialog (both option lines must be present). Fleet never answers the dialog.
 */
final readonly class UsageLimit
{
    private function __construct(
        /** The reset time: ISO-8601 when it could be parsed, else as printed, else null when the dialog does not show one. */
        public ?string $resetsAt,
    ) {
    }

    /** Null when the capture does not show the dialog. */
    public static function detect(string $capture, ?\DateTimeImmutable $now = null): ?self
    {
        $plain = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $capture);
        if (1 !== preg_match('/\bStop and wait for limit to reset\b/i', $plain)
            || 1 !== preg_match('/\bWait here, then continue automatically\b/i', $plain)
            || 1 !== preg_match('/What do you want to do\?/i', $plain)) {
            return null;
        }

        return new self(self::resetTime($plain, $now ?? new \DateTimeImmutable()));
    }

    private static function resetTime(string $plain, \DateTimeImmutable $now): ?string
    {
        if (1 !== preg_match('/\bresets[ \t]+(?:at[ \t]+|on[ \t]+)?([^\r\n]{1,80})/i', $plain, $m)) {
            return null;
        }
        $raw = trim((string) preg_replace('/[│|\s]+$/u', '', $m[1]));
        if ('' === $raw) {
            return null;
        }
        $zone = null;
        $clock = $raw;
        if (1 === preg_match('/^(.*?)\s*\(([A-Za-z_]+(?:\/[A-Za-z_+\-0-9]+)*|UTC)\)\s*$/', $raw, $z)) {
            $clock = $z[1];
            try {
                $zone = new \DateTimeZone($z[2]);
            } catch (\Exception) {
                return $raw;
            }
        }
        if (1 !== preg_match('/^[A-Za-z]{0,9}\s*[0-9A-Za-z:, ]{1,30}$/', $clock) || 1 !== preg_match('/[0-9]/', $clock)) {
            return $raw;
        }
        try {
            $local = $now->setTimezone($zone ?? $now->getTimezone());
            $at = new \DateTimeImmutable($clock, $local->getTimezone());
        } catch (\Exception) {
            return $raw;
        }
        // A time of day already past today means tomorrow.
        if ($at <= $local && 1 !== preg_match('/[A-Za-z]{3,}\s*[0-9]|[0-9]{4}/', $clock)) {
            $at = $at->modify('+1 day');
        }

        return $at->format(\DATE_ATOM);
    }
}
