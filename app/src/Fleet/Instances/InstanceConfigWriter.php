<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Port\NamedPort;
use App\Domain\Project\Project;
use App\Fleet\Git\GitClient;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Jobs\SecretRegistry;
use App\Fleet\Projects\Rule\GitIdentity;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\Typesense\TypesenseEnvironment;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes `.ddev/config.fleet.yaml`, the one file fleet adds to ddev's configuration of an instance: its name, domain,
 * environment (the Typesense host, port and keys of a Typesense project, and `FLEET_PORT_<NAME>` for each named port) and extra host names. It never touches the project's own `config.yaml`. The file may hold the Claude
 * token and the Typesense keys (registered with the job log masker), so it is not world readable, and it is dumped through the YAML component so no value can break out of it.
 */
final readonly class InstanceConfigWriter
{
    public const string RELATIVE_PATH = '.ddev/config.fleet.yaml';

    /**
     * Seeds `~/.claude.json` past Claude Code's first-run onboarding (theme picker, trust dialog), as 0.x did.
     * `CLAUDE_CODE_OAUTH_TOKEN` authenticates but does not mark onboarding complete. It MERGES (Claude writes runtime
     * keys to that file) and runs as a `post-start` hook, not baked into the image: DDEV creates the container user at
     * runtime and re-copies homeadditions on every start.
     *
     * ORDERING: DDEV merges `hooks` from config.yaml and every config.*.yaml additively, in filename order, so this
     * never clobbers a project's own hooks. `config.fleet.yaml` sorts after `config.claude-code.yaml`, which may relink
     * `~/.claude.json` into the project tree; the seed must run after that relink (writing through the symlink is
     * intended). No single quotes in the JS: it is wrapped in a single-quoted `node -e '...'`.
     */
    public const string CLAUDE_ONBOARDING_SEED = <<<'JS'
        const fs = require("fs");
        const path = (process.env.HOME || "/home/fleet") + "/.claude.json";
        let data = {};
        try { data = JSON.parse(fs.readFileSync(path, "utf8")); } catch (e) {}
        data.hasCompletedOnboarding = true;
        if (data.theme === undefined) { data.theme = "dark"; }
        data.projects = data.projects || {};
        const root = process.env.DDEV_APPROOT || process.cwd();
        const proj = data.projects[root] || {};
        proj.hasTrustDialogAccepted = true;
        data.projects[root] = proj;
        fs.writeFileSync(path, JSON.stringify(data, null, 2));
        JS;

    public function __construct(
        private InstanceHostnames $hostnames,
        private GitIdentityResolver $identities,
        private SecretStore $secrets,
        private SecretRegistry $registry,
        private TypesenseEnvironment $typesense,
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
            // git over SSH inside the container: pinned known_hosts from the checkout, keys from DDEV's ssh-agent (FLE-145).
            'GIT_SSH_COMMAND='.GitClient::CONTAINER_SSH_COMMAND,
        ];
        $token = $this->secrets->getFleetWide(HostSecrets::CLAUDE_TOKEN);
        if (null !== $token && '' !== $token) {
            $this->registry->register($token);
            $environment[] = 'CLAUDE_CODE_OAUTH_TOKEN='.$token;
        }
        array_push($environment, ...$this->typesense->variables($instance, $project));
        array_push($environment, ...self::portVariables($project));
        $identity = $this->identities->forProject($project);
        if (null !== $identity) {
            // A value stored before the input rule existed must not reach DDEV's compose file (FLE-189).
            if (GitIdentity::hasComposeUnsafe($identity['name']) || GitIdentity::hasComposeUnsafe($identity['email'])) {
                throw new \RuntimeException('The git identity contains a double quote, "$" or backslash, which DDEV cannot carry in web_environment. Fix the name or e-mail in the project or host settings.');
            }
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
            // Unconditional, as in 0.x: Claude Code is baked into every instance's web image.
            'hooks' => ['post-start' => [['exec' => "node -e '\n".self::CLAUDE_ONBOARDING_SEED."\n'\n"]]],
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

    /**
     * `FLEET_PORT_<NAME>=<public port>` for every named port the project uses, as in 0.x (`playwright` gives
     * `FLEET_PORT_PLAYWRIGHT`, `ts-dashboard` gives `FLEET_PORT_TS_DASHBOARD`), ordered by public port.
     *
     * @return list<string>
     */
    private static function portVariables(Project $project): array
    {
        $ports = $project->ports();
        usort($ports, static fn (NamedPort $a, NamedPort $b): int => $a->publicPort() <=> $b->publicPort());

        return array_map(
            static fn (NamedPort $port): string => 'FLEET_PORT_'.strtoupper(str_replace('-', '_', $port->name())).'='.$port->publicPort(),
            $ports,
        );
    }
}
