<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Jobs\SecretRegistry;
use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * What an instance gets so that it cannot reach or steer the others on the same host (FLE-300, instance isolation audit
 * 2026-11-11, R4 R5 R8):
 *
 * - Mailpit (runs inside the DDEV web container) requires basic auth: `MP_UI_AUTH=<user>:<password>` in the web
 *   environment, a per-instance password derived from the app secret and the instance id (HMAC-SHA256, not stored), so the
 *   instance page can show it and no instance can compute the password of another. Applied at deploy and redeploy.
 * - `.ddev/docker-compose.fleet-isolation.yaml` mounts the router's `traefik/` tree of the shared `ddev-global-cache`
 *   volume read-only into the web container (a subpath mount over the read-write mount of the whole volume), so code
 *   in a web container can no longer rewrite the router's merged configuration and hijack the routing of other instances.
 * - Templates without a database get `FLEET_HAS_DB=0` and a poisoned database host (`.invalid` never resolves, RFC 6761),
 *   so a tool never falls back to the generic `db` alias that another project's database also carries (FLE-297).
 * - Templates for AI agents get `FLEET_AGENT=1` (the UI and the docs carry the warning).
 */
final readonly class InstanceIsolation
{
    public const string OVERRIDE_FILE = '.ddev/docker-compose.fleet-isolation.yaml';

    public const string MAILPIT_USER = 'fleet';

    /** A host name that never resolves; the value of the database host variables of a template without a database. */
    public const string POISONED_DB_HOST = 'fleet-no-database.invalid';

    /** The variables a database-less instance gets the poisoned host in. */
    public const array DB_HOST_VARIABLES = ['DB_HOST', 'DATABASE_HOST', 'MYSQL_HOST'];

    public function __construct(
        private TemplateRepository $templates,
        private SecretRegistry $registry,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private string $appSecret,
    ) {
    }

    /**
     * @return array{user: string, password: string}
     */
    public function mailpitCredentials(string $instanceId): array
    {
        return [
            'user' => self::MAILPIT_USER,
            'password' => substr(hash_hmac('sha256', 'fleet-mailpit|'.$instanceId, $this->appSecret), 0, 32),
        ];
    }

    /**
     * @return list<string> `NAME=value` entries for the web environment
     */
    public function variables(Instance $instance, Project $project): array
    {
        $credentials = $this->mailpitCredentials($instance->id());
        $this->registry->register($credentials['password']);
        $variables = ['MP_UI_AUTH='.$credentials['user'].':'.$credentials['password']];

        $template = $this->templates->find($project, $instance->templateName());
        if (false === $template?->hasDatabase()) {
            $variables[] = 'FLEET_HAS_DB=0';
            foreach (self::DB_HOST_VARIABLES as $name) {
                $variables[] = $name.'='.self::POISONED_DB_HOST;
            }
        } else {
            $variables[] = 'FLEET_HAS_DB=1';
        }
        if (true === $template?->isAgent()) {
            $variables[] = 'FLEET_AGENT=1';
        }

        return $variables;
    }

    /**
     * @return string the path written, relative to $dir
     */
    public function write(string $dir): string
    {
        $override = [
            'services' => ['web' => ['volumes' => [[
                'type' => 'volume',
                'source' => 'ddev-global-cache',
                'target' => '/mnt/ddev-global-cache/traefik',
                'read_only' => true,
                'volume' => ['subpath' => 'traefik'],
            ]]]],
        ];
        AtomicFile::write($dir.'/'.self::OVERRIDE_FILE, Yaml::dump($override, 6, 2), 0o640);

        return self::OVERRIDE_FILE;
    }
}
