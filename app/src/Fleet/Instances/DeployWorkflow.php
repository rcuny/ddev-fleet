<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunnerException;
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
        private readonly InstanceHostnames $hostnames,
        private readonly InstanceSiteWriter $sites,
        private readonly Teardown $teardown,
        private readonly FleetPaths $paths,
        private readonly JobFeed $feed,
        private readonly ClockInterface $clock,
        #[AutowireIterator('fleet.deploy_step')]
        private readonly iterable $steps = [],
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
        $postDeploy = $this->preparePostDeploy($template, $tokens);
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

        // 4. What fleet adds to the checkout, kept out of the project's git status.
        $written = [$this->config->write($instance, $project, $dir)];
        array_push($written, ...$this->drupal->write($dir, $this->hostnames->domain()));
        if (null !== $template->drupalEnv()) {
            EnvFile::setVar($dir.'/.env', 'DRUPAL_ENV', $template->drupalEnv());
            $written[] = '.env';
        }
        GitExclude::add($dir, $written);

        $context = new DeployContext($instance, $project, $dir, $tokens, $log);

        // 5-8. Steps, the site (never live without the gate), ddev, steps.
        $this->runSteps(StepPhase::BeforeStart, $context);
        try {
            $this->sites->write($instance, $project);
        } catch (\RuntimeException $e) {
            throw new JobFailed('The Caddy site could not be installed: '.$e->getMessage());
        }
        $started = $this->ran(fn (): CommandResult => $this->ddev->start($dir, $log->output(...)), 'ddev start');
        if (!$started->isSuccess()) {
            throw new JobFailed(\sprintf('ddev start failed with exit code %d', $started->exitCode), $started->exitCode);
        }
        $this->runSteps(StepPhase::AfterStart, $context);

        // 9. Post-deploy lines, already split and substituted.
        $env = $this->contextTokens->env($tokens);
        foreach ($postDeploy as ['line' => $line, 'argv' => $argv]) {
            $log->line('post_deploy: '.$line);
            $result = $this->ran(fn (): CommandResult => $this->ddev->run($dir, $argv, $env, $log->output(...)), 'post_deploy command');
            if (!$result->isSuccess()) {
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
    }

    /**
     * Splits every line into argv and substitutes the tokens inside each word, so a value can never add shell syntax or
     * split a word. All lines are done before the first one runs.
     *
     * @param array<string, string> $tokens
     *
     * @return list<array{line: string, argv: list<string>}>
     */
    private function preparePostDeploy(Template $template, array $tokens): array
    {
        $prepared = [];
        foreach ($template->postDeploy() as $number => $line) {
            try {
                $argv = array_map(static fn (string $word): string => TokenRenderer::render($word, $tokens), CommandLineSplitter::split($line));
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
}
