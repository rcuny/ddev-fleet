<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobExecutor;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobService;
use App\Fleet\Jobs\JobType;
use App\Fleet\Jobs\JobTypeRegistry;
use App\Fleet\Jobs\LogReader;
use App\Fleet\Jobs\NoLogMasker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Mercure\HubInterface;

/**
 * The job core wired by hand over a migrated database: a recording bus, a mock clock and a hub that remembers its updates.
 */
abstract class JobTestCase extends KernelTestCase
{
    use FleetDatabase;

    protected EntityManagerInterface $em;
    protected FleetPaths $paths;
    protected JobRepository $jobs;
    protected InstanceRepository $instances;
    protected RecordingMessageBus $bus;
    protected MockClock $clock;
    protected LockFactory $locks;
    protected HubInterface $hub;
    protected JobFeed $feed;
    protected LogReader $reader;
    protected JobService $service;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->jobs = new JobRepository($this->em);
        $this->instances = new InstanceRepository($this->em);
        $this->bus = new RecordingMessageBus();
        $this->clock = new MockClock('2026-10-20 09:00:00 UTC');
        $this->locks = new LockFactory(new FlockStore($this->paths->locksDir()));
        $this->hub = $this->createStub(HubInterface::class);
        $this->feed = new JobFeed($this->hub, new NullLogger());
        $this->reader = new LogReader($this->paths);
        $this->service = new JobService($this->jobs, $this->bus, $this->feed, $this->reader, $this->clock, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param list<JobType> $types
     */
    protected function executor(array $types): JobExecutor
    {
        return new JobExecutor(
            $this->jobs,
            new JobTypeRegistry($types),
            $this->service,
            $this->locks,
            $this->bus,
            $this->paths,
            new NoLogMasker(),
            $this->feed,
            $this->clock,
            new NullLogger(),
            0,
        );
    }

    protected function queuedJob(string $type = 'deploy', ?string $instanceId = 'acme--main', ?string $batchId = null, bool $sequential = false): Job
    {
        $job = new Job($type, $instanceId, 'acme', JobTrigger::Ui, 'admin', [], $batchId, $sequential, null, $this->clock->now());
        $this->jobs->add($job);

        return $job;
    }

    /**
     * @param \Closure(Job, JobLog): void $body
     */
    protected function jobType(string $name, \Closure $body): CountingJobType
    {
        return new CountingJobType($name, $body);
    }
}
