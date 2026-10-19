<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SecondWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobRepositoryTest extends KernelTestCase
{
    use FleetDatabase;
    use SecondWriter;

    private EntityManagerInterface $em;
    private JobRepository $jobs;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->jobs = new JobRepository($this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function job(string $type = 'deploy', ?string $instance = 'blog--main', ?string $batch = null, JobStatus $status = JobStatus::Queued, ?\DateTimeImmutable $finishedAt = null, JobTrigger $trigger = JobTrigger::Ui): Job
    {
        $job = new Job($type, $instance, null === $instance ? null : explode('--', $instance)[0], $trigger, 'admin', [], $batch);
        if (JobStatus::Running === $status) {
            $job->markRunning(new \DateTimeImmutable('2026-10-01 08:00:00'));
        } elseif ($status->isFinal()) {
            $job->finish($status, JobStatus::Succeeded === $status ? 0 : 1, null, $finishedAt ?? new \DateTimeImmutable('2026-10-01 08:10:00'));
        }
        $this->jobs->add($job);

        return $job;
    }

    public function testRoundTripsEveryField(): void
    {
        $created = new \DateTimeImmutable('2026-10-01 08:00:00');
        $job = new Job('deploy', 'blog--main', 'blog', JobTrigger::Cli, 'cli:alice', ['branch' => 'develop'], 'batch-1', true, 7, $created);
        $job->markRunning(new \DateTimeImmutable('2026-10-01 08:01:00'));
        $job->finish(JobStatus::Failed, 3, str_repeat('é', 600), new \DateTimeImmutable('2026-10-01 08:02:00'));
        $this->jobs->add($job);
        $this->em->clear();

        $found = $this->jobs->find($job->id() ?? self::fail()) ?? self::fail();
        self::assertSame('deploy', $found->type());
        self::assertSame('blog--main', $found->instanceId());
        self::assertSame('blog', $found->projectName());
        self::assertSame(JobStatus::Failed, $found->status());
        self::assertSame(JobTrigger::Cli, $found->trigger());
        self::assertSame('cli:alice', $found->requestedBy());
        self::assertSame(['branch' => 'develop'], $found->params());
        self::assertSame('batch-1', $found->batchId());
        self::assertTrue($found->isSequential());
        self::assertSame(7, $found->retryOf());
        self::assertSame(3, $found->exitCode());
        self::assertSame(Job::ERROR_MAX, mb_strlen((string) $found->error()));
        self::assertEquals($created, $found->createdAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 08:01:00'), $found->startedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 08:02:00'), $found->finishedAt());
    }

    public function testClaimWinsOnce(): void
    {
        $job = $this->job();
        $id = $job->id() ?? self::fail();
        $second = $this->secondEntityManager($this->em);
        $other = new JobRepository($second);
        $at = new \DateTimeImmutable('2026-10-01 09:00:00');

        $first = $this->jobs->claim($id, $at);
        $again = $other->claim($id, $at);

        self::assertTrue($first);
        self::assertFalse($again);
        self::assertSame(JobStatus::Running, $job->status(), 'the managed copy is refreshed');
        self::assertEquals($at, $job->startedAt());
        self::assertFalse($this->jobs->claim($id, $at));
        self::assertFalse($this->jobs->claim(9999, $at));
    }

    public function testHasEarlierActiveInBatch(): void
    {
        $finished = $this->job(batch: 'b1', status: JobStatus::Succeeded);
        $first = $this->job(batch: 'b1');
        $second = $this->job(batch: 'b1');
        $otherBatch = $this->job(batch: 'b2');
        $lone = $this->job();

        self::assertFalse($this->jobs->hasEarlierActiveInBatch($finished));
        self::assertFalse($this->jobs->hasEarlierActiveInBatch($first), 'a finished earlier job does not count');
        self::assertTrue($this->jobs->hasEarlierActiveInBatch($second), 'a queued earlier job counts');
        self::assertFalse($this->jobs->hasEarlierActiveInBatch($otherBatch));
        self::assertFalse($this->jobs->hasEarlierActiveInBatch($lone));

        $this->jobs->claim($first->id() ?? self::fail(), new \DateTimeImmutable());
        self::assertTrue($this->jobs->hasEarlierActiveInBatch($second), 'a running earlier job counts');

        $first->finish(JobStatus::Failed, 1, 'boom', new \DateTimeImmutable());
        $this->jobs->save($first);
        self::assertFalse($this->jobs->hasEarlierActiveInBatch($second), 'a failed member never blocks the batch');
    }

    public function testLatestPerInstance(): void
    {
        $this->job(instance: 'blog--main');
        $newest = $this->job(instance: 'blog--main', type: 'redeploy');
        $shop = $this->job(instance: 'shop--main');
        $this->job(instance: 'shop--other');
        $this->job(type: 'prune', instance: null);
        $this->em->clear();

        $latest = $this->jobs->latestPerInstance(['blog--main', 'shop--main', 'blog--none']);

        self::assertSame(['blog--main', 'shop--main'], array_keys($latest));
        self::assertSame($newest->id(), $latest['blog--main']->id());
        self::assertSame($shop->id(), $latest['shop--main']->id());
        self::assertSame([], $this->jobs->latestPerInstance([]));
    }

    public function testLatestPerInstanceHandlesMoreIdsThanSqliteBindsAtOnce(): void
    {
        // One real instance in each of the first, middle and last chunks (the repository chunks by 500, and the
        // fillers never match), so this fails when a chunk is skipped or overwritten, whatever the build's limit.
        $ids = array_map(static fn (int $i): string => 'none--'.$i, range(1, 1500));
        $ids[10] = 'blog--main';
        $ids[700] = 'shop--main';
        $ids[1400] = 'docs--main';
        $expected = [];
        foreach (['blog--main', 'shop--main', 'docs--main'] as $instance) {
            $this->job(instance: $instance);
            $expected[$instance] = $this->job(instance: $instance)->id();
        }
        $this->em->clear();

        $latest = $this->jobs->latestPerInstance(array_values($ids));

        self::assertEqualsCanonicalizing(array_keys($expected), array_keys($latest));
        foreach ($expected as $instance => $id) {
            self::assertSame($id, $latest[$instance]->id(), $instance);
        }
    }

    public function testSearchFiltersAndPages(): void
    {
        $this->job(instance: 'blog--main', status: JobStatus::Succeeded);
        $this->job(instance: 'blog--main', type: 'destroy', status: JobStatus::Failed, trigger: JobTrigger::Cli);
        $this->job(instance: 'shop--main');
        $this->job(type: 'prune', instance: null, status: JobStatus::Succeeded);
        for ($i = 0; $i < 5; ++$i) {
            $this->job(instance: 'shop--page');
        }

        $all = $this->jobs->search([], 1);
        self::assertSame(9, $all['total']);
        self::assertGreaterThan($all['rows'][1]->id(), $all['rows'][0]->id(), 'newest first');
        self::assertCount(4, $this->jobs->search([], 1, 4)['rows']);
        self::assertCount(1, $this->jobs->search([], 3, 4)['rows'], 'page 3 of 4 per page holds the ninth job');
        self::assertSame(9, $this->jobs->search([], 3, 4)['total']);
        self::assertSame(2, $this->jobs->search(['status' => JobStatus::Succeeded, 'type' => null], 1)['total']);
        self::assertSame(1, $this->jobs->search(['trigger' => JobTrigger::Cli], 1)['total']);
        self::assertSame(1, $this->jobs->search(['type' => 'destroy'], 1)['total']);
        self::assertSame(6, $this->jobs->search(['project' => 'shop'], 1)['total']);
        self::assertSame(0, $this->jobs->search(['project' => 'nope'], 1)['total']);
        self::assertSame(2, $this->jobs->search(['instance' => 'blog--main'], 1)['total']);
        self::assertSame(2, $this->jobs->search(['q' => 'blog'], 1)['total']);
        self::assertSame(1, $this->jobs->search(['q' => (string) $all['rows'][0]->id()], 1)['total']);
        self::assertSame(0, $this->jobs->search(['q' => '%'], 1)['total'], 'LIKE wildcards are literal');
        self::assertSame(1, $this->jobs->search(['status' => JobStatus::Failed, 'project' => 'blog'], 1)['total']);
    }

    public function testPruneKeepsActiveJobs(): void
    {
        $old = new \DateTimeImmutable('2026-07-01 00:00:00');
        $cut = new \DateTimeImmutable('2026-08-01 00:00:00');
        $oldDone = $this->job(status: JobStatus::Succeeded, finishedAt: $old);
        $oldFailed = $this->job(status: JobStatus::Failed, finishedAt: $old);
        $recent = $this->job(status: JobStatus::Succeeded, finishedAt: new \DateTimeImmutable('2026-09-01 00:00:00'));
        $queued = $this->job();
        $running = $this->job(status: JobStatus::Running);

        $pruned = $this->jobs->pruneFinishedBefore($cut);

        self::assertSame([$oldDone->id(), $oldFailed->id()], $pruned);
        $this->em->clear();
        self::assertNull($this->jobs->find($oldDone->id() ?? 0));
        self::assertNotNull($this->jobs->find($recent->id() ?? 0));
        self::assertNotNull($this->jobs->find($queued->id() ?? 0));
        self::assertNotNull($this->jobs->find($running->id() ?? 0));
        self::assertSame([$running->id()], array_map(static fn (Job $j): ?int => $j->id(), $this->jobs->running()));
        self::assertSame([], $this->jobs->pruneFinishedBefore($cut));
    }
}
