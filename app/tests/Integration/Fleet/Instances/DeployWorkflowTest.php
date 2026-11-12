<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\InstanceState;
use App\Fleet\Ddev\DdevPaths;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

final class DeployWorkflowTest extends InstanceJobTestCase
{
    private function deploy(string $label = 'main', string $branch = 'develop', ?string $requestedLabel = null): \App\Domain\Job\Job
    {
        $instance = $this->instance($label, $branch);
        $job = $this->job('deploy', $instance);
        $this->workflow->run($instance, $this->project, false, $this->logFor($job), false, null, $requestedLabel);

        return $job;
    }

    private function failedDeploy(string $label = 'main', string $branch = 'develop'): JobFailed
    {
        try {
            $this->deploy($label, $branch);
        } catch (JobFailed $e) {
            self::assertSame(InstanceState::Failed, $this->instances->find('acme--'.$label)?->state());

            return $e;
        }
        self::fail('expected the deploy to fail');
    }

    private function writeOverride(string $yaml): void
    {
        $this->steps->append(new class($yaml) implements DeployStep {
            public function __construct(private readonly string $yaml)
            {
            }

            public function phase(): StepPhase
            {
                return StepPhase::BeforeStart;
            }

            public function run(DeployContext $context): void
            {
                file_put_contents($context->dir.'/.ddev/docker-compose.dockersock.yaml', $this->yaml);
            }
        });
    }

    public function testADockerSocketOverrideRefusesTheDeployBeforeDdevStarts(): void
    {
        // FLE-298: the Docker socket bridge of a project. Nothing may start.
        $this->writeOverride("services:\n  web:\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n");
        $this->saveTemplate('full', ['ddev init']);

        $e = $this->failedDeploy();

        self::assertStringContainsString('Refused: the project ships DDEV files', $e->getMessage());
        self::assertStringContainsString('.ddev/docker-compose.dockersock.yaml: services.web.volumes[0]', $e->getMessage());
        self::assertNotContains('ddev start', $this->commandNames());
    }

    public function testAProjectWithPrivilegedOverridesOnDeploysAndIsAuditedWithTheViolations(): void
    {
        $this->writeOverride("services:\n  web:\n    privileged: true\n");
        $this->saveTemplate('full', []);
        $this->project->changeAllowPrivilegedOverrides(true);
        new \App\Domain\Project\ProjectRepository($this->em)->save($this->project);

        $job = $this->deploy();

        self::assertSame(InstanceState::Running, $this->instances->find('acme--main')?->state());
        self::assertContains('ddev start', $this->commandNames());
        self::assertStringContainsString('privileged compose overrides allowed', $this->logText($job));
        $actions = array_map(static fn ($e): string => $e->action().' '.$e->subject(), new \App\Domain\Audit\AuditEntryRepository($this->em)->latest(10));
        self::assertStringContainsString('deploy.overrides-allowed acme--main: .ddev/docker-compose.dockersock.yaml: services.web.privileged', implode("\n", $actions));
    }

