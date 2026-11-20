<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Instances\DiskHeadroom;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceRetention;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Projects\NotFound;
use App\Health\HostHealth;
use App\Tests\Support\JobTestCase;
use Doctrine\Persistence\ManagerRegistry;

final class InstanceRetentionTest extends JobTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $this->project->changeCiRetentionDays(7);
        new ProjectRepository($this->em)->save($this->project);
        new TemplateRepository($this->em)->save(new Template($this->project, 'full'));
        new HostSettingsRepository($this->em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
    }

    private function retention(): InstanceRetention
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $audit = self::getContainer()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);
        $service = new InstanceService(
            new ProjectRepository($this->em),
            new TemplateRepository($this->em),
            $this->instances,
            $this->jobs,
            $this->service,
            $this->em,
            $registry,
            $this->paths,
            new DiskHeadroom(new HostHealth($this->paths), 0.0),
            new InstanceHostnames(new HostSettingsRepository($this->em)),
            $this->clock,
        );

        return new InstanceRetention(new ProjectRepository($this->em), $this->instances, $this->jobs, $service, $audit, $this->clock);
    }

    private function instance(string $label, string $daysAgo, bool $deployed = true): Instance
    {
        $at = $this->clock->now()->modify('-'.$daysAgo.' days');
        $instance = new Instance($this->project, $label, 'full', 'feature/x', 'admin', $at);
        if ($deployed) {
            $instance->markDeployed($at);
        }
        $this->instances->save($instance);

        return $instance;
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        return array_map(static fn (array $c): string => $c['instance']->id(), $this->retention()->candidates());
    }

    public function testSelectsOnlyOldCiInstances(): void
    {
        $this->instance('ci-old', '10');
        $this->instance('ci-new', '3');
        $this->instance('feature-old', '30');
        $this->instance('main', '30');
        $this->instance('ci-nodeploy', '9', false);

        self::assertSame(['acme--ci-old', 'acme--ci-nodeploy'], $this->ids());
    }

    public function testDeployedAtWinsOverCreatedAt(): void
    {
        $instance = new Instance($this->project, 'ci-redeployed', 'full', 'b', 'admin', $this->clock->now()->modify('-30 days'));
        $instance->markDeployed($this->clock->now()->modify('-1 day'));
        $this->instances->save($instance);

        self::assertSame([], $this->ids());
    }

    public function testProtectedAndBusyAreSkipped(): void
    {
        $locked = $this->instance('ci-locked', '20');
        $locked->protect('admin', $this->clock->now());
        $this->instances->save($locked);
        $busy = $this->instance('ci-busy', '20');
        $this->em->persist(new Job('stop', $busy->id(), 'acme', JobTrigger::Ui, 'x', [], null, false, null, $this->clock->now()));
        $this->em->flush();
        $this->instance('ci-free', '20');

        self::assertSame(['acme--ci-free'], $this->ids());
    }

    public function testOffByDefault(): void
    {
        $this->project->changeCiRetentionDays(null);
        new ProjectRepository($this->em)->save($this->project);
        $this->instance('ci-old', '100');

        self::assertSame([], $this->ids());
    }

    public function testRunQueuesDestroyJobsAndAudits(): void
    {
        $this->instance('ci-old', '10');
        $retention = $this->retention();

        $result = $retention->run(null, 'scheduler', JobTrigger::Schedule);

        self::assertSame(['acme--ci-old'], $result['destroyed']);
        self::assertSame([], $result['skipped']);
        $jobs = $this->jobs->forInstance('acme--ci-old');
        self::assertCount(1, $jobs);
        self::assertSame('destroy', $jobs[0]->type());
        self::assertSame(JobTrigger::Schedule, $jobs[0]->trigger());
        $audit = self::getContainer()->get(AuditLog::class);
        self::assertInstanceOf(AuditLog::class, $audit);
        $entries = $audit->latest(10, AuditLog::INSTANCE_RETENTION);
        self::assertCount(1, $entries);
        self::assertSame('acme--ci-old/7d', $entries[0]->subject());
        // the instance is now destroying with a job: a second pass finds nothing
        self::assertSame([], $this->ids());
    }

    public function testUnknownProject(): void
    {
        $this->expectException(NotFound::class);
        $this->retention()->candidates('nope');
    }
}
