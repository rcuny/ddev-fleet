<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Ssh\AtomicFile;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes `.ddev/config.fleet.yaml`, the one file fleet adds to ddev's configuration of an instance: its name, domain,
 * environment and extra host names. It never touches the project's own `config.yaml`. The file may hold the Claude
 * token, so it is not world readable, and it is dumped through the YAML component so no value can break out of it.
 */
final readonly class InstanceConfigWriter
{
    public const string RELATIVE_PATH = '.ddev/config.fleet.yaml';

    public function __construct(
        private InstanceHostnames $hostnames,
        private GitIdentityResolver $identities,
        private SecretStore $secrets,
    ) {
    }

    /**
     * @return string the path written, relative to $dir
     */
    public function write(Instance $instance, Project $project, string $dir): string
    {
        $primary = $this->hostnames->primary($instance->id());
        $environment = [
            'DRUSH_OPTIONS_URI=https://'.$primary,
            'FLEET_INSTANCE_HOST='.$primary,
        ];
        $token = $this->secrets->getFleetWide(HostSecrets::CLAUDE_TOKEN);
        if (null !== $token && '' !== $token) {
            $environment[] = 'CLAUDE_CODE_OAUTH_TOKEN='.$token;
        }
        $identity = $this->identities->forProject($project);
        if (null !== $identity) {
            $environment[] = 'GIT_AUTHOR_NAME='.$identity['name'];
            $environment[] = 'GIT_AUTHOR_EMAIL='.$identity['email'];
            $environment[] = 'GIT_COMMITTER_NAME='.$identity['name'];
            $environment[] = 'GIT_COMMITTER_EMAIL='.$identity['email'];
        }

        $config = [
            'name' => $instance->id(),
            'project_tld' => $this->hostnames->domain(),
            'performance_mode' => 'none',
            'web_environment' => $environment,
        ];
        $aliases = $this->hostnames->aliases($project, $instance->id());
        if ([] !== $aliases) {
            $config['additional_fqdns'] = $aliases;
        }

        $ddevDir = $dir.'/.ddev';
        if (!is_dir($ddevDir) && !@mkdir($ddevDir, 0o750, true) && !is_dir($ddevDir)) {
            throw new \RuntimeException('Cannot create the .ddev directory.');
        }
        AtomicFile::write($dir.'/'.self::RELATIVE_PATH, Yaml::dump($config, 4, 2), 0o640);

        return self::RELATIVE_PATH;
    }
}
