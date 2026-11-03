<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Asset;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `fleet asset:snapshot`: the same service and job as the page (dev and test run a queued job at once, with the recording
 * runner), the table and JSON outputs and the exit codes.
 */
final class AssetSnapshotCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $project = self::getContainer()->get(ProjectService::class)->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $instances = [];
        foreach ([InstanceState::Running, InstanceState::Stopped] as $i => $state) {
            $instance = new Instance($project, 'i'.($i + 1), 'full', 'develop', 'admin');
            $instance->changeState($state);
            $instances[] = $instance;
        }
        new InstanceRepository($this->entityManager())->createMany($instances);
        mkdir(self::getContainer()->get(FleetPaths::class)->instanceDir('blog--i1'), 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testItQueuesAJobAndTheDumpAppears(): void
    {
        $tester = $this->cli('fleet:asset:snapshot', ['instance-id' => 'blog--i1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/Snapshot of blog--i1 queued as job \d+/', $this->squash($tester->getDisplay()));
        $dumps = glob(self::getContainer()->get(FleetPaths::class)->assetsDir('blog').'/dumps/default-blog--i1-*.sql.gz') ?: [];
        self::assertCount(1, $dumps);
        $jobs = new JobRepository($this->entityManager());
        $job = $jobs->forInstance('blog--i1')[0];
        self::assertSame('snapshot', $job->type());
        self::assertSame(JobTrigger::Cli, $job->trigger());
        self::assertSame('cli', $job->requestedBy());
        self::assertSame(JobStatus::Succeeded, $job->status());
    }

    public function testJsonOutput(): void
    {
        $tester = $this->cli('fleet:asset:snapshot', ['instance-id' => 'blog--i1', '--format' => 'json']);

        self::assertSame(0, $tester->getStatusCode());
        $document = $this->json($tester);
        self::assertSame('blog--i1', $document['instance']);
        self::assertSame('blog', $document['project']);
        self::assertIsInt($document['job']);
    }

    public function testAStoppedInstanceIsRefusedWithExitCodeTwo(): void
    {
        $tester = $this->cli('fleet:asset:snapshot', ['instance-id' => 'blog--i2']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('not running (stopped)', $tester->getErrorOutput());
    }

    public function testAnUnknownInstanceIsExitCodeThreeAndJsonCarriesTheError(): void
    {
        $tester = $this->cli('fleet:asset:snapshot', ['instance-id' => 'blog--nope', '--json' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', (string) ($this->json($tester)['error'] ?? ''));
    }

    public function testABusyInstanceIsRefused(): void
    {
        $em = $this->entityManager();
        $job = new \App\Domain\Job\Job('start', 'blog--i1', 'blog', JobTrigger::Ui, 'admin', [], null, false, null, new \DateTimeImmutable());
        new JobRepository($em)->add($job);

        $tester = $this->cli('fleet:asset:snapshot', ['instance-id' => 'blog--i1']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Busy: blog--i1 has a job queued or running', $tester->getErrorOutput());
    }
}
