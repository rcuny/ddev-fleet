<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * Reads Caddy's journal (`journalctl -u caddy -o json`, one JSON object per line) for certificate trouble: Let's
 * Encrypt rate limits, failed challenge solves (HTTP-01, DNS-01 and its cleanup) and issued certificates. Pure
 * string work. Caddy logs JSON itself, so a journal MESSAGE is usually a JSON document again; plain-text messages
 * are matched the same way. Lines that are neither are ignored, and nothing from a line but a shortened excerpt and
 * valid hostnames is ever kept.
 */
final class AcmeLogParser
{
    public const int MAX_NAMES = 25;
    public const int MAX_ERRORS = 5;
    private const int EXCERPT = 300;

    private const string RATE_LIMITED = '/rateLimited|too many certificates|too many (?:failed authorizations|new orders)|\b429\b/i';
    private const string RETRY_AFTER = '/retry after (\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/i';
    private const string FAILED_SOLVE = '/solving challenges|challenge failed|cleaning up (?:solver|challenge)|cleanup (?:failed|error)|unable to (?:present|clean ?up)|acme: error|authorization failed|dns[-_ ]?01.*(?:error|failed)/i';
    private const string OBTAINED = '/certificate obtained successfully/i';
    private const string HOSTNAME = '/^(?=.{1,253}$)(?:\*\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D';

    /**
     * @param iterable<string> $lines journalctl `-o json` lines
     */
    public function parse(iterable $lines): AcmeEvents
    {
        $rateLimited = 0;
        $rateFirst = $rateLast = $retryAfter = $solveFirst = null;
        $rateNames = $solveNames = $errors = [];
        $solves = $obtained = 0;

        foreach ($lines as $line) {
            $entry = self::decode($line);
            if (null === $entry) {
                continue;
            }
            [$time, $message, $level, $names] = $entry;

            if (1 === preg_match(self::OBTAINED, $message)) {
                ++$obtained;
                continue;
            }
            $isError = 'error' === $level || 'warn' === $level || 'warning' === $level || null === $level;
            if (!$isError) {
                continue;
            }
            if (1 === preg_match(self::RATE_LIMITED, $message)) {
                ++$rateLimited;
                $rateFirst ??= $time;
                $rateLast = $time ?? $rateLast;
                if (1 === preg_match(self::RETRY_AFTER, $message, $m)) {
                    $at = new \DateTimeImmutable($m[1].' '.$m[2], new \DateTimeZone('UTC'));
                    $retryAfter = null === $retryAfter || $at > $retryAfter ? $at : $retryAfter;
                }
                $rateNames = self::merge($rateNames, $names);
                $errors = self::remember($errors, $message);
            } elseif (1 === preg_match(self::FAILED_SOLVE, $message)) {
                ++$solves;
                $solveFirst ??= $time;
                $solveNames = self::merge($solveNames, $names);
                $errors = self::remember($errors, $message);
            }
        }

        return new AcmeEvents($rateLimited, $rateFirst, $rateLast, $retryAfter, $rateNames, $solves, $solveFirst, $solveNames, $obtained, $errors);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: string, 2: ?string, 3: list<string>}|null time, text, log level, hostnames
     */
    private static function decode(string $line): ?array
    {
        $journal = json_decode($line, true);
        if (!\is_array($journal) || !isset($journal['MESSAGE']) || !\is_string($journal['MESSAGE'])) {
            return null;
        }
        $message = $journal['MESSAGE'];
        $time = null;
        $micros = $journal['__REALTIME_TIMESTAMP'] ?? null;
        if (\is_string($micros) && ctype_digit($micros)) {
            $time = new \DateTimeImmutable('@'.intdiv((int) $micros, 1_000_000));
        }

        $level = null;
        $names = [];
        $inner = json_decode($message, true);
        if (\is_array($inner)) {
            $level = isset($inner['level']) && \is_string($inner['level']) ? strtolower($inner['level']) : null;
            if (isset($inner['ts']) && (\is_int($inner['ts']) || \is_float($inner['ts']))) {
                $time = new \DateTimeImmutable('@'.(int) $inner['ts']);
            }
            foreach (['identifier', 'domain', 'subject'] as $key) {
                if (isset($inner[$key]) && \is_string($inner[$key])) {
                    $names[] = $inner[$key];
                }
            }
            foreach (['identifiers', 'names'] as $key) {
                if (isset($inner[$key]) && \is_array($inner[$key])) {
                    array_push($names, ...array_filter($inner[$key], '\is_string'));
                }
            }
            $message = trim(\sprintf('%s %s %s', $inner['logger'] ?? '', $inner['msg'] ?? '', $inner['error'] ?? ''));
        }
        $names = array_values(array_filter(array_map('strtolower', $names), static fn (string $n): bool => 1 === preg_match(self::HOSTNAME, $n)));

        return [$time, $message, $level, $names];
    }

    /**
     * @param list<string> $known
     * @param list<string> $new
     *
     * @return list<string>
     */
    private static function merge(array $known, array $new): array
    {
        foreach ($new as $name) {
            if (\count($known) >= self::MAX_NAMES) {
                break;
            }
            if (!\in_array($name, $known, true)) {
                $known[] = $name;
            }
        }

        return $known;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function remember(array $errors, string $message): array
    {
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message));
        $text = mb_strimwidth($text, 0, self::EXCERPT, '…');
        $errors = array_values(array_filter($errors, static fn (string $e): bool => $e !== $text));
        $errors[] = $text;

        return \array_slice($errors, -self::MAX_ERRORS);
    }
}
