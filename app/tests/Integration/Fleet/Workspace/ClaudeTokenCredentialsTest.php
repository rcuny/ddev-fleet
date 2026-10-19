<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Workspace;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Instances\InstanceConfigWriter;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\TmuxWindowStep;
use App\Tests\Support\FakeTmuxRunner;
use App\Tests\Support\InstanceJobTestCase;

/**
 * Where the Claude token may and may not appear during a deploy: it is written once, into the instance's
 * `config.fleet.yaml` (read by ddev start, so it is in the container environment), and nowhere else.
 */
final class ClaudeTokenCredentialsTest extends InstanceJobTestCase
{
    private const string TOKEN = 'sk-ant-oat01-credentials-unique-0123456789';
    private const string CLAUDE = 'ddev exec claude --model sonnet "/jira work [[issue-id]]"';

    private FakeTmuxRunner $tmuxRunner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmuxRunner = new FakeTmuxRunner();
        $this->steps[] = new TmuxWindowStep(new Tmux($this->tmuxRunner, $this->paths, '/opt/ddev-fleet/app/bin/console'), $this->templates);
        $this->project->changeIssueIdRegexp('FLE-[0-9]+');
        $template = $this->saveTemplate('full', ['ddev drush cr']);
        $template->changeCommands(['ddev drush cr'], ['ddev logs -f'], [self::CLAUDE]);
        $this->templates->save($template);
    }

    private function deploy(): \App\Domain\Job\Job
    {
        $instance = $this->instance('fle-9', 'FLE-9');
        $job = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);

        return $job;
    }

    public function testTheTokenIsOnlyInTheInstanceConfigFile(): void
    {
        $this->secretStore->putFleetWide(HostSecrets::CLAUDE_TOKEN, self::TOKEN, 'admin');

        $job = $this->deploy();

        $file = $this->paths->instanceDir('acme--fle-9').'/'.InstanceConfigWriter::RELATIVE_PATH;
        self::assertStringContainsString('CLAUDE_CODE_OAUTH_TOKEN='.self::TOKEN, (string) file_get_contents($file));
        self::assertSame(0o640, fileperms($file) & 0o777);
        self::assertStringContainsString(".ddev/config.fleet.yaml\n", (string) file_get_contents($this->paths->instanceDir('acme--fle-9').'/.git/info/exclude'));

        self::assertSame(['ddev exec claude --model sonnet "/jira work FLE-9"'], $this->tmuxRunner->typedIn('tty2'));
        self::assertStringNotContainsString(self::TOKEN, json_encode($this->tmuxRunner->typed, \JSON_THROW_ON_ERROR), 'not in the keys typed');
        $everything = json_encode([$this->tmuxRunner->argvs, $this->argvs(), array_map(static fn ($c): array => $c->env, $this->runner->calls())], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('credentials-unique', $everything, 'not in a process argument nor its environment');
        self::assertStringNotContainsString('credentials-unique', $this->logText($job));
        $audit = json_encode(array_map(static fn ($e): array => [$e->actor(), $e->action(), $e->subject()], new AuditEntryRepository($this->em)->latest(50)), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('credentials-unique', $audit);
        self::assertStringNotContainsString('credentials-unique', (string) file_get_contents($this->paths->instanceSiteFile('acme--fle-9')));
    }

    public function testWithoutATokenNoLineIsWrittenAndTheStepStillSucceeds(): void
    {
        $job = $this->deploy();

        $file = (string) file_get_contents($this->paths->instanceDir('acme--fle-9').'/'.InstanceConfigWriter::RELATIVE_PATH);
        self::assertStringNotContainsString('CLAUDE_CODE_OAUTH_TOKEN', $file);
        self::assertSame(InstanceState::Running, $this->instances->find('acme--fle-9')?->state());
        self::assertSame(['ddev exec claude --model sonnet "/jira work FLE-9"'], $this->tmuxRunner->typedIn('tty2'));
        self::assertStringContainsString('tmux window ready', $this->logText($job));
    }
}
