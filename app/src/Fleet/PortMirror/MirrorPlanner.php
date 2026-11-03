<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Decides which catalogue ports the router mirrors (FLE-29) and whether the :443 site mirror is active (FLE-32).
 *
 * A colliding entrypoint would stop the shared ddev-router from starting and take every instance down, so a port that
 * would collide is skipped with a reason, never refused with an exception: the router's own ports, another entry's
 * router port (the mirror forwards to it), a duplicate public port, an entrypoint (by name or by port) that another
 * `static_config.*.yaml` already declares, and DDEV's `router_https_port` when it is not 443.
 */
final readonly class MirrorPlanner
{
    /** The DDEV router's own entrypoints on a fleet host: http/https (80/443, 8080/8443), mailpit and the Traefik API. */
    public const array RESERVED_PORTS = [80, 443, 8080, 8443, 8025, 8026, 10999];
    public const int DDEV_DEFAULT_HTTPS_PORT = 443;

    public function __construct(private TraefikDirectory $traefik)
    {
    }

    /**
     * @param list<NamedPort> $catalogue
     */
    public function plan(bool $enabled, array $catalogue): MirrorPlan
    {
        if (!$enabled) {
            return new MirrorPlan();
        }
        if (!$this->traefik->exists()) {
            return new MirrorPlan(skipped: [\sprintf('%s does not exist (DDEV not initialised for this user); nothing to mirror', $this->traefik->dir())]);
        }

        [$foreignPorts, $foreignNames] = $this->foreignEntrypoints();
        $httpsPort = $this->routerHttpsPort();
        $routerPorts = array_map(static fn (NamedPort $port): int => $port->routerPort(), $catalogue);
        usort($catalogue, static fn (NamedPort $a, NamedPort $b): int => [$a->publicPort(), $a->name()] <=> [$b->publicPort(), $b->name()]);

        $selected = [];
        $skipped = [];
        $seen = [];
        foreach ($catalogue as $port) {
            $public = $port->publicPort();
            $entrypoint = MirrorPlan::entrypoint($public);
            $prefix = \sprintf('port "%s" (public %d) not mirrored: ', $port->name(), $public);
            if (\in_array($public, self::RESERVED_PORTS, true)) {
                $skipped[] = $prefix.'the port is used by ddev-router itself';
            } elseif ($public === $httpsPort && self::DDEV_DEFAULT_HTTPS_PORT !== $httpsPort) {
                $skipped[] = $prefix.\sprintf("the port is the router's https port (router_https_port in %s)", TraefikDirectory::GLOBAL_CONFIG_FILENAME);
            } elseif (\in_array($public, $routerPorts, true)) {
                $skipped[] = $prefix.'the port is also a router (upstream) port of the catalogue';
            } elseif (isset($seen[$public])) {
                $skipped[] = $prefix.\sprintf('duplicate of "%s"', $seen[$public]);
            } elseif (isset($foreignNames[$entrypoint])) {
                $skipped[] = $prefix.\sprintf('%s already declares the entrypoint "%s" (delete that file to mirror this port)', $foreignNames[$entrypoint], $entrypoint);
            } elseif (isset($foreignPorts[$public])) {
                $skipped[] = $prefix.\sprintf('%s already declares an entrypoint on :%d (delete that file to mirror this port)', $foreignPorts[$public], $public);
            } else {
                $seen[$public] = $port->name();
                $selected[] = $port;
            }
        }

        [$site, $siteSkipped] = $this->siteTarget($httpsPort, $foreignPorts, $foreignNames);

        return new MirrorPlan($selected, $site, [...$skipped, ...$siteSkipped]);
    }

    /**
     * DDEV's `router_https_port` from `~/.ddev/global_config.yaml`: an int or a numeric string. The file, the key or its
     * value absent means DDEV's default (443); null (unknown) when the file cannot be read, is not a YAML mapping or the
     * value is not a port number.
     */
    public function routerHttpsPort(): ?int
    {
        $path = $this->traefik->globalConfigFile();
        if (!is_file($path)) {
            return self::DDEV_DEFAULT_HTTPS_PORT;
        }
        $text = @file_get_contents($path);
        if (false === $text) {
            return null;
        }
        try {
            $data = Yaml::parse($text);
        } catch (ParseException) {
            return null;
        }
        if (null === $data) {
            return self::DDEV_DEFAULT_HTTPS_PORT;
        }
        if (!\is_array($data) || ([] !== $data && array_is_list($data))) {
            return null;
        }
        $value = $data['router_https_port'] ?? null;
        if (null === $value) {
            return self::DDEV_DEFAULT_HTTPS_PORT;
        }
        if (!\is_int($value) && !\is_string($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (1 !== preg_match('/^[0-9]+$/D', $text)) {
            return null;
        }
        $port = (int) $text;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * The entrypoints that other `static_config.*.yaml` files in the Traefik directory declare: `[port => file]` (only
     * for an address `[host]:<port>`) and `[name => file]`. An unparsable file is ignored.
     *
     * @return array{array<int, string>, array<string, string>}
     */
    public function foreignEntrypoints(): array
    {
        $ports = [];
        $names = [];
        $files = glob($this->traefik->dir().'/static_config.*.yaml') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (TraefikDirectory::STATIC_FILENAME === $name) {
                continue;
            }
            try {
                $data = Yaml::parse((string) file_get_contents($file));
            } catch (ParseException) {
                continue;
            }
            $entrypoints = \is_array($data) ? ($data['entryPoints'] ?? null) : null;
            if (!\is_array($entrypoints)) {
                continue;
            }
            foreach ($entrypoints as $entrypoint => $definition) {
                $names[(string) $entrypoint] ??= $name;
                $address = \is_array($definition) ? ($definition['address'] ?? null) : null;
                if (\is_string($address) && 1 === preg_match('/:(\d+)(?:\/\w+)?$/D', $address, $match)) {
                    $ports[(int) $match[1]] ??= $name;
                }
            }
        }

        return [$ports, $names];
    }

    /**
     * Our live entrypoints: the `fleet-mirror-*` names minus those another static config declares (a leftover spike
     * file is not ours and must not cause restarts).
     *
     * @param list<string> $live
     *
     * @return list<string> sorted
     */
    public function ours(array $live): array
    {
        $foreign = $this->foreignEntrypoints()[1];
        $names = array_values(array_filter(
            array_unique($live),
            static fn (string $name): bool => str_starts_with($name, MirrorPlan::ENTRYPOINT_PREFIX) && !isset($foreign[$name]),
        ));
        sort($names);

        return $names;
    }

    /**
     * @param array<int, string>    $foreignPorts
     * @param array<string, string> $foreignNames
     *
     * @return array{int|null, list<string>}
     */
    private function siteTarget(?int $httpsPort, array $foreignPorts, array $foreignNames): array
    {
        $public = MirrorPlan::SITE_PUBLIC_PORT;
        $prefix = \sprintf('site mirror (:%d) not mirrored: ', $public);
        if (null === $httpsPort) {
            return [null, [\sprintf('site mirror (:%d) not configured: cannot read router_https_port from %s', $public, $this->traefik->globalConfigFile())]];
        }
        if ($public === $httpsPort) {
            return [null, []];
        }
        if (isset($foreignNames[MirrorPlan::SITE_ENTRYPOINT])) {
            return [null, [$prefix.\sprintf('%s already declares the entrypoint "%s" (delete that file to mirror the site on :%d)', $foreignNames[MirrorPlan::SITE_ENTRYPOINT], MirrorPlan::SITE_ENTRYPOINT, $public)]];
        }
        if (isset($foreignPorts[$public])) {
            return [null, [$prefix.\sprintf('%s already declares an entrypoint on :%d (delete that file to mirror the site on :%d)', $foreignPorts[$public], $public, $public)]];
        }

        return [$httpsPort, []];
    }
}
