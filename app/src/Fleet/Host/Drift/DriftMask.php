<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

/**
 * Hides what looks like a secret in a drift diff or message (FLE-261). The roles already keep secrets out of check
 * output (no_log, which the report callback turns into "hidden"); this is the second line: a value after a key whose
 * name says it is secret, a bearer token, a private key block. A false positive costs a masked value in a report, a
 * miss would put a secret into a page and a file, so the pattern is deliberately wide.
 */
final class DriftMask
{
    public const string MASK = '***';

    private const string KEY = '(?:password|passwd|passphrase|secret|token|api[_-]?key|private[_-]?key|secret[_-]?key|credential|jwt|psk|[_-]key|(?-i:[a-z0-9]Key))';

    private function __construct()
    {
    }

    public static function text(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)/s', '-----BEGIN PRIVATE KEY----- '.self::MASK.' -----END PRIVATE KEY-----', $text);
        $text = self::blockScalars($text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 '.self::MASK, $text);
        // "<anything>key-ish<anything>: value" or "= value", also quoted JSON/YAML ("password": "x").
        $text = (string) preg_replace('/^([^\n]*?[A-Za-z0-9_.-]*'.self::KEY.'[A-Za-z0-9_.-]*["\']?\s*[:=]\s*)(?!\*\*\*)\S[^\n]*$/im', '$1'.self::MASK, $text);

        return $text;
    }

    /**
     * A secret key whose value is a YAML block scalar ("key: |" or ">") keeps its value on the lines below, which the
     * line pattern never sees: mask every following line that is indented deeper than the key (blank lines too). A
     * diff marker ("+", "-", " ") in front of a line is left alone and not counted as indentation.
     */
    private static function blockScalars(string $text): string
    {
        $lines = explode("\n", $text);
        $keyIndent = null;
        foreach ($lines as $i => $line) {
            $marker = '' !== $line && str_contains('+- ', $line[0]) ? $line[0] : '';
            $body = substr($line, \strlen($marker));
            $indent = \strlen($body) - \strlen(ltrim($body, ' '));
            if (null !== $keyIndent) {
                if ('' === trim($body) || $indent > $keyIndent) {
                    $lines[$i] = '' === trim($body) ? $line : $marker.str_repeat(' ', $indent).self::MASK;
                    continue;
                }
                $keyIndent = null;
            }
            if (1 === preg_match('/^\s*(?:-\s+)?[\'"]?[A-Za-z0-9_.-]*'.self::KEY.'[A-Za-z0-9_.-]*[\'"]?\s*:\s*[|>][+-]?\d*[+-]?\s*$/i', $body)) {
                $keyIndent = $indent;
            }
        }

        return implode("\n", $lines);
    }
}
