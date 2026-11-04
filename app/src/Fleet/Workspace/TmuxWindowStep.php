<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Template\TemplateRepository;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Secrets\TokenRenderer;
use App\Fleet\Shell\CommandRunnerException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Gives a deployed instance its tmux window and types the template's tty1 and tty2 commands into it (the v0 behaviour).
 * Runs after the last post-deploy line succeeded and `deploy complete` is logged (FLE-171: typing earlier raced the
 * site install), so a failed post-deploy types nothing. A redeploy keeps the window and any pane that is busy (FLE-175). This step never fails a deploy: a tmux problem, or a
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
        return StepPhase::AfterDeploy;
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
            // An existing window (a redeploy) is only healed, never reset: a pane running a command (a CI suite in tty1,
            // Claude in tty2) must survive. Idle panes get the commands typed, as on a first deploy.
            $this->tmux->ensureWindow($id, $context->dir);
            $typed = 0;
            $panes = [];
            foreach ([[Pane::Tty1, $template->tty1()], [Pane::Tty2, $template->tty2()]] as [$pane, $commands]) {
                if ([] !== array_filter($commands, static fn (string $c): bool => '' !== trim($c)) && $this->tmux->isBusy($id, $pane)) {
                    $context->log->line(\sprintf('%s busy, not restarted', strtolower($pane->name)));

                    continue;
                }
                foreach ($commands as $command) {
                    $line = $this->prepare($command, $pane, $context);
                    if (null === $line) {
                        continue;
                    }
                    $this->tmux->sendKeys($id, $pane, $line);
                    ++$typed;
                    $panes[$pane->name] = strtolower($pane->name);
                }
            }
            $context->log->line(\sprintf('tmux window ready (%d command%s typed)', $typed, 1 === $typed ? '' : 's'));
            if ([] !== $panes) {
                $context->log->line(\sprintf('tty commands typed (%s)', implode(', ', array_values($panes))));
            }
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

        // Only the built-in context tokens are typed: a secret would stay readable in the pane's scrollback, so a command
        // that names anything else is skipped. Nothing is looked up here, so no secret is ever opened for a tty command.
        preg_match_all(TokenRenderer::PATTERN, $command, $names);
        $others = array_values(array_filter(array_unique($names[1]), static fn (string $name): bool => !isset($context->tokens[$name])));
        if ([] !== $others) {
            sort($others);
            $context->log->line(\sprintf(
                'WARNING: skipped a %s command: unresolved token(s): %s (tty commands take only the built-in tokens; a secret is never typed, the pane keeps it in its scrollback)',
                $label,
                implode(', ', array_map(static fn (string $name): string => '[['.$name.']]', $others)),
            ));

            return null;
        }
        foreach (array_unique($names[1]) as $name) {
            if (1 !== preg_match(self::SAFE_VALUE, $context->tokens[$name])) {
                $context->log->line(\sprintf('WARNING: skipped a %s command: the value of [[%s]] is not safe to type into a shell', $label, $name));

                return null;
            }
        }

        return $context->render($command)->text;
    }
}
