<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Fleet\FleetPaths;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The fleet's own known_hosts file (FleetPaths::knownHostsFile()), used by every git/ssh command the fleet runs.
 *
 * Trust rules (a host key is an identity, so an extra key for a host is a man-in-the-middle):
 *  - seed() adds the providers' published keys and never removes anything, except a key the seed itself marks
 *    `@revoked`;
 *  - add() never attaches a different key to a host that already has one (seeded or not); that needs an explicit
 *    replace(), which is itself refused for the seeded provider hosts (their keys change only through the seed).
 *    A seeded host accepts only the seed's own keys through add(), even if seed() has not run or its lines are gone;
 *  - every entry is a validated KnownHostEntry and the file is rewritten atomically under a lock, so no input can
 *    inject a line or leave a half-written file.
 *
 * Lines the fleet did not write (comments, hashed hosts, host lists, certificate authorities) are kept verbatim and
 * are taken into account when deciding whether a host already has a key.
 */
final readonly class KnownHostsFile
{
    public function __construct(
        private FleetPaths $paths,
        #[Autowire('%kernel.project_dir%/resources/ssh/known_hosts.seed')]
        private string $seedFile,
    ) {
    }

    /**
     * Adds the seed lines that are missing. Local additions are kept.
     */
    public function seed(): void
    {
        $seed = $this->seedEntries();

        $this->modify(static function (array $lines) use ($seed): array {
            $revokedInSeed = array_values(array_filter($seed, static fn (KnownHostEntry $e): bool => $e->revoked));
            $isRevoked = static function (KnownHostEntry $entry) use ($revokedInSeed): bool {
                foreach ($revokedInSeed as $revoked) {
                    if ($revoked->sameKeyAs($entry)) {
                        return true;
                    }
                }

                return false;
            };

            // The one thing seed() removes: a plain single-host line for a key the seed has revoked.
            $kept = [];
            $present = [];
            foreach ($lines as $line) {
                $parsed = self::parse($line);
                if (null === $parsed) {
                    $kept[] = $line;
                    continue;
                }
                $entries = $parsed['entries'] ?? [];
                if (null === $parsed['marker'] && 1 === \count($entries) && !$entries[0]->revoked && $isRevoked($entries[0])) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $present[$entry->line()] = true;
                }
                $kept[] = $line;
            }

            foreach ($seed as $entry) {
                if (!$entry->revoked && $isRevoked($entry)) {
                    continue;
                }
                if (!isset($present[$entry->line()])) {
                    $kept[] = $entry->line();
                    $present[$entry->line()] = true;
                }
            }

            return $kept;
        });
    }

    /**
     * Every well-formed `host type key` line, one entry per listed host; hashed hosts cannot be listed.
     *
     * @return list<KnownHostEntry>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->read() as $line) {
            foreach (self::parse($line)['entries'] ?? [] as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Whether the file trusts a key for $host (hashed entries and host lists included).
     */
    public function has(string $host): bool
    {
        $host = KnownHostEntry::normalizeHost($host);
        foreach ($this->read() as $line) {
            $parsed = self::parse($line);
            if (null !== $parsed && '@revoked' !== $parsed['marker'] && self::mentions($parsed['hosts'], $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Idempotent for a key that is already there.
     *
     * @throws KnownHostConflict when the host already has a different key
     */
    public function add(KnownHostEntry $entry): void
    {
        $this->addAll([$entry]);
    }

    /**
     * Adds several keys of one host (what a scan returns) in one step. A host with no entry yet gets all of them; a
     * host that has entries only accepts keys it already holds (a no-op).
     *
     * @param list<KnownHostEntry> $entries
     *
     * @throws KnownHostConflict
     */
    public function addAll(array $entries): void
    {
        $host = self::singleHost($entries);
        if (null === $host) {
            return;
        }
        // A seeded provider host accepts only the seed's own keys, whatever the file currently holds: the check must
        // not depend on seed() having run or on the seed lines still being there.
        $seeded = array_values(array_filter($this->seedEntries(), static fn (KnownHostEntry $e): bool => $e->host === $host));
        if ([] !== $seeded) {
            foreach ($entries as $entry) {
                $builtIn = false;
                foreach ($seeded as $known) {
                    $builtIn = $builtIn || (!$known->revoked && $known->sameKeyAs($entry));
                }
                if (!$builtIn) {
                    throw new KnownHostConflict(\sprintf('"%s" is a seeded provider host; only its published keys are accepted.', $host));
                }
            }
        }

        $this->modify(static function (array $lines) use ($entries, $host): array {
            $existing = [];
            $held = false;
            foreach ($lines as $line) {
                $parsed = self::parse($line);
                if (null === $parsed || '@revoked' === $parsed['marker'] || !self::mentions($parsed['hosts'], $host)) {
                    continue;
                }
                $held = true;
                foreach ($parsed['entries'] ?? [] as $entry) {
                    $existing[$entry->line()] = true;
                }
            }

            if (!$held) {
                return self::appended($lines, $entries);
            }
            foreach ($entries as $entry) {
                if (!isset($existing[$entry->line()])) {
                    throw new KnownHostConflict(\sprintf('"%s" already has a different host key; replacing it needs an explicit replace.', $host));
                }
            }

            return $lines;
        });
    }

    /**
     * Explicit replacement of every key of a host (a rotated host key the user has verified). Refused for the seeded
     * provider hosts, whose keys only change through the seed.
     *
     * @param list<KnownHostEntry> $entries
     *
     * @throws KnownHostConflict
     */
    public function replace(array $entries): void
    {
        $host = self::singleHost($entries);
        if (null === $host) {
            return;
        }
        foreach ($this->seedEntries() as $seeded) {
            if ($seeded->host === $host) {
                throw new KnownHostConflict(\sprintf('"%s" is a seeded provider host; its keys change only with the seed.', $host));
            }
        }

        $this->modify(static function (array $lines) use ($entries, $host): array {
            $kept = [];
            foreach ($lines as $line) {
                $parsed = self::parse($line);
                // Marker lines (@revoked, @cert-authority) are never edited here.
                if (null === $parsed || null !== $parsed['marker'] || !self::mentions($parsed['hosts'], $host)) {
                    $kept[] = $line;
                    continue;
                }
                $rewritten = self::withoutHost($line, $parsed, $host);
                null !== $rewritten && $kept[] = $rewritten;
            }

            return self::appended($kept, $entries);
        });
    }

    /**
     * @param list<string>         $lines
     * @param list<KnownHostEntry> $entries
     *
     * @return list<string>
     */
    private static function appended(array $lines, array $entries): array
    {
        foreach ($entries as $entry) {
            if (!\in_array($entry->line(), $lines, true)) {
                $lines[] = $entry->line();
            }
        }

        return $lines;
    }

    /**
     * @param list<KnownHostEntry> $entries
     */
    private static function singleHost(array $entries): ?string
    {
        if ([] === $entries) {
            return null;
        }
        $host = $entries[0]->host;
        foreach ($entries as $entry) {
            if ($entry->revoked || $entry->host !== $host) {
                throw new \InvalidArgumentException('Entries must be unrevoked keys of one host.');
            }
        }

        return $host;
    }

    /**
     * @return list<KnownHostEntry>
     */
    private function seedEntries(): array
    {
        $contents = @file_get_contents($this->seedFile);
        if (false === $contents) {
            throw new \RuntimeException('The known_hosts seed file is missing.');
        }

        $entries = [];
        foreach (explode("\n", $contents) as $number => $line) {
            if ('' === trim($line) || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $parsed = self::parse($line);
            if (null === $parsed || null === $parsed['entries'] || 1 !== \count($parsed['entries'])) {
                throw new \RuntimeException(\sprintf('Invalid line %d in the known_hosts seed file.', $number + 1));
            }
            $entries[] = $parsed['entries'][0];
        }

        return $entries;
    }

    /**
     * Splits a line into its marker, host field tokens and (when it is a plain `host type key` line with valid hosts)
     * its entries. Null for blank lines and comments.
     *
     * @return array{marker: ?string, hosts: list<string>, entries: ?list<KnownHostEntry>}|null
     */
    private static function parse(string $line): ?array
    {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            return null;
        }
        $fields = preg_split('/\s+/', $line);
        if (false === $fields) {
            return null;
        }
        $marker = null;
        if (str_starts_with($fields[0], '@')) {
            $marker = array_shift($fields);
        }
        if (\count($fields) < 3) {
            return ['marker' => $marker, 'hosts' => [], 'entries' => null];
        }

        $hosts = explode(',', $fields[0]);
        $entries = null;
        if (null === $marker || '@revoked' === $marker) {
            $entries = [];
            foreach ($hosts as $host) {
                try {
                    $entries[] = new KnownHostEntry($host, $fields[1], $fields[2], '@revoked' === $marker);
                } catch (\InvalidArgumentException) {
                    // A pattern, an IP in a form we do not write, a hashed host: kept in the file, not listed.
                }
            }
        }

        return ['marker' => $marker, 'hosts' => $hosts, 'entries' => $entries];
    }

    /**
     * @param list<string> $hosts the comma-separated tokens of a host field
     */
    private static function mentions(array $hosts, string $host): bool
    {
        foreach ($hosts as $token) {
            if (str_starts_with($token, '|1|')) {
                $parts = explode('|', $token);
                $salt = base64_decode($parts[2] ?? '', true);
                $hash = base64_decode($parts[3] ?? '', true);
                if (false !== $salt && false !== $hash && hash_equals($hash, hash_hmac('sha1', $host, $salt, true))) {
                    return true;
                }
                continue;
            }
            if (strtolower($token) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line without $host in its host list, or null when nothing is left.
     *
     * @param array{marker: ?string, hosts: list<string>, entries: ?list<KnownHostEntry>} $parsed
     */
    private static function withoutHost(string $line, array $parsed, string $host): ?string
    {
        $remaining = array_values(array_filter(
            $parsed['hosts'],
            static fn (string $token): bool => !self::mentions([$token], $host),
        ));
        if ([] === $remaining) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($line)) ?: [];
        $hostIndex = null === $parsed['marker'] ? 0 : 1;
        $fields[$hostIndex] = implode(',', $remaining);

        return implode(' ', $fields);
    }

    /**
     * @return list<string>
     */
    private function read(): array
    {
        $path = $this->paths->knownHostsFile();
        if (is_link($path)) {
            throw new \RuntimeException('The known_hosts file must not be a symlink.');
        }
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Cannot read the known_hosts file.');
        }

        return '' === $contents ? [] : explode("\n", rtrim($contents, "\n"));
    }

    /**
     * Read-modify-write under an exclusive lock; the file is replaced atomically and only when it changed.
     *
     * @param callable(list<string>): list<string> $change
     */
    private function modify(callable $change): void
    {
        $dir = $this->paths->sshDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }

        $lock = @fopen($dir.'/.known_hosts.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            false !== $lock && fclose($lock);

            throw new \RuntimeException('Cannot lock the known_hosts file.');
        }
        try {
            $before = $this->read();
            $after = $change($before);
            if ($after !== $before) {
                AtomicFile::write($this->paths->knownHostsFile(), [] === $after ? '' : implode("\n", $after)."\n", 0o644);
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
