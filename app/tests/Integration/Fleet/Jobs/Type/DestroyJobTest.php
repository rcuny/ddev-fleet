<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobStatus;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\DestroyJob;
use App\Tests\Support\InstanceJobTestCase;

final class DestroyJobTest extends InstanceJobTestCase
{
    private function destroy(Job $job): void
    {
        new DestroyJob($this->instances, $this->teardown, $this->feed, $this->clock)->execute($job, $this->logFor($job));
    }

    public function testDestroysCheckoutSiteAndRow(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Destroying);
        $dir = $this->checkout($instance);
        $this->sites->write($instance, $this->project);
        $job = $this->job('destroy', $instance);

        $this->destroy($job);

        self::assertDirectoryDoesNotExist($dir);
        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--main'));
        $this->em->clear();
        self::assertNull($this->instances->find('acme--main'));
        self::assertStringContainsString('destroy complete', $this->logText($job));
    }

    public function testJobsAndLogSurviveTheRow(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Destroying);
        $this->checkout($instance);
        $earlier = $this->job('deploy', $instance);
        $earlier->finish(JobStatus::Succeeded, 0, null, $this->clock->now());
        $this->em->flush();
        $job = $this->job('destroy', $instance);

        $this->destroy($job);
        $this->em->clear();

        self::assertNull($this->instances->find('acme--main'));
        self::assertNotNull($this->em->getRepository(Job::class)->find($earlier->id()));
        self::assertNotNull($this->em->getRepository(Job::class)->find($job->id()));
        self::assertFileExists($this->paths->jobLog((string) $job->id(), 'acme--main'));
    }

    public function testLeftoverFilesFailAndKeepTheRowFailed(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can remove a read-only directory.');
        }
        $instance = $this->instance('main', 'develop', InstanceState::Destroying);
        $dir = $this->checkout($instance);
        mkdir($dir.'/locked');
        file_put_contents($dir.'/locked/file', 'x');
        chmod($dir.'/locked', 0o555);
        $job = $this->job('destroy', $instance);

        try {
            $this->destroy($job);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertStringContainsString('sudo rm -rf '.escapeshellarg($dir), $e->getMessage());
        } finally {
            chmod($dir.'/locked', 0o755);
        }

        self::assertSame(InstanceState::Failed, $instance->state());
        self::assertNotNull($this->instances->find('acme--main'));
    }

    public function testRetryAfterManualRemovalSucceeds(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Failed);
        $job = $this->job('destroy', $instance);
        $this->sites->write($instance, $this->project);

        $this->destroy($job);

        $this->em->clear();
        self::assertNull($this->instances->find('acme--main'), 'a directory that is already gone is success');
        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--main'));
    }

    public function testAnInstanceThatIsAlreadyGoneIsSuccess(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Destroying);
        $job = $this->job('destroy', $instance);
        $this->instances->remove($instance);

        $this->destroy($job);

        self::assertStringContainsString('already gone', $this->logText($job));
    }

    public function testNeverCallsPrivilegedApply(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Destroying);
        $this->checkout($instance);
        $this->sites->write($instance, $this->project);
        $job = $this->job('destroy', $instance);

        $this->destroy($job);

        foreach ($this->argvs() as $argv) {
            self::assertNotContains('sudo', $argv);
            self::assertNotContains('apply', $argv);
        }
        self::assertSame(['caddy validate', 'caddy reload', 'ddev delete', 'caddy validate', 'caddy reload'], $this->commandNames());
    }
}
