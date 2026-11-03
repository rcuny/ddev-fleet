<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Assets;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobTrigger;
use App\Fleet\Assets\SnapshotService;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\JobTestCase;

final class SnapshotServiceTest extends JobTestCase
{
    use InstanceFixtures;

    private AuditEntryRepository $entries;

    private function service(): SnapshotService
    {
        $this->entries = new AuditEntryRepository($this->em);

        return new SnapshotService($this->instances, $this->jobs, $this->service, $this->em, new AuditLog($this->entries, $this->clock), $this->clock);
    }

    public function testItQueuesASnapshotJobAndAudits(): void
    {
        $this->instancesOf($this->projectNamed('blog'), [InstanceState::Running]);

        $job = $this->service()->request('blog--i1', JobTrigger::Cli, 'cli');

        self::assertSame('snapshot', $job->type());
        self::assertSame('blog--i1', $job->instanceId());
        self::assertSame('blog', $job->projectName());
        self::assertSame(JobTrigger::Cli, $job->trigger());
        self::assertSame('cli', $job->requestedBy());
        self::assertCount(1, $this->bus->sent);
        $latest = $this->entries->latest(5, AuditLog::ASSET_SNAPSHOT);
        self::assertCount(1, $latest);
        self::assertSame('blog--i1', $latest[0]->subject());
    }

    public function testRefusals(): void
    {
        $project = $this->projectNamed('blog');
        $this->instancesOf($project, [InstanceState::Stopped, InstanceState::Running, InstanceState::Failed]);
        $this->projectNamed('shop');
        $service = $this->service();

        foreach (['blog--i1' => 'is not running (stopped)', 'blog--i3' => 'is not running (failed)'] as $id => $reason) {
            try {
                $service->request($id, JobTrigger::Ui, 'admin');
                self::fail('Expected a refusal.');
            } catch (Refused $e) {
                self::assertStringContainsString($reason, $e->getMessage());
            }
        }
        $service->request('blog--i2', JobTrigger::Ui, 'admin');
        try {
            $service->request('blog--i2', JobTrigger::Ui, 'admin');
            self::fail('Expected a refusal.');
        } catch (Refused $e) {
            self::assertSame('Busy: blog--i2 has a job queued or running. Nothing was started.', $e->getMessage());
        }
        self::assertCount(1, $this->bus->sent);
        self::assertCount(1, $this->entries->latest(10, AuditLog::ASSET_SNAPSHOT));
    }

    public function testUnknownAndForeignInstancesDoNotExist(): void
    {
        $this->instancesOf($this->projectNamed('blog'), [InstanceState::Running]);
        $this->projectNamed('shop');
        $service = $this->service();

        foreach ([['blog--nope', null], ['not an id', null], ['blog--i1', 'shop']] as [$id, $project]) {
            try {
                $service->request($id, JobTrigger::Ui, 'admin', $project);
                self::fail('Expected NotFound for '.$id);
            } catch (NotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertCount(0, $this->bus->sent);
    }

    public function testSnapshottableListsOnlyRunningInstancesOfTheProject(): void
    {
        $blog = $this->projectNamed('blog');
        $this->instancesOf($blog, [InstanceState::Running, InstanceState::Stopped, InstanceState::Running]);

        self::assertSame(['blog--i1', 'blog--i3'], $this->service()->snapshottable($blog));
        self::assertSame([], $this->service()->snapshottable($this->projectNamed('shop')));
    }
}
