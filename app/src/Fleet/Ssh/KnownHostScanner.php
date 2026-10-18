<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * Reads the host keys a server offers (`ssh-keyscan`) so the user can compare their fingerprints before trusting
 * them. Nothing is trusted here: callers show the fingerprints and add the entries only on confirmation.
 */
final readonly class KnownHostScanner
{
    private const int MAX_OUTPUT_LINES = 32;

    public function __construct(private CommandRunner $runner)
    {
    }

    /**
     * @return list<KnownHostEntry> empty when the host is unreachable, offers no usable key or the scan fails
     *
     * @throws \InvalidArgumentException when $host is not a valid host name (checked before anything runs)
     */
    public function scan(string $host): array
    {
        $host = KnownHostEntry::normalizeHost($host);

        $port = [];
        $target = $host;
        if (str_starts_with($host, '[')) {
            $close = (int) strrpos($host, ']');
            $port = ['-p', substr($host, $close + 2)];
            $target = substr($host, 1, $close - 1);
        }

        try {
            $result = $this->runner->run(
                ['ssh-keyscan', '-T', '5', '-t', 'ed25519,ecdsa,rsa', ...$port, '--', $target],
                null,
                [],
                null,
                20.0,
            );
        } catch (CommandTimedOut|CommandRunnerException) {
            return [];
        }
        if (!$result->isSuccess()) {
            return [];
        }

        $entries = [];
        $seen = 0;
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (++$seen > self::MAX_OUTPUT_LINES) {
                break;
            }
            $parts = preg_split('/\s+/', $line);
            if (false === $parts || 3 !== \count($parts)) {
                continue;
            }
            try {
                $entry = new KnownHostEntry($parts[0], $parts[1], $parts[2]);
            } catch (\InvalidArgumentException) {
                continue;
            }
            // Only keys for the host that was asked about (a server cannot name other hosts in its own answer).
            if ($entry->host === $host) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
