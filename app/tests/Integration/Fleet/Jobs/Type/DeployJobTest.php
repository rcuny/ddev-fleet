<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceState;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\DeployJob;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

final class DeployJobTest extends InstanceJobTestCase
{
    public function testSuccessSetsRunningFailureSetsFailed(): void
    {
        $ok = $this->instance('ok');
        $okJob = $this->job('deploy', $ok, ['template' => 'full', 'requestedLabel' => 'ok']);
        new DeployJob($this->instances, $this->workflow)->execute($okJob, $this->logFor($okJob));
        self::assertSame(InstanceState::Running, $ok->state());

        $bad = $this->instance('bad');
        $badJob = $this->job('deploy', $bad);
        $this->runner->expect(['ddev', 'start'], new CommandResult(1));
        try {
            new DeployJob($this->instances, $this->workflow)->execute($badJob, $this->logFor($badJob));
            self::fail('expected a failure');
        } catch (JobFailed) {
            self::assertSame(InstanceState::Failed, $bad->state());
        }
    }

    public function testAMissingInstanceFailsTheJob(): void
    {
        $instance = $this->instance();
        $job = $this->job('deploy', $instance);
        $this->instances->remove($instance);

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('instance no longer exists');

        new DeployJob($this->instances, $this->workflow)->execute($job, $this->logFor($job));
    }
}