    public function testADeployDisconnectsTheDbFromTheSharedNetworkAfterTheStart(): void
    {
        // FLE-297 review gap: the deploy must run the isolation check right after `ddev start`, before the post-deploy lines.
        $this->workflow = $this->workflowWith(preStart: $this->preStartGate(true));
        $this->saveTemplate('full', ['ddev drush cr']);
        $id = 'acme--main';
        $this->runner->expect(['ddev', 'debug', 'compose-config'], new CommandResult(0, "services:\n  web: {}\n  db: {}\n"));
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-db|db|com.ddev.site-name='.$id."\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}'));

        $job = $this->deploy();

        self::assertSame(InstanceState::Running, $this->instances->find($id)?->state());
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'disconnect', 'ddev_default', 'ddev-'.$id.'-db']));
        $names = $this->commandNames();
        $disconnect = array_search('docker network', $names, true);
        self::assertIsInt($disconnect, implode(', ', $names));
        self::assertLessThan(array_search('ddev drush', $names, true), $disconnect);
        self::assertStringContainsString('isolation: disconnected ddev-'.$id.'-db from ddev_default', $this->logText($job));
    }

    public function testAFailedDisconnectFailsTheDeployBeforeThePostDeployLines(): void
    {
        $this->workflow = $this->workflowWith(preStart: $this->preStartGate(true));
        $this->saveTemplate('full', ['ddev drush cr']);
        $id = 'acme--main';
        $this->runner->expect(['ddev', 'debug', 'compose-config'], new CommandResult(0, "services:\n  web: {}\n  db: {}\n"));
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-db|db|com.ddev.site-name='.$id."\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(1));

        $e = $this->failedDeploy();

        self::assertStringContainsString('The database isolation is not in effect', $e->getMessage());
        self::assertNotContains('ddev drush', $this->commandNames());
    }

    public function testARedeployOfARunningInstanceWhoseNewCheckoutIsRefusedLeavesItRunning(): void
    {
        // FLE-319: the old checkout and containers must survive; nothing is torn down or started.
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.ddev', 0o750, true);
        file_put_contents($dir.'/marker', 'old');
        $this->runner->expect(['git'], new CommandResult(0, ''), static function (array $argv): void {
            $target = end($argv);
            mkdir($target.'/.ddev', 0o750, true);
            file_put_contents($target.'/.ddev/docker-compose.dockersock.yaml', "services:\n  web:\n    privileged: true\n");
        });
        $job = $this->job('redeploy', $instance);

        try {
            $this->workflow->run($instance, $this->project, true, $this->logFor($job), false);
            self::fail('expected a refusal');
        } catch (JobFailed $e) {
            self::assertStringContainsString('still running its old checkout', $e->getMessage());
            self::assertStringContainsString('docker-compose.dockersock.yaml:services.web.privileged', $e->getMessage());
        }

        self::assertSame(InstanceState::Running, $this->instances->find($instance->id())?->state());
        self::assertFileExists($dir.'/marker');
        self::assertNotContains('ddev delete', $this->commandNames());
        self::assertNotContains('ddev start', $this->commandNames());
        self::assertDirectoryDoesNotExist($this->paths->stagingDir().'/redeploy-'.$instance->id());
        self::assertStringContainsString('redeploy refused', $this->logText($job));
        $audit = $this->em->getRepository(\App\Domain\Audit\AuditEntry::class)->findBy(['action' => 'redeploy.refused']);
        self::assertCount(1, $audit);
    }

    public function testARedeployOfARunningInstanceWhoseBranchCannotBeClonedAbortsBeforeTeardown(): void
    {
        // FLE-323: a missing branch or an unreachable remote must not cost the running instance its checkout.
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/.ddev', 0o750, true);
        file_put_contents($dir.'/marker', 'old');
        $this->runner->expect(['git'], new CommandResult(128, '', "fatal: Remote branch develop not found in upstream origin\n"));
        $job = $this->job('redeploy', $instance);

        try {
            $this->workflow->run($instance, $this->project, true, $this->logFor($job), false);
            self::fail('expected an abort');
        } catch (JobFailed $e) {
            self::assertStringContainsString('Redeploy aborted', $e->getMessage());
            self::assertStringContainsString('still running its old checkout', $e->getMessage());
            self::assertStringContainsString('exit code 128', $e->getMessage());
        }

        self::assertSame(InstanceState::Running, $this->instances->find($instance->id())?->state());
        self::assertFileExists($dir.'/marker');
        self::assertSame(['git clone'], $this->commandNames(), 'no ddev delete, no second clone');
        self::assertDirectoryDoesNotExist($this->paths->stagingDir().'/redeploy-'.$instance->id());
        self::assertStringContainsString('redeploy aborted', $this->logText($job));
        $audit = $this->em->getRepository(\App\Domain\Audit\AuditEntry::class)->findBy(['action' => 'redeploy.aborted']);
        self::assertCount(1, $audit);
    }

    public function testTheScanCloneUsesItsOwnShorterTimeout(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $this->workflow->run($instance, $this->project, true, $this->logFor($this->job('redeploy', $instance)), false);

        $timeouts = [];
        foreach ($this->runner->calls() as $call) {
            if ('git' === $call->argv[0] && \in_array('clone', $call->argv, true)) {
                $timeouts[] = $call->timeout;
            }
        }
        self::assertSame([\App\Fleet\Git\GitClient::SCAN_CLONE_TIMEOUT, \App\Fleet\Git\GitClient::CLONE_TIMEOUT], $timeouts);
    }

    public function testARedeployOfARunningInstanceWithACleanNewCheckoutProceeds(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $job = $this->job('redeploy', $instance);

        $this->workflow->run($instance, $this->project, true, $this->logFor($job), false);

        self::assertSame(2, \count(array_keys($this->commandNames(), 'git clone', true)), 'the scan clone, then the real one');
        self::assertContains('ddev start', $this->commandNames());
        self::assertSame(InstanceState::Running, $this->instances->find($instance->id())?->state());
        self::assertDirectoryDoesNotExist($this->paths->stagingDir().'/redeploy-'.$instance->id());
    }

    public function testHappyPathRunsTheStepsInOrder(): void
    {
        $this->saveTemplate('full', ['ddev init', 'ddev drush cr'], 'dev');
        $this->runner->expect(['git', 'rev-parse'], new CommandResult(0, "abc1234\n"));

        $job = $this->deploy();
        $instance = $this->instances->find('acme--main');

        self::assertSame(['git clone', 'git config', 'caddy validate', 'caddy reload', 'ddev start', 'ddev init', 'ddev drush', 'git rev-parse'], $this->commandNames());
        self::assertNotNull($instance);
        self::assertSame(InstanceState::Running, $instance->state());
        self::assertSame('abc1234', $instance->headSha());
        self::assertNotNull($instance->deployedAt());
        $log = $this->logText($job);
        self::assertStringContainsString('deploy start: project=acme template=full label=main branch=develop', $log);
        self::assertStringContainsString('deploy complete', $log);

        $dir = $this->paths->instanceDir('acme--main');
        self::assertFileExists($dir.'/.ddev/config.fleet.yaml');
        $exclude = (string) file_get_contents($dir.'/.git/info/exclude');
        self::assertStringContainsString(".ddev/config.fleet.yaml\n", $exclude);
        self::assertStringContainsString(".env\n", $exclude);
        self::assertFileExists($this->paths->instanceSiteFile('acme--main'));
    }

    public function testTheContainerGetsAPinnedKnownHostsAndAnAgentOnlySshCommand(): void
    {
        mkdir($this->paths->sshDir(), 0o700, true);
        file_put_contents($this->paths->knownHostsFile(), "git.example.com ssh-ed25519 AAAApinned\n");

        $this->deploy();

        $dir = $this->paths->instanceDir('acme--main');
        self::assertSame("git.example.com ssh-ed25519 AAAApinned\n", file_get_contents($dir.'/.ddev/fleet_known_hosts'));
        self::assertStringContainsString(".ddev/fleet_known_hosts\n", (string) file_get_contents($dir.'/.git/info/exclude'));
        $config = (string) file_get_contents($dir.'/.ddev/config.fleet.yaml');
        self::assertStringContainsString('GIT_SSH_COMMAND=', $config);
        self::assertStringContainsString('-o StrictHostKeyChecking=yes', $config);
        self::assertStringContainsString('-o BatchMode=yes', $config);
        self::assertStringContainsString('UserKnownHostsFile='.DdevPaths::CONTAINER_APPROOT.'/.ddev/fleet_known_hosts', $config);
        self::assertStringNotContainsString('IdentitiesOnly', $config, 'it would ignore the agent keys');
        self::assertStringNotContainsString($this->paths->sshDir(), $config, 'no host path, no key file in the container');
    }

    private function withPushKey(): void
    {
        if (!is_dir($this->paths->sshDir())) {
            mkdir($this->paths->sshDir(), 0o700, true);
        }
        touch($this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push));
    }

    /**
     * @return list<list<string>>
     */
    private function agentCalls(): array
    {
        return array_values(array_filter($this->argvs(), static fn (array $argv): bool => \in_array($argv[0], ['ssh-agent', 'ssh-add'], true)));
    }

    public function testAnOptedInProjectGetsItsOwnAgentAndASocketMountForThisInstanceOnly(): void
    {
        $this->withPushKey();
        $this->project->changeContainerSsh(true);
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=4242; export SSH_AGENT_PID;\n"));

        $this->deploy();

        $names = array_column($this->agentCalls(), 0);
        self::assertSame(['ssh-agent', 'ssh-add'], $names, 'the agent, then the key; nothing else');
        $dir = $this->paths->instanceDir('acme--main');
        $override = (string) file_get_contents($dir.'/.ddev/docker-compose.fleet-ssh.yaml');
        self::assertStringContainsString($this->paths->agentSocketDir('acme--main').':/run/fleet-ssh-agent', $override);
        self::assertStringContainsString('SSH_AUTH_SOCK=/run/fleet-ssh-agent/agent.sock', $override);
        self::assertStringContainsString(".ddev/docker-compose.fleet-ssh.yaml\n", (string) file_get_contents($dir.'/.git/info/exclude'));
        $started = array_search('ddev start', $this->commandNames(), true);
        self::assertNotFalse($started);
        self::assertEmpty(array_filter($this->argvs(), static fn (array $argv): bool => 'ddev' === $argv[0] && 'auth' === ($argv[1] ?? '')), 'nothing goes into the global DDEV agent');
    }

    public function testAnOptOutProjectGetsNoAgentNoSocketAndNoOverride(): void
    {
        $this->withPushKey();

        $this->deploy();

        self::assertSame([], $this->agentCalls());
        self::assertFileDoesNotExist($this->paths->instanceDir('acme--main').'/.ddev/docker-compose.fleet-ssh.yaml');
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir('acme--main'));
        $config = (string) file_get_contents($this->paths->instanceDir('acme--main').'/.ddev/config.fleet.yaml');
        self::assertStringNotContainsString('SSH_AUTH_SOCK', $config);
    }

    public function testARedeployOfAProjectThatOptedOutDropsTheOldOverrideAndAgent(): void
    {
        $this->withPushKey();
        mkdir($this->paths->agentSocketDir('acme--main'), 0o700, true);
        touch($this->paths->agentSocket('acme--main'));
        file_put_contents($this->paths->agentPidFile('acme--main'), "77\n");

        $job = $this->deploy();

        self::assertFileDoesNotExist($this->paths->instanceDir('acme--main').'/.ddev/docker-compose.fleet-ssh.yaml');
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir('acme--main'));
        self::assertStringContainsString('no longer uses container_ssh', $this->logText($job));
    }

    public function testAnOptedInProjectOnAHostWithoutAPushKeyOnlyLogsAWarning(): void
    {
        $this->project->changeContainerSsh(true);

        $job = $this->deploy();

        self::assertSame([], $this->agentCalls());
        self::assertFileDoesNotExist($this->paths->instanceDir('acme--main').'/.ddev/docker-compose.fleet-ssh.yaml');
        self::assertStringContainsString('the host has no push key', $this->logText($job));
    }

    public function testAnUnregisteredPushKeyIsWarnedAboutInTheDeployLog(): void
    {
        $this->project->changeContainerSsh(true);
        $this->withPushKey();
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=4242; export SSH_AGENT_PID;\n"));
        $this->runner->expect(['ssh', '-F', '/dev/null'], new CommandResult(255, '', 'git@git.example.com: Permission denied (publickey).'));
        $this->workflow = $this->workflowWith(pushCheck: new \App\Fleet\Ssh\PushKeyCheck($this->paths, $this->runner));

        $job = $this->deploy();

        self::assertStringContainsString('warning: The push key is not registered on the git host git.example.com', $this->logText($job));
    }

    public function testAnAgentThatCannotBeStartedFailsTheDeployBeforeDdevStart(): void
    {
        $this->withPushKey();
        $this->project->changeContainerSsh(true);
        $this->runner->expect(['ssh-agent'], new CommandResult(1, '', 'cannot bind'));

        $e = $this->failedDeploy();

        self::assertStringContainsString('ssh-agent setup failed', $e->getMessage());
        self::assertNotContains('ddev start', $this->commandNames());
    }

    public function testAFailingGitSshSetupFailsTheDeploy(): void
    {
        $this->runner->expect(['git', 'config'], new CommandResult(1, '', "error: could not lock config file\n"));

        $e = $this->failedDeploy();

        self::assertStringContainsString('git ssh setup failed with exit code 1', $e->getMessage());
        self::assertNotContains('ddev start', $this->commandNames());
    }

    public function testASecretTheDeployUsesNeverReachesTheJobLog(): void
    {
        $this->secretStore->putFleetWide(HostSecrets::CLAUDE_TOKEN, 'tok-claude-SECRET-99', 'admin');
        $this->runner->expect(['ddev', 'start'], new CommandResult(0, "web: CLAUDE_CODE_OAUTH_TOKEN=tok-claude-SECRET-99\n", "warning: tok-claude-SECRET-99 is set\n"));

        $instance = $this->instance();
        $job = $this->job('deploy', $instance);
        $jobLog = $this->logFor($job);
        $this->workflow->run($instance, $this->project, false, $jobLog, false);
        $jobLog->finish(); // JobExecutor does this: the masker holds back the last bytes until then

        $log = $this->logText($job);
        self::assertStringNotContainsString('tok-claude-SECRET-99', $log);
        self::assertStringContainsString('CLAUDE_CODE_OAUTH_TOKEN=[masked]', $log);
        self::assertStringContainsString('warning: [masked] is set', $log);
        self::assertStringContainsString('deploy complete', $log);
    }

    public function testCloneFailureMarksTheInstanceFailed(): void
    {
        $this->runner->expect(['git', '-c'], new CommandResult(128, '', "fatal: repository not found\n"));

        $e = $this->failedDeploy();

        self::assertStringContainsString('git clone failed with exit code 128', $e->getMessage());
        self::assertSame(['git clone'], $this->commandNames());
        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--main'));
    }

    public function testStubDirectoryWithoutGitIsRecovered(): void
    {
        $instance = $this->instance();
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/vendor', 0o750, true);
        file_put_contents($dir.'/vendor/half', 'x');
        $job = $this->job('deploy', $instance);

        $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);

        self::assertStringContainsString('recovered from a partial instance directory', $this->logText($job));
        self::assertFileDoesNotExist($dir.'/vendor/half');
        self::assertSame(InstanceState::Running, $instance->state());
    }

    public function testExistingCheckoutOnAFreshDeployIsRefused(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        $job = $this->job('deploy', $instance);

        try {
            $this->workflow->run($instance, $this->project, false, $this->logFor($job), false);
            self::fail('expected a refusal');
        } catch (JobFailed $e) {
            self::assertStringContainsString('no record', $e->getMessage());
        }

        self::assertFileExists($dir.'/index.php');
        self::assertSame([], $this->runner->calls());
        self::assertSame(InstanceState::Failed, $instance->state());
    }

    public function testDdevStartFailureKeepsTheLog(): void
    {
        $this->runner->expect(['ddev', 'start'], new CommandResult(2, '', "Failed to start: boom\n"));

        $e = $this->failedDeploy();

        self::assertSame('ddev start failed with exit code 2', $e->getMessage());
        self::assertSame(2, $e->exitCode);
        $job = $this->em->getRepository(\App\Domain\Job\Job::class)->findOneBy([]);
        self::assertNotNull($job);
        self::assertStringContainsString('Failed to start: boom', $this->logText($job));
    }

    public function testPostDeployRunsAsHostArgvWithFleetEnv(): void
    {
        $this->saveTemplate('full', ['ddev init', "ddev drush user:create 'a b' [[instance]]-[[project]]"]);

        $this->deploy();

        $init = $this->runner->callsMatching(['ddev', 'init']);
        $drush = $this->runner->callsMatching(['ddev', 'drush']);
        self::assertCount(1, $init);
        self::assertSame(['ddev', 'drush', 'user:create', 'a b', 'main-acme'], $drush[0]->argv);
        self::assertSame($this->paths->instanceDir('acme--main'), $drush[0]->cwd);
        self::assertSame('acme--main', $drush[0]->env['FLEET_INSTANCE_ID']);
        self::assertSame('acme', $drush[0]->env['FLEET_PROJECT']);
        self::assertSame('main', $drush[0]->env['FLEET_INSTANCE']);
        self::assertSame('develop', $drush[0]->env['FLEET_BRANCH']);
        self::assertSame('acme--main.fleet.example.com', $drush[0]->env['FLEET_INSTANCE_FQDN']);
        self::assertSame('fleet.example.com', $drush[0]->env['FLEET_DOMAIN']);
        self::assertSame('true', $drush[0]->env['DDEV_NONINTERACTIVE']);
    }

    public function testShellSyntaxInPostDeployFailsBeforeAnythingRuns(): void
    {
        $this->saveTemplate('full', ['ddev init', 'ddev drush cr | cat']);

        $e = $this->failedDeploy();

        self::assertStringContainsString('line 2', $e->getMessage());
        self::assertStringContainsString("ddev exec '...'", $e->getMessage());
        self::assertSame([], $this->runner->calls());
        self::assertDirectoryDoesNotExist($this->paths->instanceDir('acme--main'));
    }

    public function testUnresolvedTokenFailsBeforeAnythingRuns(): void
    {
        $this->saveTemplate('full', ['ddev init', 'ddev drush user:create [[issue-id]]']);

        $e = $this->failedDeploy();

        self::assertStringContainsString('[[issue-id]]', $e->getMessage());
        self::assertSame([], $this->runner->calls());
    }

    public function testProjectSecretTokenIsInArgvAndMaskedInTheJobLog(): void
    {
        $this->secretStore->putManaged('acme', 'MY_SECRET', 'proj-s3cret-value', 'test');
        $this->saveTemplate('full', ['ddev drush config:set key [[my-secret]]']);
        $this->runner->expect(['ddev', 'drush'], new CommandResult(0, "echo proj-s3cret-value\n", ''));

        $this->deploy();

        $drush = $this->runner->callsMatching(['ddev', 'drush']);
        self::assertSame(['ddev', 'drush', 'config:set', 'key', 'proj-s3cret-value'], $drush[0]->argv);
        $job = $this->em->getRepository(\App\Domain\Job\Job::class)->findOneBy([]);
        self::assertNotNull($job);
        $log = $this->logText($job);
        self::assertStringContainsString('post_deploy: ddev drush config:set key [[my-secret]]', $log);
        self::assertStringNotContainsString('proj-s3cret-value', $log);
        self::assertStringContainsString('[masked]', $log);
    }

    public function testProjectSecretWinsOverFleetWideAndFleetWideAloneResolves(): void
    {
        $this->secretStore->putManaged(null, 'SHARED', 'fleet-value-1', 'test');
        $this->secretStore->putManaged(null, 'BOTH', 'fleet-value-2', 'test');
        $this->secretStore->putManaged('acme', 'BOTH', 'project-value-2', 'test');
        $this->saveTemplate('full', ['ddev drush x [[shared]] [[both]] [[project]]']);

        $this->deploy();

        $drush = $this->runner->callsMatching(['ddev', 'drush']);
        self::assertSame(['ddev', 'drush', 'x', 'fleet-value-1', 'project-value-2', 'acme'], $drush[0]->argv);
    }

    public function testUnresolvedSecretTokenFailsBeforeAnythingRunsAndNamesOnlyTheToken(): void
    {
        $this->secretStore->putManaged('acme', 'MY_SECRET', 'proj-s3cret-value', 'test');
        $this->saveTemplate('full', ['ddev drush x [[my-secret]] [[no-such-secret]]']);

        $e = $this->failedDeploy();

        self::assertStringContainsString('line 1 cannot run: unresolved token(s): [[no-such-secret]]', $e->getMessage());
        self::assertStringNotContainsString('proj-s3cret-value', $e->getMessage());
        self::assertSame([], $this->runner->calls());
    }

    public function testTokenValueNeverSplitsAWord(): void
    {
        $this->saveTemplate('full', ['ddev drush config:set x [[branch]]']);

        $this->deploy('main', 'feature/a&b');

        $drush = $this->runner->callsMatching(['ddev', 'drush']);
        self::assertSame(['ddev', 'drush', 'config:set', 'x', 'feature/a&b'], $drush[0]->argv);
    }

    public function testPostDeployNonZeroNamesTheLine(): void
    {
        $this->saveTemplate('full', ['ddev init', 'ddev drush cr']);
        $this->runner->expect(['ddev', 'init'], new CommandResult(3, '', "no\n"));

        $e = $this->failedDeploy();

        self::assertSame("post_deploy command 'ddev init' failed with exit code 3", $e->getMessage());
        self::assertSame([], $this->runner->callsMatching(['ddev', 'drush']), 'the list stops at the failing line');
    }

    public function testHostileBranchNeverReachesTheRunnerAsAnOption(): void
    {
        $e = $this->failedDeploy('main', '--upload-pack=touch /tmp/x');

        self::assertStringContainsString('not valid', $e->getMessage());
        self::assertSame([], $this->runner->callsMatching(['git']));
        self::assertSame([], $this->runner->callsMatching(['ddev']));
    }

    public function testDotDotIssueIdIsNotUsedAsAPath(): void
    {
        $this->project->changeIssueIdRegexp('[.]{2}');
        $this->em->flush();
        $this->saveTemplate('full', ['ddev init']);

        $this->deploy('main', 'a..b');

        foreach ($this->runner->calls() as $call) {
            self::assertArrayNotHasKey('FLEET_ISSUE_ID', $call->env);
            self::assertStringNotContainsString('..', (string) $call->cwd);
        }
    }

    public function testDrupalEnvIsWrittenToDotEnv(): void
    {
        $this->saveTemplate('full', [], 'staging');

        $this->deploy();

        self::assertSame("DRUPAL_ENV=staging\n", file_get_contents($this->paths->instanceDir('acme--main').'/.env'));
    }

    public function testTheTemplateDrupalEnvWinsOverAnEnvSeededFromTheAssets(): void
    {
        // FLE-192: the assets seed (a BeforeStart step) copies the project's own .env, which says DRUPAL_ENV=dev.
        $this->steps->append(new class implements DeployStep {
            public function phase(): StepPhase
            {
                return StepPhase::BeforeStart;
            }

            public function run(DeployContext $context): void
            {
                file_put_contents($context->dir.'/.env', "# env\nDRUPAL_ENV=dev\nME=\"DDEV Fleet\"\n");
            }
        });
        $this->saveTemplate('full', [], 'staging');
        $seen = null;
        $env = $this->paths->instanceDir('acme--main').'/.env';
        $this->runner->expect(['ddev', 'start'], new CommandResult(0), static function () use (&$seen, $env): void {
            $seen = file_get_contents($env);
        });

        $this->deploy();

        self::assertSame("# env\nDRUPAL_ENV=staging\nME=\"DDEV Fleet\"\n", $seen, 'set before ddev start, the rest of the file kept');
    }

    public function testStepsAndSiteComeBeforeDdevStart(): void
    {
        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject();
        $site = $this->paths->instanceSiteFile('acme--main');
        $this->steps->append(self::step(StepPhase::AfterStart, 'after', $seen));
        $this->steps->append(self::step(StepPhase::BeforeStart, 'before', $seen));
        $this->runner->expect(['ddev', 'start'], new CommandResult(0), static function () use ($seen, $site): void {
            $seen->append('start (site '.(is_file($site) ? 'written' : 'MISSING').')');
        });

        $this->deploy();

        self::assertSame(['before', 'start (site written)', 'after'], $seen->getArrayCopy());
    }

    public function testLabelChangeIsExplainedInTheLog(): void
    {
        $job = $this->deploy('main-1', 'develop', 'main');

        self::assertStringContainsString("label 'main' already in use - allocated 'main-1' instead", $this->logText($job));
    }

    /**
     * @param \ArrayObject<int, string> $seen
     */
    private static function step(StepPhase $phase, string $name, \ArrayObject $seen): DeployStep
    {
        return new class($phase, $name, $seen) implements DeployStep {
            /** @param \ArrayObject<int, string> $seen */
            public function __construct(private readonly StepPhase $phase, private readonly string $name, private readonly \ArrayObject $seen)
            {
            }

            public function phase(): StepPhase
            {
                return $this->phase;
            }

            public function run(DeployContext $context): void
            {
                $this->seen->append($this->name);
            }
        };
    }
}
