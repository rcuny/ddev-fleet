<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Instances\TokenRenderer;
use App\Fleet\Shell\CommandRunnerException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Gives a deployed instance its tmux window and types the template's tty1 and tty2 commands into it (the v0 behaviour).
 * Runs once ddev is up. A redeploy starts from a fresh window. This step never fails a deploy: a tmux problem, or a
 * command whose `[[token]]` cannot be resolved, is a WARNING line in the job log and the pane stays a plain shell.
 *
 * The commands carry context tokens only (ContextTokens), never a secret: the Claude token reaches the container as an
 * environment variable (InstanceConfigWriter), so `ddev exec claude ...` has no use for it on a command line.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 100])]
final readonly class TmuxWindowStep implements DeployStep
{
    /** A token value is typed into a shell: only these characters are accepted in it. */
    private const string SAFE_VALUE = '/^[A-Za-z0-9][A-Za-z0-9._\/@:+-]*$/D';

    public function __construct(private Tmux $tmux, private TemplateRepository $templates)
    {
    }

    public function phase(): StepPhase
    {
        return StepPhase::AfterStart;
    }

    public function run(DeployContext $context): void
    {
        $id = $context->instance->id();
        $template = $this->templates->find($context->project, $context->instance->templateName());
        if (null === $template) {
            $context->log->line('WARNING: the template was not found, so no tmux window was prepared.');

            return;
        }

        try {
            if (!$this->tmux->ensureWindow($id, $context->dir)) {
                $this->tmux->reset($id);
            }
            $typed = 0;
            foreach ([[Pane::Tty1, $template->tty1()], [Pane::Tty2, $template->tty2()]] as [$pane, $commands]) {
                foreach ($commands as $command) {
                    $line = $this->prepare($command, $pane, $context);
                    if (null === $line) {
                        continue;
                    }
                    $this->tmux->sendKeys($id, $pane, $line);
                    ++$typed;
                }
            }
            $context->log->line(\sprintf('tmux window ready (%d command%s typed)', $typed, 1 === $typed ? '' : 's'));
        } catch (WorkspaceException|CommandRunnerException $e) {
            $context->log->line('WARNING: the tmux window could not be prepared: '.$e->getMessage());
        }
    }

    /**
     * The command with its tokens substituted, or null (logged) when it must be skipped. Empty lines are ignored silently.
     */
    private function prepare(string $command, Pane $pane, DeployContext $context): ?string
    {
        if ('' === trim($command)) {
            return null;
        }
        $label = strtolower($pane->name);

        try {
            $line = TokenRenderer::render($command, $context->tokens);
        } catch (\InvalidArgumentException $e) {
            $context->log->line(\sprintf('WARNING: skipped a %s command: %s', $label, $e->getMessage()));

            return null;
        }
        preg_match_all('/\[\[([a-z0-9-]+)\]\]/', $command, $names);
        foreach (array_unique($names[1]) as $name) {
            if (1 !== preg_match(self::SAFE_VALUE, $context->tokens[$name] ?? '')) {
                $context->log->line(\sprintf('WARNING: skipped a %s command: the value of [[%s]] is not safe to type into a shell', $label, $name));

                return null;
            }
        }

        return $line;
    }
}
