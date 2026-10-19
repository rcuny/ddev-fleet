<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\InstanceState;
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

    public function testHappyPathRunsTheStepsInOrder(): void
    {
        $this->saveTemplate('full', ['ddev init', 'ddev drush cr'], 'dev');
        $this->runner->expect(['git', 'rev-parse'], new CommandResult(0, "abc1234\n"));

        $job = $this->deploy();
        $instance = $this->instances->find('acme--main');

        self::assertSame(['git clone', 'caddy validate', 'caddy reload', 'ddev start', 'ddev init', 'ddev drush', 'git rev-parse'], $this->commandNames());
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
