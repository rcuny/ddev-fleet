<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Fleet\Shell\CommandRunner;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-326: the router reads its OWN copies of the files Fleet writes in `~/.ddev/traefik/` (the `ddev-global-cache` volume), which
 * DDEV refreshes only at a `ddev start`; `docker restart ddev-router` does not refresh them. After an upgrade the host files are
 * right and the router keeps running the old ones. This class compares the router's copies with the host files and pushes them:
 *
 * - the port mirror's dynamic file, as it is (`traefik/config/<name>`), picked up by Traefik's file watcher;
 * - the port mirror's static fragment, merged into the router's static configuration file (found from the router's own command
 *   line), which only a router restart makes live. {@see self::repair()} reports whether a restart is needed: the copy changed,
 *   or a PROXY-trusting entry point of the fragment is not trusting live (the copy was pushed earlier without a restart).
 *
 * It never restarts anything itself and never throws: a problem is a message.
 */
final readonly class RouterCopies
{
    public const string ROUTER = 'ddev-router';
    public const string TREE = '/mnt/ddev-global-cache/traefik';
    /** DDEV v1.25's router reads this symlink; `ddev start` renders its target in the cache volume. */
    private const string DDEV_STATIC_LINK = '/etc/traefik/traefik.yaml';
    private const float TIMEOUT = 30.0;

    public function __construct(
        private CommandRunner $runner,
        private TraefikDirectory $traefik,
        private LiveEntrypoints $live,
    ) {
    }

    public function dynamicPath(): string
    {
        return self::TREE.'/config/'.TraefikDirectory::DYNAMIC_FILENAME;
    }

    /**
     * Compares each copy with the host file.
     *
     * @return list<array{what: string, expected: string, actual: string, ok: bool}> empty while the router is not running
     */
    public function check(): array
    {
        if (!$this->running()) {
            return [];
        }
        $rows = [];
        $hostDynamic = @file_get_contents($this->traefik->dynamicFile());
        $copy = $this->read($this->dynamicPath());
        if (\is_string($hostDynamic)) {
            $rows[] = ['what' => 'port mirror dynamic file in ddev-router', 'expected' => 'same as the host file', 'actual' => null === $copy ? 'unreachable' : ($copy === $hostDynamic ? 'match' : 'mismatch'), 'ok' => $copy === $hostDynamic];
        } elseif (null !== $copy) {
            $rows[] = ['what' => 'port mirror dynamic file in ddev-router', 'expected' => 'absent (no mirror)', 'actual' => 'present', 'ok' => false];
        }
        $fragment = $this->fragment();
        if (null !== $fragment) {
            [$state] = $this->staticState($fragment);
            $rows[] = ['what' => 'port mirror static configuration in ddev-router', 'expected' => 'contains the fragment', 'actual' => match ($state) {
                'same' => 'match',
                'different' => 'mismatch',
                default => 'unreachable',
            }, 'ok' => 'same' === $state];
            foreach ($this->trusting($fragment) as $name) {
                $trusted = $this->live->proxyTrusted($name);
                $rows[] = ['what' => 'live entry point '.$name, 'expected' => 'trusts a PROXY header from the loopback', 'actual' => null === $trusted ? 'unreachable (Traefik API)' : ($trusted ? 'match' : 'mismatch (restart pending)'), 'ok' => true === $trusted];
            }
        }

        return $rows;
    }

    /**
     * Pushes what differs. The router's STATIC configuration is pushed only when `$pushStatic` is true (isolation enforce, from
     * `port:refresh` / `host:isolation:enable`): otherwise a differing static copy is only reported (`staticDiffers`), never touched.
     * Before a static push the router's file is backed up, in the router and on the host ({@see self::restoreStatic()}).
     *
     * @return array{pushed: list<string>, restartNeeded: bool, problems: list<string>, staticDiffers: bool}
     */
    public function repair(bool $pushStatic = true): array
    {
        $result = ['pushed' => [], 'restartNeeded' => false, 'problems' => [], 'staticDiffers' => false];
        if (!$this->traefik->exists() || !$this->running()) {
            return $result;
        }
        $hostDynamic = @file_get_contents($this->traefik->dynamicFile());
        $copy = $this->read($this->dynamicPath());
        if (\is_string($hostDynamic)) {
            if ($copy !== $hostDynamic) {
                if ($this->put($this->traefik->dynamicFile(), $this->dynamicPath(), false)) {
                    $result['pushed'][] = 'the port mirror dynamic file';
                } else {
                    $result['problems'][] = 'the port mirror dynamic file could not be copied into the router';
                }
            }
        } elseif (null !== $copy) {
            if ($this->exec(['rm', '-f', $this->dynamicPath()])) {
                $result['pushed'][] = 'removed the port mirror dynamic file';
            } else {
                $result['problems'][] = 'the port mirror dynamic file could not be removed from the router';
            }
        }

        $fragment = $this->fragment();
        if (null === $fragment) {
            return $result;
        }
        [$state, $path, $merged, $original] = $this->staticState($fragment);
        $result['staticDiffers'] = 'different' === $state;
        if (!$pushStatic) {
            return $result;
        }
        if ('unsafe' === $state) {
            $result['problems'][] = 'the merge into the router\'s static configuration would lose a key of the original: nothing pushed, the :443 mirror stays without PROXY';
        } elseif ('different' === $state && null !== $path && null !== $merged && null !== $original) {
            if (!$this->backup($path, $original)) {
                $result['problems'][] = 'the router\'s static configuration could not be backed up: nothing pushed, the :443 mirror stays without PROXY';

                return $result;
            }
            $tmp = $this->traefik->dir().'/.fleet-static-merge.tmp';
            try {
                if (false === @file_put_contents($tmp, $merged)) {
                    throw new \RuntimeException('cannot write '.$tmp);
                }
                if ($this->put($tmp, $path, true)) {
                    $result['pushed'][] = 'the port mirror static configuration';
                    $result['restartNeeded'] = true;
                } else {
                    // A half-written file must not stay: put the original back (the router has not been restarted).
                    $this->restoreStatic();
                    $result['problems'][] = 'the port mirror static configuration could not be copied into the router (the original was restored)';
                }
            } catch (\Throwable $e) {
                $result['problems'][] = 'the port mirror static configuration was not pushed: '.$e->getMessage();
            } finally {
                @unlink($tmp);
            }
        } elseif ('missing' === $state) {
            $result['problems'][] = 'the router\'s static configuration file could not be read, the PROXY trust waits for the next ddev start (the :443 mirror stays without PROXY)';
        }
        if ('same' === $state || $result['restartNeeded']) {
            foreach ($this->trusting($fragment) as $name) {
                if (false === $this->live->proxyTrusted($name)) {
                    $result['restartNeeded'] = true;
                }
            }
        }

        return $result;
    }

    /** Host copy of the router's static file before Fleet changes it (and `<file>.fleet-backup` inside the router). */
    public function backupFile(): string
    {
        return $this->traefik->dir().'/.fleet-router-static.backup';
    }

    /** @return bool true when the original is saved in the router and on the host */
    private function backup(string $path, string $text): bool
    {
        if ('' === trim($text)) {
            return false;
        }
        if (false === @file_put_contents($this->backupFile(), $text)) {
            return false;
        }

        return $this->exec(['cp', '-f', $path, $path.'.fleet-backup']);
    }

    /**
     * Puts the saved original back into the router's static file (the caller restarts the router).
     *
     * @return bool false when there is no backup or it could not be written
     */
    public function restoreStatic(): bool
    {
        $path = null;
        foreach ($this->staticCandidates() as $candidate) {
            if (null !== $this->read($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if (null === $path) {
            return false;
        }
        if (is_file($this->backupFile()) && $this->put($this->backupFile(), $path, true)) {
            return true;
        }

        return $this->exec(['sh', '-c', 'test -s "$0.fleet-backup" && cat "$0.fleet-backup" > "$0"', $path]);
    }

    /** @return array<string, mixed>|null the host's static fragment, null when absent or unreadable */
    private function fragment(): ?array
    {
        $text = @file_get_contents($this->traefik->staticFile());
        if (!\is_string($text)) {
            return null;
        }
        try {
            $data = Yaml::parse($text);
        } catch (ParseException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $fragment
     *
     * @return list<string> the entry points of the fragment that declare a PROXY protocol trust
     */
    private function trusting(array $fragment): array
    {
        $names = [];
        $entryPoints = $fragment['entryPoints'] ?? null;
        foreach (\is_array($entryPoints) ? $entryPoints : [] as $name => $definition) {
            if (\is_array($definition) && isset($definition['proxyProtocol'])) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $fragment
     *
     * @return array{0: string, 1: string|null, 2: string|null, 3: string|null} state (same|different|missing|unsafe), the router's static file, the merged text to write, the file's current text
     */
    private function staticState(array $fragment): array
    {
        foreach ($this->staticCandidates() as $path) {
            $text = $this->read($path);
            if (null === $text) {
                continue;
            }
            try {
                $current = Yaml::parse($text);
            } catch (ParseException) {
                return ['missing', $path, null, null];
            }
            if (!\is_array($current)) {
                return ['missing', $path, null, null];
            }
            $merged = array_replace_recursive($current, $fragment);
            if ($merged == $current) {
                return ['same', $path, null, $text];
            }
            $dump = Yaml::dump($merged, 10, 2);
            try {
                $round = Yaml::parse($dump);
            } catch (ParseException) {
                return ['unsafe', $path, null, null];
            }

            // Every key of DDEV's own file must survive the re-dump (comments do not, DDEV renders the file again at a `ddev start`).
            return \is_array($round) && $round == $merged && self::keepsKeys($current, $round) ? ['different', $path, $dump, $text] : ['unsafe', $path, null, null];
        }

        // Never "unknown": a static file that cannot be read is a mismatch (and nothing is pushed or restarted for it).
        return ['missing', null, null, null];
    }

    /**
     * @param array<mixed> $original
     * @param array<mixed> $result
     */
    private static function keepsKeys(array $original, array $result): bool
    {
        foreach ($original as $key => $value) {
            if (!\array_key_exists($key, $result)) {
                return false;
            }
            if (\is_array($value) && (!\is_array($result[$key]) || !self::keepsKeys($value, $result[$key]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The candidates for the router's static configuration file, best first: where `/etc/traefik/traefik.yaml` points (DDEV's
     * layout: a symlink to `.static_config.yaml` in the cache volume), the `--configFile=` of an older router, and the default name.
     *
     * @return list<string>
     */
    private function staticCandidates(): array
    {
        $candidates = [];
        $link = trim((string) $this->run(['docker', 'exec', self::ROUTER, 'readlink', '-f', self::DDEV_STATIC_LINK]));
        if (str_starts_with($link, self::TREE.'/')) {
            $candidates[] = $link;
        }
        $args = $this->run(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}', self::ROUTER]);
        if (null !== $args && 1 === preg_match('~--configfile=(/[A-Za-z0-9_./-]+)~i', $args, $m) && str_starts_with($m[1], self::TREE.'/')) {
            $candidates[] = $m[1];
        }
        $candidates[] = self::TREE.'/.static_config.yaml';

        return array_values(array_unique($candidates));
    }

    private function running(): bool
    {
        return 'true' === trim((string) $this->run(['docker', 'inspect', '--format', '{{.State.Running}}', self::ROUTER]));
    }

    private function read(string $path): ?string
    {
        return $this->run(['docker', 'exec', self::ROUTER, 'cat', $path]);
    }

    /** Copies a host file into the router: under a temporary name, then renamed (or, for a file Traefik is started with, written over). */
    private function put(string $hostFile, string $path, bool $overwrite): bool
    {
        $tmp = $path.'.fleet-tmp';
        if (null === $this->run(['docker', 'cp', $hostFile, self::ROUTER.':'.$tmp])) {
            return false;
        }

        return $overwrite
            ? $this->exec(['sh', '-c', 'cat "$0" > "$1" && rm -f "$0"', $tmp, $path])
            : $this->exec(['mv', '-f', $tmp, $path]);
    }

    /** @param list<string> $argv */
    private function exec(array $argv): bool
    {
        return null !== $this->run(['docker', 'exec', self::ROUTER, ...$argv]);
    }

    /** @param list<string> $argv */
    private function run(array $argv): ?string
    {
        try {
            $result = $this->runner->run($argv, null, [], null, self::TIMEOUT);
        } catch (\Throwable) {
            return null;
        }

        return $result->isSuccess() ? $result->stdout : null;
    }
}
