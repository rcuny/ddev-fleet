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

    private const string KEY = '(?:password|passwd|passphrase|secret|token|api[_-]?key|private[_-]?key|secret[_-]?key|credential|jwt|psk)';

    private function __construct()
    {
    }

    public static function text(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)/s', '-----BEGIN PRIVATE KEY----- '.self::MASK.' -----END PRIVATE KEY-----', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 '.self::MASK, $text);
        // "<anything>key-ish<anything>: value" or "= value", also quoted JSON/YAML ("password": "x").
        $text = (string) preg_replace('/^([^\n]*?[A-Za-z0-9_.-]*'.self::KEY.'[A-Za-z0-9_.-]*["\']?\s*[:=]\s*)(?!\*\*\*)\S[^\n]*$/im', '$1'.self::MASK, $text);

        return $text;
    }
}
