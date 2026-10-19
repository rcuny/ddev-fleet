<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Workspace;

use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobStatus;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\TmuxWindowStep;
use App\Tests\Support\FakeTmuxRunner;
use App\Tests\Support\InstanceJobTestCase;

final class TmuxWindowStepTest extends InstanceJobTestCase
{
    private FakeTmuxRunner $tmuxRunner;
    private TmuxWindowStep $step;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmuxRunner = new FakeTmuxRunner();
        $tmux = new Tmux($this->tmuxRunner, $this->paths, '/opt/ddev-fleet/app/bin/console');
        $this->steps[] = $this->step = new TmuxWindowStep($tmux, $this->templates);
        // The destroy side talks to the same tmux.
        $this->teardown = new \App\Fleet\Instances\Teardown($this->paths, $this->ddev, $this->sites, $tmux);
    }

    /**
     * @param list<string> $tty1
     * @param list<string> $tty2
     */
    private function template(array $tty1, array $tty2): void
    {
        $template = $this->saveTemplate('full', []);
        $template->changeCommands([], $tty1, $tty2);
        $this->templates->save($template);
    }

    private function deploy(string $label = 'main', string $branch = 'develop'): \App\Domain\Job\Job
    {
        $instance = $this->instance($label, $branch);
        $job = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);

        return $job;
    }

    private function deployed(\App\Domain\Instance\Instance $instance): void
    {
        $first = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $this->logFor($first), false);
        $first->finish(JobStatus::Succeeded, 0, null, $this->clock->now());
        $this->em->flush();
    }

    public function testAJiraWorkTemplateTypesTheClaudeLineIntoTty2Once(): void
    {
        $this->project->changeIssueIdRegexp('FLE-[0-9]+');
        $this->template([], ['ddev exec claude --model sonnet "/jira work [[issue-id]]"']);

        $job = $this->deploy('fle-9', 'feature/FLE-9-x');

        self::assertSame(['ddev exec claude --model sonnet "/jira work FLE-9"'], $this->tmuxRunner->typedIn('tty2'));
        self::assertSame([], $this->tmuxRunner->typedIn('tty1'));
        self::assertSame(['acme--fle-9'], $this->tmuxRunner->windows);
        self::assertStringContainsString('tmux window ready (1 command typed)', $this->logText($job));
    }

    public function testSeveralCommandsOfOnePaneAreTypedInOrder(): void
    {
        $this->template(['ddev logs -f', 'ddev describe'], ['echo one', 'echo two', 'echo three']);

        $this->deploy('main');

        self::assertSame(['ddev logs -f', 'ddev describe'], $this->tmuxRunner->typedIn('tty1'));
        self::assertSame(['echo one', 'echo two', 'echo three'], $this->tmuxRunner->typedIn('tty2'));
    }

    public function testAnUnresolvedTokenSkipsOnlyThatCommand(): void
    {
        $this->template([], ['echo [[issue-id]]', 'echo [[branch]]']);

        $job = $this->deploy('main');

        self::assertSame(['echo develop'], $this->tmuxRunner->typedIn('tty2'));
        self::assertStringContainsString('WARNING: skipped a tty2 command: unresolved token(s): [[issue-id]]', $this->logText($job));
        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state(), 'a skipped command never fails the deploy');
    }

    public function testASecretTokenIsNeverTypedNorOpened(): void
    {
        $this->secretStore->putManaged('acme', 'MY_SECRET', 'tty-s3cret-value', 'test');
        $this->template([], ['echo [[my-secret]]', 'echo [[branch]]']);

        $job = $this->deploy('main');

        self::assertSame(['echo develop'], $this->tmuxRunner->typedIn('tty2'));
        $log = $this->logText($job);
        self::assertStringContainsString('WARNING: skipped a tty2 command: unresolved token(s): [[my-secret]]', $log);
        self::assertStringContainsString('a secret is never typed', $log);
        self::assertSame('tty-s3cret-value', $this->masker->mask('tty-s3cret-value'), 'the secret was never opened, so never registered');
    }

    public function testATokenValueThatIsNotSafeForAShellIsNotTyped(): void
    {
        $this->template(['echo [[branch]]', 'echo [[project]]'], []);
        $instance = $this->instance('main', 'develop');
        $job = $this->job('deploy', $instance);

        $this->step->run(new DeployContext($instance, $this->project, $this->paths->instanceDir($instance->id()), ['branch' => 'x;touch /tmp/pwned', 'project' => 'acme'], $this->logFor($job), $this->secretRenderer));

        self::assertSame(['echo acme'], $this->tmuxRunner->typedIn('tty1'));
        self::assertStringContainsString('the value of [[branch]] is not safe to type into a shell', $this->logText($job));
    }

    public function testATmuxFailureIsAWarningNotAFailedDeploy(): void
    {
        $this->template([], ['echo hi']);
        $this->tmuxRunner->failing = 'new-window';

        $job = $this->deploy('main');

        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
        self::assertStringContainsString('WARNING: the tmux window could not be prepared', $this->logText($job));
        self::assertStringContainsString('deploy complete', $this->logText($job));
    }

    public function testMissingTmuxIsAWarningToo(): void
    {
        $this->template([], ['echo hi']);
        $this->tmuxRunner->missing = true;

        $job = $this->deploy('main');

        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
        self::assertStringContainsString('WARNING: the tmux window could not be prepared: tmux is not installed', $this->logText($job));
    }

    public function testARedeployStartsFromAFreshWindow(): void
    {
        $this->template([], ['echo hi']);
        $instance = $this->instance('main', 'develop');
        $this->deployed($instance);

        $job = $this->job('redeploy', $instance);
        $this->workflow->run($instance, $this->project, true, $this->logFor($job), false);

        self::assertSame(['acme--main'], $this->tmuxRunner->windows);
        self::assertSame(['echo hi', 'echo hi'], $this->tmuxRunner->typedIn('tty2'), 'typed once per deploy');
        $kills = array_filter($this->tmuxRunner->argvs, static fn (array $a): bool => 'kill-window' === ($a[3] ?? ''));
        self::assertNotSame([], $kills, 'the old window was closed');
    }

    public function testDestroyingClosesTheWindow(): void
    {
        $this->template([], ['echo hi']);
        $instance = $this->instance('main', 'develop');
        $this->deployed($instance);
        self::assertSame(['acme--main'], $this->tmuxRunner->windows);

        $job = $this->job('destroy', $instance);
        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertSame([], $this->tmuxRunner->windows);
        self::assertStringContainsString('closed the tmux window', $this->logText($job));
    }

    public function testDestroyingWithoutTmuxStillSucceeds(): void
    {
        $instance = $this->instance('main', 'develop');
        $this->checkout($instance);
        $this->tmuxRunner->missing = true;
        $job = $this->job('destroy', $instance);

        $this->teardown->removeCheckout($instance, $this->logFor($job));

        self::assertDirectoryDoesNotExist($this->paths->instanceDir($instance->id()));
        self::assertStringContainsString('WARNING: the tmux window could not be closed', $this->logText($job));
    }
}
