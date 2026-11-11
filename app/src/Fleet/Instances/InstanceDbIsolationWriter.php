<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Ddev\Ddev;
use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Network isolation of an instance's database (FLE-297), as `.ddev/docker-compose.zzz-fleet-isolation.yaml`.
 *
 * DDEV attaches every `db` container to the shared `ddev_default` network with the alias `db` (and its container name),
 * so a web container of ANOTHER project, also on `ddev_default`, resolves `db` or `ddev-<project>-db` to someone else's
 * database and reaches it with DDEV's default credentials (db/db). Only `web` needs `ddev_default` (ddev-router routes to
 * it); the database is reached over the project's own `default` network. So:
 *
 *  - an instance WITH a db service: the db service's networks are replaced (`!override`, a plain merge would only add
 *    to DDEV's list) by the project network alone, so it leaves `ddev_default` and loses the generic alias there;
 *  - an instance WITHOUT one (`omit_containers: [db]`): `db` and `ddev-<project>-db` are pinned to 127.0.0.1 in EVERY
 *    service, so the names can never resolve to another project's database.
 *
 * The file name sorts after other `docker-compose.*.yaml` files, but DDEV loads a project's `docker-compose.override.yaml`
 * last, so a project can still undo this file. That is why every start path calls {@see self::verify()} afterwards: it
 * reads the live networks of the db container, disconnects it from `ddev_default` when it is still there and fails the
 * job either way. `!override` needs docker compose >= {@see self::MIN_COMPOSE} and DDEV >= {@see self::MIN_DDEV}
 * ({@see self::assertSupported()}).
 *
 * Like the cgroup override the file carries no `#ddev-generated` marker, so DDEV never overwrites it. The service list
 * comes from `ddev debug compose-config`, which works on a stopped project.
 */
final readonly class InstanceDbIsolationWriter
{
    public const string RELATIVE_PATH = '.ddev/docker-compose.zzz-fleet-isolation.yaml';
    /** The name of the first (FLE-297) version of the file; removed on every write. */
    public const string LEGACY_PATH = '.ddev/docker-compose.fleet-db-isolation.yaml';
    public const string MARKER = '# fleet-managed: instance database isolation (FLE-297)';
    public const string MIN_DDEV = '1.24.0';
    public const string MIN_COMPOSE = '2.24.4';
    public const string SHARED_NETWORK = 'ddev_default';

    public function __construct(private Ddev $ddev)
    {
    }

    /**
     * @param list<string> $services
     *
     * @return array<string, mixed> the compose document for these services
     */
    public static function render(array $services, string $project): array
    {
        if (\in_array('db', $services, true)) {
            return ['services' => ['db' => ['networks' => new TaggedValue('override', ['default' => new \stdClass()])]]];
        }
        $pins = ['db:127.0.0.1', 'ddev-'.$project.'-db:127.0.0.1'];
        $document = [];
        foreach ($services as $service) {
            $document[$service] = ['extra_hosts' => $pins];
        }

        return ['services' => $document];
    }

    /**
     * Writes the override. Call it before EVERY `ddev start` or `ddev restart`.
     *
     * @param \Closure(string): void $log
     *
     * @return string the path written, relative to $dir
     *
     * @throws \RuntimeException when DDEV or docker compose is too old, the services cannot be listed or the file cannot be written
     */
    public function write(string $dir, string $project, \Closure $log): string
    {
        $this->assertSupported();
        $services = $this->ddev->composeServices($dir);
        $yaml = self::MARKER."\n".Yaml::dump(self::render($services, $project), 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
        AtomicFile::write($dir.'/'.self::RELATIVE_PATH, $yaml, 0o640);
        if (is_file($dir.'/'.self::LEGACY_PATH)) {
            @unlink($dir.'/'.self::LEGACY_PATH);
        }
        $log(\in_array('db', $services, true)
            ? 'wrote '.self::RELATIVE_PATH.': the db container leaves the shared '.self::SHARED_NETWORK.' network'
            : 'wrote '.self::RELATIVE_PATH.': no db container, "db" is pinned to 127.0.0.1 in '.\count($services).' service(s)');

        return self::RELATIVE_PATH;
    }

    /**
     * Call it after EVERY successful start or restart. The proof that the override took effect: the db container must not
     * be on ddev_default. If it is, it is disconnected at once and the job fails with a clear message.
     *
     * @param \Closure(string): void $log
     *
     * @throws \RuntimeException when the db container is (or was) still on the shared network, or cannot be inspected
     */
    public function verify(string $dir, string $project, \Closure $log): void
    {
        $file = $dir.'/'.self::RELATIVE_PATH;
        if (!is_file($file) || !str_contains((string) file_get_contents($file), '!override')) {
            return; // no db service: nothing to inspect, the pins are static
        }
        $container = 'ddev-'.$project.'-db';
        $networks = $this->ddev->containerNetworks($container);
        if (null === $networks) {
            throw new \RuntimeException('Cannot verify the database isolation: docker does not know the container '.$container.'.');
        }
        if (!\in_array(self::SHARED_NETWORK, $networks, true)) {
            $log('verified: '.$container.' is not on '.self::SHARED_NETWORK.' (networks: '.implode(', ', $networks).')');

            return;
        }
        $cut = $this->ddev->disconnectNetwork(self::SHARED_NETWORK, $container);
        $log('SECURITY: '.$container.' is on '.self::SHARED_NETWORK.' although the isolation override is in place (a project docker-compose file overrides it?)');

        throw new \RuntimeException($container.' is attached to '.self::SHARED_NETWORK.', so other projects could reach its database. '.($cut ? 'It was disconnected from that network; the instance keeps running.' : 'Disconnecting it failed: stop the instance or run "docker network disconnect '.self::SHARED_NETWORK.' '.$container.'".').' Look for a project docker-compose.override.yaml that sets the networks of the db service.');
    }

    /**
     * @throws \RuntimeException below the minimum versions, or when they cannot be read (fail closed)
     */
    public function assertSupported(): void
    {
        $ddev = $this->ddev->ddevVersion();
        if (null === $ddev || version_compare($ddev, self::MIN_DDEV, '<')) {
            throw new \RuntimeException(\sprintf('The database isolation needs DDEV >= %s (found %s).', self::MIN_DDEV, $ddev ?? 'no readable version'));
        }
        $compose = $this->ddev->composeVersion();
        if (null === $compose || version_compare($compose, self::MIN_COMPOSE, '<')) {
            throw new \RuntimeException(\sprintf('The database isolation needs docker compose >= %s for the !override tag (found %s).', self::MIN_COMPOSE, $compose ?? 'no readable version'));
        }
    }

    /**
     * The file exists AND has the content this version writes (the marker and either the network override or the pins).
     */
    public static function isIsolated(string $dir): bool
    {
        $text = @file_get_contents($dir.'/'.self::RELATIVE_PATH);

        return \is_string($text) && str_starts_with($text, self::MARKER) && (str_contains($text, '!override') || str_contains($text, 'extra_hosts'));
    }
}
