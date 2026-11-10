<?php

declare(strict_types=1);

namespace App\Api\Token;

/**
 * `flt_svc_<43 random Base62 characters><6 character CRC32 checksum>` (the GitHub token scheme): the fixed prefix lets
 * secret scanners find a leaked token, the checksum lets the app turn typos and noise away without a database lookup.
 * The random part is 43 characters of 62 symbols, about 256 bits.
 */
final class TokenFormat
{
    public const string PREFIX = 'flt_svc_';
    public const int RANDOM_LENGTH = 43;
    public const int CHECKSUM_LENGTH = 6;
    public const int HINT_LENGTH = 8;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const string PATTERN = '/^flt_svc_[0-9A-Za-z]{43}[0-9A-Za-z]{6}$/D';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        $random = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; ++$i) {
            $random .= self::ALPHABET[random_int(0, 61)];
        }
        $body = self::PREFIX.$random;

        return $body.self::checksum($body);
    }

    /** True when $token has the shape and a correct checksum; says nothing about whether it was ever issued. */
    public static function isWellFormed(string $token): bool
    {
        if (1 !== preg_match(self::PATTERN, $token)) {
            return false;
        }
        $body = substr($token, 0, -self::CHECKSUM_LENGTH);

        return hash_equals(self::checksum($body), substr($token, -self::CHECKSUM_LENGTH));
    }

    /** What the database stores: lower-case hex SHA-256 of the full token. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The first characters of the secret part, for a list view. */
    public static function hint(string $token): string
    {
        return substr($token, \strlen(self::PREFIX), self::HINT_LENGTH);
    }

    private static function checksum(string $body): string
    {
        $value = crc32($body);
        $out = '';
        for ($i = 0; $i < self::CHECKSUM_LENGTH; ++$i) {
            $out = self::ALPHABET[$value % 62].$out;
            $value = intdiv($value, 62);
        }

        return $out;
    }
}
