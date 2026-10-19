<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceState;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\RedeployJob;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

final class RedeployJobTest extends InstanceJobTestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function redeploy(array $params = []): \App\Domain\Job\Job
    {
        $instance = $this->instances->find('acme--main');
        self::assertNotNull($instance);
        $job = $this->job('redeploy', $instance, $params);
        new RedeployJob($this->instances, $this->workflow)->execute($job, $this->logFor($job));

        return $job;
    }

    public function testDestroysThenRebuildsFromTheRecordedParameters(): void
    {
        $instance = $this->instance('main', 'feature/x', InstanceState::Deploying);
        $dir = $this->checkout($instance);
        $this->saveTemplate('full', ['ddev init']);

        $this->redeploy(['template' => 'full', 'force' => false]);

        self::assertFileDoesNotExist($dir.'/index.php');
        self::assertSame(['caddy validate', 'ddev delete', 'git clone', 'caddy validate', 'caddy reload', 'ddev start', 'ddev init', 'git rev-parse'], $this->commandNames());
        $clone = $this->runner->callsMatching(['git'])[0]->argv;
        self::assertContains('feature/x', $clone);
        self::assertSame(InstanceState::Running, $instance->state());
        self::assertFileExists($this->paths->instanceSiteFile('acme--main'));
    }

    public function testAnotherBranchInTheParamsChangesTheInstanceBranch(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Deploying);
        $this->checkout($instance);
        $this->saveTemplate('full', ['ddev init']);

        $this->redeploy(['template' => 'full', 'branch' => 'release/2']);

        self::assertSame('release/2', $instance->branch());
        self::assertContains('release/2', $this->runner->callsMatching(['git'])[0]->argv);
    }

    public function testNoBranchInTheParamsKeepsTheInstanceBranch(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Deploying);
        $this->checkout($instance);
        $this->saveTemplate('full', ['ddev init']);

        $this->redeploy(['template' => 'full']);

        self::assertSame('develop', $instance->branch());
    }

    public function testVanishedTemplateFailsWithoutDestroying(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);

        try {
            $this->redeploy(['template' => 'gone']);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('no longer exists; nothing was changed', $e->getMessage());
        }

        self::assertFileExists($dir.'/index.php');
        self::assertSame([], $this->runner->calls());
        self::assertSame('full', $instance->templateName());
        self::assertSame(InstanceState::Failed, $instance->state());
    }

    public function testCaddyValidationFailureFailsWithoutDestroying(): void
    {
        $instance = $this->instance();
        $dir = $this->checkout($instance);
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: broken'));

        try {
            $this->redeploy(['template' => 'full']);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('fix Caddy first', $e->getMessage());
        }

        self::assertFileExists($dir.'/index.php');
        self::assertSame([], $this->runner->callsMatching(['ddev']));
        self::assertSame([], $this->runner->callsMatching(['git']));
    }

    public function testHalfClonedStubWorks(): void
    {
        $instance = $this->instance();
        $dir = $this->paths->instanceDir($instance->id());
        mkdir($dir.'/half', 0o750, true);
        file_put_contents($dir.'/half/file', 'x');

        $job = $this->redeploy(['template' => 'full']);

        self::assertFileDoesNotExist($dir.'/half/file');
        self::assertSame(InstanceState::Running, $instance->state());
        self::assertStringContainsString('deploy complete', $this->logText($job));
    }

    public function testTemplateEditsSinceTheOriginalDeployAreUsed(): void
    {
        $instance = $this->instance();
        $this->checkout($instance);
        $this->saveTemplate('full', ['ddev drush cr']);
        $this->saveTemplate('edge', ['ddev drush updb']);

        $this->redeploy(['template' => 'edge']);

        self::assertSame('edge', $instance->templateName());
        self::assertCount(1, $this->runner->callsMatching(['ddev', 'drush', 'updb']));
        self::assertSame([], $this->runner->callsMatching(['ddev', 'drush', 'cr']));
    }

    public function testAMissingInstanceFailsTheJob(): void
    {
        $instance = $this->instance();
        $job = $this->job('redeploy', $instance);
        $this->instances->remove($instance);

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('instance no longer exists');

        new RedeployJob($this->instances, $this->workflow)->execute($job, $this->logFor($job));
    }
}
