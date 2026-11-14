<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Shell\CommandRunner;

/**
 * FLE-299: the Docker side of instance isolation, through the CommandRunner seam: the ddev_default bridge and the router's
 * address, and the `fleet-link-*` networks (one internal network per resolved link). Every failure to ask Docker is a
 * DdevUnavailable; every change reports success as a boolean so the caller can log and carry on.
 */
final readonly class DockerNetworks
{
    public const string SHARED = 'ddev_default';
    public const string ROUTER = 'ddev-router';
    private const float TIMEOUT = 30.0;

    public function __construct(private CommandRunner $runner)
    {
    }

    /**
     * The Linux bridge interface of a network: the `com.docker.network.bridge.name` option, else `br-<12 hex of the id>`.
     * Null when the network does not exist.
     *
     * @throws DdevUnavailable
     */
    public function bridgeName(string $network): ?string
    {
        $out = $this->inspect(['network', 'inspect', '--format', '{{.Id}}|{{index .Options "com.docker.network.bridge.name"}}', $network]);
        if (null === $out) {
            return null;
        }
        [$id, $option] = array_pad(explode('|', trim($out), 2), 2, '');
        if ('' !== $option && '<no value>' !== $option) {
            return $option;
        }

        return 1 === preg_match('/^[0-9a-f]{12,}$/D', $id) ? 'br-'.substr($id, 0, 12) : null;
    }

    /**
     * @return list<string> the router's IPv4 addresses on ddev_default (empty when it is not running)
     *
     * @throws DdevUnavailable
     */
    public function routerAddresses(): array
    {
        $out = $this->inspect(['inspect', '--format', '{{with index .NetworkSettings.Networks "'.self::SHARED.'"}}{{.IPAddress}}{{end}}', self::ROUTER]);

        $ip = null === $out ? '' : trim($out);

        return false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) ? [] : [$ip];
    }

    /**
     * @throws DdevUnavailable
     */
    public function gateway(string $network = self::SHARED): ?string
    {
        $out = $this->inspect(['network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Gateway}} {{end}}', $network]);
        $first = null === $out ? '' : (explode(' ', trim($out))[0]);

        return false !== filter_var($first, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) ? $first : null;
    }

    /**
     * Opens a TCP connection from inside a container (bash /dev/tcp, 3 s): true when something answered.
     */
    public function canConnect(string $container, string $host, int $port): bool
    {
        return $this->change(['exec', $container, 'timeout', '3', 'bash', '-c', 'exec 3<>/dev/tcp/$0/$1', $host, (string) $port]);
    }

    /**
     * Every container on the shared network with its address: name => ip.
     *
     * @return array<string, string>
     *
     * @throws DdevUnavailable
     */
    public function sharedContainers(): array
    {
        return $this->addresses(self::SHARED);
    }

    /**
     * The link networks that exist: name => the containers attached to them.
     *
     * @return array<string, list<string>>
     *
     * @throws DdevUnavailable
     */
    public function linkNetworks(): array
    {
        $out = $this->inspect(['network', 'ls', '--filter', 'label='.LinkIds::LABEL, '--format', '{{.Name}}']);
        $networks = [];
        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $name) {
            if (!str_starts_with($name, LinkIds::NETWORK_PREFIX)) {
                continue;
            }
            $members = $this->inspect(['network', 'inspect', '--format', '{{range .Containers}}{{.Name}} {{end}}', $name]);
            $networks[$name] = array_values(array_filter(explode(' ', trim((string) $members))));
        }

        return $networks;
    }

    /**
     * The addresses of the containers on a network: container name => IPv4.
     *
     * @return array<string, string>
     *
     * @throws DdevUnavailable
     */
    public function addresses(string $network): array
    {
        $out = $this->inspect(['network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}', $network]);
        $map = [];
        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $line) {
            [$name, $cidr] = array_pad(explode('|', $line, 2), 2, '');
            if ('' !== $name && '' !== $cidr) {
                $map[$name] = explode('/', $cidr)[0];
            }
        }

        return $map;
    }

    /**
     * The link networks that have this instance as their source or target, by their labels (an exact match: an instance id that
     * is the prefix of another one never catches the other's networks).
     *
     * @return array<string, list<string>> network => members
     *
     * @throws DdevUnavailable
     */
    public function linkNetworksOf(string $instanceId): array
    {
        $format = '{{.Name}}|{{.Label "'.LinkIds::LABEL_SOURCE.'"}}|{{.Label "'.LinkIds::LABEL_TARGET.'"}}';
        $out = $this->inspect(['network', 'ls', '--filter', 'label='.LinkIds::LABEL, '--format', $format]);
        $networks = [];
        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $line) {
            [$name, $source, $target] = array_pad(explode('|', $line, 3), 3, '');
            if (!str_starts_with($name, LinkIds::NETWORK_PREFIX) || ($source !== $instanceId && $target !== $instanceId)) {
                continue;
            }
            $members = $this->inspect(['network', 'inspect', '--format', '{{range .Containers}}{{.Name}} {{end}}', $name]);
            $networks[$name] = array_values(array_filter(explode(' ', trim((string) $members))));
        }

        return $networks;
    }

    public function createLinkNetwork(ResolvedLink $link): bool
    {
        return $this->change([
            'network', 'create', '--driver', 'bridge', '--internal',
            '--label', LinkIds::LABEL.'='.$link->id(),
            '--label', LinkIds::LABEL_SOURCE.'='.$link->sourceInstance,
            '--label', LinkIds::LABEL_TARGET.'='.$link->targetInstance,
            '-o', 'com.docker.network.bridge.name='.$link->bridge(),
            $link->network(),
        ]);
    }

    public function connect(string $network, string $container, ?string $alias = null): bool
    {
        $argv = ['network', 'connect'];
        if (null !== $alias) {
            $argv[] = '--alias';
            $argv[] = $alias;
        }

        return $this->change([...$argv, $network, $container]);
    }

    public function disconnect(string $network, string $container): bool
    {
        return $this->change(['network', 'disconnect', '--force', $network, $container]);
    }

    public function remove(string $network): bool
    {
        return $this->change(['network', 'rm', $network]);
    }

    public function routerRunning(): bool
    {
        return 'true' === trim((string) $this->inspect(['inspect', '--format', '{{.State.Running}}', self::ROUTER]));
    }

    /** The file as the running router sees it (null: missing or unreadable). */
    public function routerRead(string $path): ?string
    {
        return $this->inspect(['exec', self::ROUTER, 'cat', $path]);
    }

    /**
     * Puts a host file into the router's own Traefik directory: copied under a name Traefik ignores, then renamed, so its
     * file watcher never reads a half-written file. DDEV copies `custom-global-config` there only at a `ddev start` (FLE-326).
     */
    public function routerPut(string $hostFile, string $path): bool
    {
        $tmp = $path.'.tmp';

        return $this->change(['cp', $hostFile, self::ROUTER.':'.$tmp]) && $this->change(['exec', self::ROUTER, 'mv', '-f', $tmp, $path]);
    }

    public function routerRemove(string $path): bool
    {
        return $this->change(['exec', self::ROUTER, 'rm', '-f', $path, $path.'.tmp']);
    }

    /**
     * @param list<string> $args
     *
     * @throws DdevUnavailable
     */
    private function inspect(array $args): ?string
    {
        try {
            $result = $this->runner->run(['docker', ...$args], null, [], null, self::TIMEOUT);
        } catch (\Throwable $e) {
            throw new DdevUnavailable('docker did not answer.', 0, $e);
        }

        return $result->isSuccess() ? $result->stdout : null;
    }

    /**
     * @param list<string> $args
     */
    private function change(array $args): bool
    {
        try {
            return $this->runner->run(['docker', ...$args], null, [], null, self::TIMEOUT)->isSuccess();
        } catch (\Throwable) {
            return false;
        }
    }
}
