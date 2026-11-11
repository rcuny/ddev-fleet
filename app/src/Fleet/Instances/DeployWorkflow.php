<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Template\SshIdentities;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Isolation\InstanceIsolation;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\TokenRenderer;
use App\Fleet\Secrets\UnresolvedTokens;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\Ssh\InstanceSshAgent;
use App\Fleet\Ssh\PushKeyCheck;
use App\Fleet\Ssh\PushKeyState;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds an instance: clone, the files fleet adds to the checkout, the Caddy site, `ddev start`, the template's
 * post-deploy lines. A redeploy removes the checkout first and runs the same steps. Everything that can be checked
 * without touching the host is checked before anything is destroyed or cloned; any failure leaves the instance
 * `failed` with the log kept.
 *
 * `$force` is accepted for the request side (`--force`); a redeploy always discards the old checkout.
 */
final class DeployWorkflow
{
    /**
     * @param iterable<DeployStep> $steps
     */
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly TemplateRepository $templates,
        private readonly GitClient $git,
        private readonly Ddev $ddev,
        private readonly InstanceConfigWriter $config,
        private readonly DrupalSettingsWriter $drupal,
        private readonly ContextTokens $contextTokens,
        private readonly TokenRenderer $secrets,
        private readonly InstanceHostnames $hostnames,
        private readonly InstanceSiteWriter $sites,
        private readonly Teardown $teardown,
        private readonly FleetPaths $paths,
        private readonly InstanceSshAgent $agent,
        private readonly JobFeed $feed,
        private readonly ClockInterface $clock,
        private readonly InstanceIsolation $isolation,
        #[AutowireIterator('fleet.deploy_step')]
        private readonly iterable $steps = [],
        private readonly ?InstanceCgroupWriter $cgroup = null,
        private readonly ?PushKeyCheck $pushCheck = null,
    ) {
    }

    /**
     * @param bool        $replace        true for a redeploy: the existing checkout is removed first
     * @param string|null $template       another template for a redeploy; the instance keeps its own when null
     * @param string|null $requestedLabel the label the request asked for, to explain a different one in the log
     *
     * @throws JobFailed
     */
    public function run(Instance $instance, Project $project, bool $replace, JobLog $log, bool $force, ?string $template = null, ?string $requestedLabel = null): void
    {
        unset($force);

        try {
            $this->deploy($instance, $project, $replace, $log, $template, $requestedLabel);
        } catch (\Throwable $e) {
            $instance->changeState(InstanceState::Failed, $this->clock->now());
            $this->instances->save($instance);
            $this->feed->instanceChanged($instance->id());

            throw $e;
        }
    }

    private function deploy(Instance $instance, Project $project, bool $replace, JobLog $log, ?string $templateName, ?string $requestedLabel): void
    {
        $id = $instance->id();
        $dir = $this->paths->instanceDir($id);
        $name = $templateName ?? $instance->templateName();

        // 1. Preflight: nothing is destroyed or cloned until all of this holds.
        $template = $this->templates->find($project, $name)
            ?? throw new JobFailed(\sprintf('The template "%s" no longer exists; nothing was changed.', $name));
        try {
            $this->hostnames->aliases($project, $id);
        } catch (ValidationFailed $e) {
            throw new JobFailed(implode(' ', $e->errors).' Nothing was changed.');
        }
        try {
            $tokens = $this->contextTokens->for($instance, $project);
        } catch (Refused $e) {
            throw new JobFailed($e->getMessage().' Nothing was changed.');
        }
        $postDeploy = $this->preparePostDeploy($template, $project, $tokens);
        if ($replace) {
            try {
                $this->sites->validate();
            } catch (\RuntimeException $e) {
                throw new JobFailed('Caddy does not accept its current configuration, fix Caddy first; nothing was changed. '.$e->getMessage());
            }
        }
        if ($name !== $instance->templateName()) {
            $instance->changeTemplate($name, $this->clock->now());
            $this->instances->save($instance);
        }

        // 2. Redeploy removes the old checkout; a directory fleet has no checkout for is a leftover of a partial deploy.
        if ($replace) {
            $log->line('redeploy: removing the existing checkout');
            $this->teardown->removeCheckout($instance, $log);
        } elseif (file_exists($dir.'/.git')) {
            throw new JobFailed('A checkout exists that fleet has no record of; destroy it by hand.');
        }
        if (file_exists($dir) || is_link($dir)) {
            $log->line('recovered from a partial instance directory');
            $this->teardown->removeTree($dir, $log);
        }

        // 3. Clone.
        $log->line(\sprintf('deploy start: project=%s template=%s label=%s branch=%s', $project->name(), $name, $instance->label(), $instance->branch()));
        if (null !== $requestedLabel && $requestedLabel !== $instance->label()) {
            $log->line(\sprintf("label '%s' already in use - allocated '%s' instead (deploy never overwrites an existing instance)", $requestedLabel, $instance->label()));
        }
        try {
            $cloned = $this->git->clone($project->git(), $instance->branch(), $dir, $log->output(...));
        } catch (\InvalidArgumentException) {
            throw new JobFailed('The repository URL or the branch is not valid; nothing was cloned.');
        } catch (CommandRunnerException $e) {
            throw new JobFailed('git clone did not finish: '.$e->getMessage());
        }
        $this->requireSuccess('git clone', $cloned);
        try {
            $this->requireSuccess('git ssh setup', $this->git->configureSsh($dir));
        } catch (CommandRunnerException $e) {
            throw new JobFailed('git ssh setup did not finish: '.$e->getMessage());
        }
        try {
            $sshKnownHosts = $this->git->writeContainerKnownHosts($dir);
        } catch (\RuntimeException $e) {
            throw new JobFailed($e->getMessage());
        }

        // 4. What fleet adds to the checkout, kept out of the project's git status.
        $written = [$this->config->write($instance, $project, $dir)];
        array_push($written, ...$this->drupal->write($dir, $this->hostnames->domain()));
        if (null !== $template->drupalEnv()) {
            $written[] = '.env'; // written after the BeforeStart steps, see below
        }
        $written[] = $sshKnownHosts;
        $written[] = InstanceCgroupWriter::RELATIVE_PATH;
        array_push($written, ...$this->setUpContainerAgent($instance, $project, $template, $dir, $log));
        GitExclude::add($dir, $written);

        $context = new DeployContext($instance, $project, $dir, $tokens, $log, $this->secrets);

        // 5-8. Steps, the site (never live without the gate), ddev, steps.
        $this->runSteps(StepPhase::BeforeStart, $context);
        // After the steps (FLE-192): the assets seed copies the project's own `.env` over the checkout's, which would
        // otherwise replace the template's DRUPAL_ENV with the asset's value. The template setting wins.
        if (null !== $template->drupalEnv()) {
            EnvFile::setVar($dir.'/.env', 'DRUPAL_ENV', $template->drupalEnv());
        }
        try {
            $this->sites->write($instance, $project);
        } catch (\RuntimeException $e) {
            throw new JobFailed('The Caddy site could not be installed: '.$e->getMessage());
        }
        // After the add-on files are in place (the seed), before the start: every service goes under the template's slice.
        if (null !== $this->cgroup) {
            try {
                $this->cgroup->write($instance, $project, $dir, $log);
            } catch (\RuntimeException $e) {
                throw new JobFailed('The CPU slice override could not be written: '.$e->getMessage());
            }
        }
        // The last file is in place (project's own, assets, fleet's): nothing starts that hands a container the host (FLE-298).
        $this->isolation->enforce($instance, $dir, $log);
        $started = $this->ran(fn (): CommandResult => $this->ddev->start($dir, $log->output(...)), 'ddev start');
        if (!$started->isSuccess()) {
            throw new JobFailed(\sprintf('ddev start failed with exit code %d', $started->exitCode), $started->exitCode);
        }
        $warning = $this->cgroup?->check($instance, $project);
        if (null !== $warning) {
            $log->line($warning);
        }
        $this->runSteps(StepPhase::AfterStart, $context);

        // 9. Post-deploy lines, already split and substituted.
        $env = $this->contextTokens->env($tokens);
        foreach ($postDeploy as ['line' => $line, 'argv' => $argv]) {
            $log->line('post_deploy: '.$line);
            $result = $this->ran(fn (): CommandResult => $this->ddev->run($dir, $argv, $env, $log->output(...)), 'post_deploy command');
            if (!$result->isSuccess()) {
                $log->line(\sprintf("tty commands not started: post-deploy failed at '%s'", $line));
                throw new JobFailed(\sprintf("post_deploy command '%s' failed with exit code %d", $line, $result->exitCode), $result->exitCode);
            }
        }

        if ([] !== $context->excluded()) {
            GitExclude::add($dir, $context->excluded());
        }

        // 10.
        $instance->markDeployed($this->clock->now());
        $instance->changeState(InstanceState::Running, $this->clock->now());
        $head = $this->git->head($dir);
        $instance->recordHead('' === $head ? null : $head);
        $this->instances->save($instance);
        $log->line('deploy complete');
        $this->feed->instanceChanged($id);

        // 11. The tmux window's tty commands, only now that the site is installed (FLE-171).
        $this->runSteps(StepPhase::AfterDeploy, $context);
    }

    /**
     * Splits every line into argv and substitutes the tokens inside each word, so a value can never add shell syntax or
     * split a word. All lines are done before the first one runs, in the preflight and so before the first log line that
     * could hold a value: every secret inserted is registered with the masker by then. Context tokens win over secrets,
     * a project secret over a fleet-wide one. An unresolved token fails the deploy before anything is changed.
     *
     * @param array<string, string> $tokens
     *
     * @return list<array{line: string, argv: list<string>}>
     */
    private function preparePostDeploy(Template $template, Project $project, array $tokens): array
    {
        $prepared = [];
        foreach ($template->postDeploy() as $number => $line) {
            try {
                $argv = array_map(fn (string $word): string => $this->secrets->renderStrict($word, $project->name(), $tokens), CommandLineSplitter::split($line));
            } catch (UnresolvedTokens $e) {
                throw new JobFailed(\sprintf('post_deploy line %d cannot run: unresolved token(s): %s. Nothing was changed.', $number + 1, implode(', ', array_map(static fn (string $t): string => '[['.$t.']]', $e->tokens))));
            } catch (SecretOpenFailed) {
                throw new JobFailed(\sprintf('post_deploy line %d cannot run: a secret it uses could not be opened. Nothing was changed.', $number + 1));
            } catch (\InvalidArgumentException $e) {
                throw new JobFailed(\sprintf('post_deploy line %d cannot run: %s. Nothing was changed.', $number + 1, $e->getMessage()));
            }
            $prepared[] = ['line' => $line, 'argv' => $argv];
        }

        return $prepared;
    }

    private function runSteps(StepPhase $phase, DeployContext $context): void
    {
        foreach ($this->steps as $step) {
            if ($step->phase() === $phase) {
                $step->run($context);
            }
        }
    }

    /**
     * @param \Closure(): CommandResult $call
     */
    private function ran(\Closure $call, string $what): CommandResult
    {
        try {
            return $call();
        } catch (CommandRunnerException $e) {
            throw new JobFailed(\sprintf('%s did not finish: %s', $what, $e->getMessage()));
        }
    }

    private function requireSuccess(string $what, CommandResult $result): void
    {
        if (!$result->isSuccess()) {
            throw new JobFailed(\sprintf('%s failed with exit code %d', $what, $result->exitCode), $result->exitCode);
        }
    }

    /**
     * FLE-271: an unregistered push key only shows up when the agent first pushes; say so in the deploy log now. A warning, not a
     * failure (the instance may only read), and an unreadable remote or an unreachable host never blocks the deploy.
     */
    private function warnIfPushKeyNotRegistered(Project $project, JobLog $log): void
    {
        if (null === $this->pushCheck) {
            return;
        }
        try {
            $host = PushKeyCheck::hostFromRemote($project->git());
            $state = $this->pushCheck->run($host);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (PushKeyState::NotRegistered === $state || PushKeyState::HostNotTrusted === $state) {
            $log->line('warning: '.$state->message($host));
        }
    }

    /**
     * Ruling (FLE-145): only a project that opted in (`containerSsh`) gets an agent, and only that instance's container
     * mounts its socket; nothing is ever loaded into DDEV's host-wide agent. An agent that cannot be started fails the
     * deploy (an agent instance that cannot push would only find out after its work); a host without a push key only logs.
     *
     * @return list<string> files added to the checkout, relative to $dir
     */
    private function setUpContainerAgent(Instance $instance, Project $project, Template $template, string $dir, JobLog $log): array
    {
        if (!$project->containerSsh()) {
            // Opted out (FLE-167): a redeploy drops what an earlier opted-in deploy left behind.
            if ($this->agent->cleanUp($instance->id(), $dir)) {
                $log->line('removed the ssh-agent and its compose override: this project no longer uses container_ssh');
            }

            return [];
        }
        if (!$this->agent->hasKeyFor($instance)) {
            $log->line('warning: the project asks for git over SSH in the container, but the host has no push key; no ssh-agent was started');

            return [];
        }
        $this->warnIfPushKeyNotRegistered($project, $log);
        try {
            $this->agent->startFor($instance);
            AtomicFile::write($dir.'/'.InstanceSshAgent::OVERRIDE_FILE, $this->agent->composeOverride($instance->id()), 0o640);
        } catch (\RuntimeException $e) {
            throw new JobFailed('ssh-agent setup failed: '.$e->getMessage());
        }
        $extra = $template->sshKeys();
        $held = SshIdentities::ExtrasOnly === $template->sshIdentities()
            ? 'the template\'s extra key(s) only, no push key: '.implode(', ', $extra)
            : 'push key'.([] === $extra ? '' : ' and the template\'s extra key(s): '.implode(', ', $extra));
        $log->line('started a dedicated ssh-agent for this instance ('.$held.', '.InstanceSshAgent::KEY_LIFETIME.' s)');

        return [InstanceSshAgent::OVERRIDE_FILE];
    }
}
