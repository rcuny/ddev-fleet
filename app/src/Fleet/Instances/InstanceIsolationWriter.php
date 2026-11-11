<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Ddev\Ddev;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Network isolation of an instance's database (FLE-297), as `.ddev/docker-compose.fleet-isolation.yaml`.
 *
 * DDEV attaches every `db` container to the shared `ddev_default` network with the alias `db` (and its container name),
 * so a web container of ANOTHER project, also on `ddev_default`, resolves `db` or `ddev-<project>-db` to someone else's
 * database and reaches it with DDEV's default credentials (db/db). Only `web` needs `ddev_default` (ddev-router routes to
 * it); the database is reached over the project's own `default` network. So:
 *
 *  - an instance WITH a db service: the db service's networks are replaced (`!override`, a plain merge would only add
 *    to DDEV's list) by the project network alone, so it leaves `ddev_default` and loses the generic alias there;
 *  - an instance WITHOUT one (`omit_containers: [db]`): `db` is pinned to 127.0.0.1 in the web service's hosts, so the
 *    name can never resolve to another project's database.
 *
 * Like the cgroup override the file carries no `#ddev-generated` marker, so DDEV never overwrites it. The service list
 * comes from `ddev debug compose-config`, which works on a stopped project.
 */
final readonly class InstanceIsolationWriter
{
    public const string RELATIVE_PATH = '.ddev/docker-compose.fleet-isolation.yaml';

    public function __construct(private Ddev $ddev)
    {
    }

    /**
     * @param list<string> $services
     *
     * @return array<string, mixed> the compose document for these services
     */
    public static function render(array $services): array
    {
        if (\in_array('db', $services, true)) {
            return ['services' => ['db' => ['networks' => new TaggedValue('override', ['default' => new \stdClass()])]]];
        }

        return ['services' => ['web' => ['extra_hosts' => ['db:127.0.0.1']]]];
    }

    /**
     * @return string the path written, relative to $dir
     *
     * @throws \RuntimeException when the services cannot be listed or the file cannot be written
     */
    public function write(string $dir, JobLog $log): string
    {
        $services = $this->ddev->composeServices($dir);
        AtomicFile::write($dir.'/'.self::RELATIVE_PATH, Yaml::dump(self::render($services), 6, 2, Yaml::DUMP_OBJECT_AS_MAP), 0o640);
        $log->line(\in_array('db', $services, true)
            ? 'wrote '.self::RELATIVE_PATH.': the db container leaves the shared ddev_default network'
            : 'wrote '.self::RELATIVE_PATH.': no db container, "db" is pinned to 127.0.0.1');

        return self::RELATIVE_PATH;
    }

    public static function isIsolated(string $dir): bool
    {
        return is_file($dir.'/'.self::RELATIVE_PATH);
    }
}
