<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Jobs\SecretRegistry;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;
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
 * - `.ddev/docker-compose.fleet-isolation.yaml` (fail-safe, see write()) mounts the router's `traefik/` tree of the shared `ddev-global-cache`
 *   volume read-only into the web container (a subpath mount over the read-write mount of the whole volume), so code
 *   in a web container can no longer rewrite the router's merged configuration and hijack the routing of other instances.
 * - Templates without a database get `FLEET_HAS_DB=0` and a poisoned database host (`.invalid` never resolves, RFC 6761),
 *   so a tool never falls back to the generic `db` alias that another project's database also carries (FLE-297).
 * - Templates for AI agents get `FLEET_AGENT=1` (the UI and the docs carry the warning).
 */
final readonly class InstanceIsolation
{
    public const string OVERRIDE_FILE = '.ddev/docker-compose.fleet-isolation.yaml';

    public const string DASHBOARD_SERVICE = 'typesense-dashboard';

    public const string DASHBOARD_PROFILE = 'dashboard';

    public const string MAILPIT_USER = 'fleet';

    /** A host name that never resolves; the value of the database host variables of a template without a database. */
    public const string POISONED_DB_HOST = 'fleet-no-database.invalid';

    /** The variables a database-less instance gets the poisoned host in. */
    public const array DB_HOST_VARIABLES = ['DB_HOST', 'DATABASE_HOST', 'MYSQL_HOST'];

    public function __construct(
        private TemplateRepository $templates,
        private SecretRegistry $registry,
        private CommandRunner $runner,
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
     * Writes the compose override, or removes a stale one when nothing applies.
     *
     * - The read-only `traefik/` mount only when the host can do it (a `volume.subpath` mount needs Docker Engine 26 and
     *   Compose 2.23) AND `traefik/` already exists in the volume (a missing subpath makes `web` fail to start, so the first
     *   deploy on a fresh host skips it with a warning and the next deploy applies it). Never fatal.
     * - The Typesense dashboard of a Typesense project is moved into the `dashboard` compose profile, so it does not start,
     *   unless the project switched it on. Only when the project's own compose file declares that service.
     *
     * @param \Closure(string): void $warn receives a line for the job log
     *
     * @return string|null the path written, relative to $dir; null when no override is needed
     */
    public function write(string $dir, Project $project, \Closure $warn): ?string
    {
        $services = [];

        $skip = $this->traefikMountUnavailable();
        if (null === $skip) {
            $services['web'] = ['volumes' => [[
                'type' => 'volume',
                'source' => 'ddev-global-cache',
                'target' => '/mnt/ddev-global-cache/traefik',
                'read_only' => true,
                'volume' => ['subpath' => 'traefik'],
            ]]];
        } else {
            $warn('WARNING: isolation: the router configuration is not mounted read-only in this instance ('.$skip.').');
        }

        if ($project->typesense() && !$project->typesenseDashboard() && self::declaresDashboard($dir)) {
            $services[self::DASHBOARD_SERVICE] = ['profiles' => [self::DASHBOARD_PROFILE]];
        }

        $path = $dir.'/'.self::OVERRIDE_FILE;
        if ([] === $services) {
            if (is_file($path)) {
                @unlink($path);
            }

            return null;
        }
        AtomicFile::write($path, Yaml::dump(['services' => $services], 6, 2), 0o640);

        return self::OVERRIDE_FILE;
    }

    /**
     * @return string|null why the read-only mount cannot be used (null: it can)
     */
    public function traefikMountUnavailable(): ?string
    {
        $docker = $this->output(['docker', 'version', '--format', '{{.Server.Version}}']);
        if (null === $docker || !self::atLeast($docker, '26.0')) {
            return 'Docker Engine 26 or newer is needed for a subpath mount, found '.($docker ?? 'none');
        }
        $compose = $this->output(['docker', 'compose', 'version', '--short']);
        if (null === $compose || !self::atLeast($compose, '2.23')) {
            return 'Docker Compose 2.23 or newer is needed for a subpath mount, found '.($compose ?? 'none');
        }
        $image = $this->output(['docker', 'inspect', '--format', '{{.Config.Image}}', 'ddev-router']);
        if (null === $image) {
            return 'the ddev router has not started yet, so traefik/ is not in the cache volume; deploy again once it runs';
        }
        $probe = $this->runner->run(['docker', 'run', '--rm', '--pull=never', '--network=none', '-v', 'ddev-global-cache:/c:ro', '--entrypoint', 'test', $image, '-d', '/c/traefik'], null, [], null, 60.0);
        if (!$probe->isSuccess()) {
            return 'traefik/ does not exist in the ddev-global-cache volume yet; deploy again once the router has written it';
        }

        return null;
    }

    private static function atLeast(string $version, string $minimum): bool
    {
        return 1 === preg_match('/^v?\d+(\.\d+)*/', $version, $m) && version_compare($m[0], $minimum, '>=');
    }

    private static function declaresDashboard(string $dir): bool
    {
        foreach (glob($dir.'/.ddev/docker-compose.*.y*ml') ?: [] as $file) {
            if (str_ends_with($file, self::OVERRIDE_FILE) || !is_file($file)) {
                continue;
            }
            $compose = @file_get_contents($file);
            if (false !== $compose && 1 === preg_match('/^\s{1,4}'.preg_quote(self::DASHBOARD_SERVICE, '/').'\s*:/m', $compose)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $argv
     */
    private function output(array $argv): ?string
    {
        try {
            $result = $this->runner->run($argv, null, [], null, 20.0);
        } catch (CommandRunnerException|CommandTimedOut) {
            return null;
        }
        $out = trim($result->stdout);

        return $result->isSuccess() && '' !== $out ? $out : null;
    }
}
