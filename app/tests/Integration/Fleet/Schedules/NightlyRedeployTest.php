<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Schedules;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\ProjectRepository;
use App\Domain\Schedule\ScheduleOutcome;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Schedule\SchedulerHeartbeatRepository;
use App\Domain\Schedule\ScheduleRunRepository;
use App\Domain\Schedule\ScheduleTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Instances\DiskHeadroom;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Jobs\JobExecutor;
use App\Fleet\Jobs\JobService;
use App\Fleet\Jobs\JobTypeRegistry;
use App\Fleet\Jobs\LogReader;
use App\Fleet\Jobs\NoLogMasker;
use App\Fleet\Jobs\Type\RedeployJob;
use App\Fleet\Schedules\RedeployAction;
use App\Fleet\Schedules\ScheduleActions;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleLauncher;
use App\Fleet\Schedules\ScheduleService;
use App\Fleet\Schedules\ScheduleTicker;
use App\Health\HostHealth;
use App\Tests\Support\InstanceJobTestCase;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The real thing 0.x did, with no fake action: a `redeploy` schedule fires at its cron time, queues a real redeploy
 * job through InstanceService, the RedeployJob runs over the recording ddev/git doubles, and the run history follows the job.
 */
final class NightlyRedeployTest extends InstanceJobTestCase
{
    private RecordingMessageBus $bus;
    private JobRepository $jobRepository;
    private JobService $jobService;
    private ScheduleService $scheduleService;
    private ScheduleTicker $ticker;
    private ScheduleRunRepository $runs;
    private JobExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->bus = new RecordingMessageBus();
        $this->jobRepository = new JobRepository($this->em);
        $this->jobService = new JobService($this->jobRepository, $this->bus, $this->feed, new LogReader($this->paths), $this->clock, new NullLogger());
        $instanceService = new InstanceService(
            new ProjectRepository($this->em),
            $this->templates,
            $this->instances,
            $this->jobRepository,
            $this->jobService,
            $this->em,
            $registry,
            $this->paths,
            new DiskHeadroom(new HostHealth($this->paths), 0.0),
            new InstanceHostnames(new HostSettingsRepository($this->em)),
            $this->clock,
        );
        $actions = new ScheduleActions([new RedeployAction($instanceService, $this->instances)]);
        $this->runs = new ScheduleRunRepository($this->em);
        $schedules = new ScheduleRepository($this->em);
        $launcher = new ScheduleLauncher($actions, $this->runs, $registry, $this->em, new NullLogger());
        $this->ticker = new ScheduleTicker($schedules, $this->runs, new SchedulerHeartbeatRepository($this->em), $launcher, $registry, $this->em, $this->clock, new NullLogger());
        $this->scheduleService = new ScheduleService($schedules, $this->runs, $actions, $launcher, $this->jobRepository, new AuditLog(new AuditEntryRepository($this->em), $this->clock), $this->clock);
        $this->executor = new JobExecutor(
            $this->jobRepository,
            new JobTypeRegistry([new RedeployJob($this->instances, $this->workflow)]),
            $this->jobService,
            new LockFactory(new FlockStore($this->paths->locksDir())),
            $this->bus,
            $this->paths,
            new NoLogMasker(),
            $this->feed,
            $this->clock,
            new NullLogger(),
            0,
        );
    }

    private function running(string $label): Instance
    {
        $instance = $this->instance($label, 'develop', InstanceState::Running);
        $this->checkout($instance);

        return $instance;
    }

    private function schedule(string $name, Instance $instance, string $cron = '0 3 * * *'): void
    {
        $this->scheduleService->create(new ScheduleInput($name, $cron, 'UTC', 'redeploy', $instance->id()), 'admin');
    }

    /**
     * @return list<Job>
     */
    private function redeployJobs(): array
    {
        return array_values(array_filter($this->em->getRepository(Job::class)->findAll(), static fn (Job $job): bool => 'redeploy' === $job->type()));
    }

    public function testTheScheduleQueuesARealRedeployAndTheHistoryFollowsTheJob(): void
    {
        $instance = $this->running('main');
        $this->saveTemplate('full', ['ddev init']);
        $this->schedule('nightly', $instance);

        $this->clock->modify('2026-10-21 03:00:10 UTC');
        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        $jobs = $this->redeployJobs();
        self::assertCount(1, $jobs);
        $job = $jobs[0];
        self::assertSame($instance->id(), $job->instanceId());
        self::assertSame(JobTrigger::Schedule, $job->trigger());
        self::assertSame('schedule:nightly', $job->requestedBy());
        self::assertSame('full', $job->params()['template']);
        self::assertSame(InstanceState::Deploying, $instance->state());
        self::assertSame($job->id(), $created[0]->jobId());
        self::assertSame(ScheduleTrigger::Schedule, $created[0]->trigger());
        self::assertSame('claimed', $this->scheduleService->result($created[0]));

        // The worker picks the job up: the recorded ddev/git calls rebuild the instance and the history says so.
        $this->executor->run((int) $job->id());

        self::assertSame(JobStatus::Succeeded, $this->jobRepository->find((int) $job->id())?->status());
        self::assertSame(InstanceState::Running, $instance->state());
        self::assertContains('ddev delete', $this->commandNames());
        self::assertContains('git clone', $this->commandNames());
        self::assertContains('ddev init', $this->commandNames());
        $history = $this->scheduleService->history('nightly');
        self::assertCount(1, $history);
        self::assertSame($job->id(), $history[0]->jobId());
        self::assertSame('succeeded', $this->scheduleService->result($history[0]));
    }

    public function testAFailingRedeployShowsAsFailedInTheHistory(): void
    {
        $instance = $this->running('main');
        $this->schedule('nightly', $instance);
        $this->clock->modify('2026-10-21 03:00:10 UTC');
        $run = $this->ticker->tick()[0];

        // The template vanished between the request and the worker: the job fails and so does the history line.
        $this->em->remove($this->templates->find($this->project, 'full') ?? self::fail('template'));
        $this->em->flush();
        $this->executor->run((int) $run->jobId());

        self::assertSame(JobStatus::Failed, $this->jobRepository->find((int) $run->jobId())?->status());
        self::assertSame(ScheduleOutcome::Claimed, $run->outcome());
        self::assertSame('failed', $this->scheduleService->result($run));
    }

    public function testABusyInstanceIsSkippedWithoutAJob(): void
    {
        $instance = $this->running('main');
        $this->schedule('nightly', $instance);
        $busy = $this->job('redeploy', $instance, ['template' => 'full']);
        $this->clock->modify('2026-10-21 03:00:10 UTC');

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame(ScheduleOutcome::Skipped, $created[0]->outcome());
        self::assertNull($created[0]->jobId());
        self::assertSame('skipped', $this->scheduleService->result($created[0]));
        self::assertStringContainsString('queued or running', (string) $created[0]->detail());
        self::assertSame([$busy->id()], array_map(static fn (Job $j): ?int => $j->id(), $this->redeployJobs()));
    }

    public function testADestroyedInstanceIsSkippedWithAReasonAndWithoutAJob(): void
    {
        $instance = $this->running('main');
        $this->schedule('nightly', $instance);
        $this->em->remove($instance);
        $this->em->flush();
        $this->clock->modify('2026-10-21 03:00:10 UTC');

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame(ScheduleOutcome::Skipped, $created[0]->outcome());
        self::assertSame('skipped', $this->scheduleService->result($created[0]));
        self::assertStringContainsString('does not exist yet', (string) $created[0]->detail());
        self::assertNull($created[0]->jobId());
        self::assertSame([], $this->redeployJobs());
        // The occurrence is consumed: the next tick does not retry it.
        $this->clock->modify('2026-10-21 03:00:40 UTC');
        self::assertSame([], $this->ticker->tick());
    }

    public function testAPausedScheduleQueuesNothing(): void
    {
        $instance = $this->running('main');
        $this->schedule('nightly', $instance);
        $this->scheduleService->pause('nightly', 'admin');

        $this->clock->modify('2026-10-21 03:00:10 UTC');

        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->redeployJobs());
        self::assertSame([], $this->scheduleService->history('nightly'));
    }

    public function testTwoSchedulesOnTwoInstancesEachQueueTheirOwnRedeploy(): void
    {
        $blog = $this->running('blog');
        $shop = $this->running('shop');
        $this->schedule('nightly-blog', $blog);
        $this->schedule('nightly-shop', $shop, '30 3 * * *');

        $this->clock->modify('2026-10-21 03:00:10 UTC');
        $first = $this->ticker->tick();
        self::assertCount(1, $first);
        self::assertSame('nightly-blog', $first[0]->schedule()->name());

        $this->clock->modify('2026-10-21 03:30:10 UTC');
        $second = $this->ticker->tick();
        self::assertCount(1, $second);
        self::assertSame('nightly-shop', $second[0]->schedule()->name());

        $byInstance = [];
        foreach ($this->redeployJobs() as $job) {
            $byInstance[(string) $job->instanceId()] = $job->requestedBy();
        }
        self::assertSame([$blog->id() => 'schedule:nightly-blog', $shop->id() => 'schedule:nightly-shop'], $byInstance);
        self::assertCount(1, $this->scheduleService->history('nightly-blog'));
        self::assertCount(1, $this->scheduleService->history('nightly-shop'));
    }

    public function testTwoSchedulesDueTogetherQueueOneJobEachOnTheirOwnInstance(): void
    {
        $blog = $this->running('blog');
        $shop = $this->running('shop');
        $this->schedule('nightly-blog', $blog);
        $this->schedule('nightly-shop', $shop);

        $this->clock->modify('2026-10-21 03:00:10 UTC');
        $created = $this->ticker->tick();

        self::assertCount(2, $created);
        self::assertCount(2, $this->redeployJobs());
        self::assertSame([ScheduleOutcome::Claimed, ScheduleOutcome::Claimed], array_map(static fn ($run): ScheduleOutcome => $run->outcome(), $created));
    }
}
