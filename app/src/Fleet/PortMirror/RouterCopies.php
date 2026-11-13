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
            $rows[] = ['what' => 'port mirror dynamic file in ddev-router', 'expected' => 'same as the host file', 'actual' => null === $copy ? 'missing' : ($copy === $hostDynamic ? 'same' : 'different'), 'ok' => $copy === $hostDynamic];
        } elseif (null !== $copy) {
            $rows[] = ['what' => 'port mirror dynamic file in ddev-router', 'expected' => 'absent (no mirror)', 'actual' => 'present', 'ok' => false];
        }
        $fragment = $this->fragment();
        if (null !== $fragment) {
            [$state] = $this->staticState($fragment);
            $rows[] = ['what' => 'port mirror static configuration in ddev-router', 'expected' => 'contains the fragment', 'actual' => $state, 'ok' => 'same' === $state];
            foreach ($this->trusting($fragment) as $name) {
                $trusted = $this->live->proxyTrusted($name);
                $rows[] = ['what' => 'live entry point '.$name, 'expected' => 'trusts a PROXY header from the loopback', 'actual' => null === $trusted ? 'unknown (API)' : ($trusted ? 'trusts' : 'does not trust (restart pending)'), 'ok' => true === $trusted];
            }
        }

        return $rows;
    }

    /**
     * Pushes what differs.
     *
     * @return array{pushed: list<string>, restartNeeded: bool, problems: list<string>}
     */
    public function repair(): array
    {
        $result = ['pushed' => [], 'restartNeeded' => false, 'problems' => []];
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
        [$state, $path, $merged] = $this->staticState($fragment);
        if ('different' === $state && null !== $path && null !== $merged) {
            $tmp = $this->traefik->dir().'/.fleet-static-merge.tmp';
            try {
                if (false === @file_put_contents($tmp, $merged)) {
                    throw new \RuntimeException('cannot write '.$tmp);
                }
                if ($this->put($tmp, $path, true)) {
                    $result['pushed'][] = 'the port mirror static configuration';
                    $result['restartNeeded'] = true;
                } else {
                    $result['problems'][] = 'the port mirror static configuration could not be copied into the router';
                }
            } catch (\Throwable $e) {
                $result['problems'][] = 'the port mirror static configuration was not pushed: '.$e->getMessage();
            } finally {
                @unlink($tmp);
            }
        } elseif ('unknown' === $state) {
            $result['problems'][] = 'the router\'s static configuration file could not be found, the PROXY trust waits for the next ddev start';
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
     * @return array{0: string, 1: string|null, 2: string|null} state (same|different|unknown), the router's static file, the merged text to write
     */
    private function staticState(array $fragment): array
    {
        $path = $this->staticPath();
        if (null === $path) {
            return ['unknown', null, null];
        }
        $text = $this->read($path);
        if (null === $text) {
            return ['unknown', $path, null];
        }
        try {
            $current = Yaml::parse($text);
        } catch (ParseException) {
            return ['unknown', $path, null];
        }
        if (!\is_array($current)) {
            return ['unknown', $path, null];
        }
        $merged = array_replace_recursive($current, $fragment);
        if ($merged == $current) {
            return ['same', $path, null];
        }

        return ['different', $path, Yaml::dump($merged, 10, 2)];
    }

    /** The configuration file Traefik was started with (`--configFile=` in the router's command line). */
    private function staticPath(): ?string
    {
        $out = $this->run(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}', self::ROUTER]);
        if (null === $out) {
            return null;
        }
        if (1 !== preg_match('~--configfile=(/[A-Za-z0-9_./-]+)~i', $out, $m) || !str_starts_with($m[1], self::TREE.'/')) {
            return null;
        }

        return $m[1];
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
