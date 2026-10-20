<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * Where the fleet user's DDEV keeps the global Traefik configuration (`~/.ddev/traefik/`) and its global config
 * (`~/.ddev/global_config.yaml`). The home is the one DDEV reads (FLEET_USER_HOME, as for the Ddev service), so a test
 * hands in a temporary directory and never touches a real DDEV.
 */
final readonly class TraefikDirectory
{
    public const string STATIC_FILENAME = 'static_config.fleet-port-mirror.yaml';
    public const string DYNAMIC_FILENAME = 'fleet-port-mirror.yaml';
    public const string DYNAMIC_SUBDIR = 'custom-global-config';
    public const string MARKER_FILENAME = '.fleet-port-mirror-failed';
    public const string GLOBAL_CONFIG_FILENAME = 'global_config.yaml';

    private string $home;

    /**
     * @param string|null $home the fleet user's home (FLEET_USER_HOME); the process's own HOME when null or empty
     */
    public function __construct(?string $home = null)
    {
        $home = null === $home || '' === $home ? (string) getenv('HOME') : $home;
        if (!str_starts_with($home, '/')) {
            throw new \LogicException('Set FLEET_USER_HOME (or HOME) to the fleet user\'s home directory.');
        }
        $this->home = rtrim($home, '/');
    }

    public function dir(): string
    {
        return $this->home.'/.ddev/traefik';
    }

    public function exists(): bool
    {
        return is_dir($this->dir());
    }

    public function staticFile(): string
    {
        return $this->dir().'/'.self::STATIC_FILENAME;
    }

    public function dynamicDir(): string
    {
        return $this->dir().'/'.self::DYNAMIC_SUBDIR;
    }

    public function dynamicFile(): string
    {
        return $this->dynamicDir().'/'.self::DYNAMIC_FILENAME;
    }

    public function markerFile(): string
    {
        return $this->dir().'/'.self::MARKER_FILENAME;
    }

    public function globalConfigFile(): string
    {
        return $this->home.'/.ddev/'.self::GLOBAL_CONFIG_FILENAME;
    }
}
