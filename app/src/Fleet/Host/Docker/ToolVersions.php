<?php

declare(strict_types=1);

namespace App\Fleet\Host\Docker;

use App\Fleet\Shell\CommandRunner;

/**
 * FLE-310: the instance isolation features need Docker Engine >= 26 (FLE-300 read-only traefik mount), docker compose
 * >= 2.24.4 (FLE-297 db isolation) and DDEV >= 1.24.0. The installer pre-flight checks them at install and upgrade
 * time (install/lib/preflight.sh, same minimums); this shows the live versions on /admin/host.
 *
 * A tool that does not answer or prints no version is reported with a null version and `ok: false`.
 */
final readonly class ToolVersions
{
    public const string MIN_DOCKER = '26.0.0';
    public const string MIN_COMPOSE = '2.24.4';
    public const string MIN_DDEV = '1.24.0';

    public function __construct(private CommandRunner $runner)
    {
    }

    /**
     * @return list<ToolVersion>
     */
    public function read(): array
    {
        return [
            $this->one('Docker Engine', ['docker', 'version', '--format', '{{.Server.Version}}'], self::MIN_DOCKER),
            $this->one('docker compose', ['docker', 'compose', 'version', '--short'], self::MIN_COMPOSE),
            $this->one('DDEV', ['ddev', '--version'], self::MIN_DDEV),
        ];
    }

    /**
     * @param list<string> $argv
     */
    private function one(string $label, array $argv, string $minimum): ToolVersion
    {
        try {
            $result = $this->runner->run($argv, timeout: 10.0);
        } catch (\Throwable) {
            return new ToolVersion($label, null, $minimum, false);
        }
        $version = $result->isSuccess() ? self::parse($result->stdout) : null;

        return new ToolVersion($label, $version, $minimum, null !== $version && version_compare($version, $minimum, '>='));
    }

    /** The first x.y[.z] in the output (a leading `v`, `-rc1` or `+ds` suffixes do not matter). */
    public static function parse(string $output): ?string
    {
        return 1 === preg_match('/\d+\.\d+(?:\.\d+)?/', $output, $m) ? $m[0] : null;
    }
}
