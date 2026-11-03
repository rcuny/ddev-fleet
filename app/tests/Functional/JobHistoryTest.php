<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class JobHistoryTest extends AdminTestCase
{
    private function add(string $type, JobTrigger $trigger, ?JobStatus $status, string $project = 'acme'): Job
    {
        $em = $this->em();
        $job = new Job($type, $project.'--i1', $project, $trigger, 'admin');
        if (null !== $status) {
            $job->markRunning(new \DateTimeImmutable());
            if ($status->isFinal()) {
                $job->finish($status, JobStatus::Succeeded === $status ? 0 : 1, JobStatus::Failed === $status ? 'It broke.' : null, new \DateTimeImmutable());
            }
        }
        new JobRepository($em)->add($job);

        return $job;
    }

    /**
     * @return list<string> job numbers in the order shown
     */
    private function shown(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid=job-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-job'));
    }

    public function testAdminOnly(): void
    {
        $this->as('member');
        $this->open('/admin/jobs');
        self::assertResponseStatusCodeSame(403);

        $this->as('none');
        $this->open('/admin/jobs');
        self::assertResponseStatusCodeSame(401);

        $this->as('admin');
        $crawler = $this->open('/admin/jobs');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function testFiltersByStatusTriggerAndType(): void
    {
        $a = $this->add('start', JobTrigger::Ui, JobStatus::Succeeded);
        $b = $this->add('deploy', JobTrigger::Cli, JobStatus::Failed);
        $c = $this->add('start', JobTrigger::Cli, JobStatus::Failed);

        self::assertSame([(string) $c->id(), (string) $b->id(), (string) $a->id()], $this->shown($this->open('/admin/jobs')));
        self::assertSame([(string) $c->id(), (string) $b->id()], $this->shown($this->open('/admin/jobs?status=failed')));
        self::assertSame([(string) $c->id(), (string) $b->id()], $this->shown($this->open('/admin/jobs?trigger=cli')));
        self::assertSame([(string) $c->id(), (string) $a->id()], $this->shown($this->open('/admin/jobs?type=start')));
        self::assertSame([(string) $c->id()], $this->shown($this->open('/admin/jobs?type=start&trigger=cli&status=failed')));
        self::assertSame([], $this->shown($this->open('/admin/jobs?status=nonsense&type=%00')));
    }

    public function testFiltersByProjectInstanceAndSearch(): void
    {
        $a = $this->add('start', JobTrigger::Ui, JobStatus::Succeeded, 'acme');
        $b = $this->add('start', JobTrigger::Ui, JobStatus::Succeeded, 'demo');

        self::assertSame([(string) $b->id()], $this->shown($this->open('/admin/jobs?project=demo')));
        self::assertSame([(string) $a->id()], $this->shown($this->open('/admin/jobs?instance=acme--i1')));
        self::assertSame([(string) $a->id()], $this->shown($this->open('/admin/jobs?q=acme')));
    }

    public function testShowsTriggerUiCliAndOutcome(): void
    {
        $this->add('start', JobTrigger::Ui, JobStatus::Succeeded);
        $this->add('stop', JobTrigger::Cli, JobStatus::Failed);

        $crawler = $this->open('/admin/jobs');
        $rows = $crawler->filter('[data-testid=job-row]');

        self::assertCount(2, $rows);
        self::assertStringContainsString('cli', $rows->eq(0)->text());
        self::assertStringContainsString('failed', $rows->eq(0)->text());
        self::assertStringContainsString('ui', $rows->eq(1)->text());
        self::assertStringContainsString('succeeded', $rows->eq(1)->text());
        self::assertCount(1, $rows->eq(0)->filter('a[href^="/jobs/"]'));
    }

    public function testPagination(): void
    {
        for ($i = 0; $i < 51; ++$i) {
            $this->add('start', JobTrigger::Ui, JobStatus::Succeeded);
        }

        $first = $this->open('/admin/jobs');
        self::assertCount(50, $first->filter('[data-testid=job-row]'));
        self::assertCount(1, $first->filter('[data-testid=jobs-next]'));

        $second = $this->open('/admin/jobs?page=2');
        self::assertCount(1, $second->filter('[data-testid=job-row]'));
        self::assertCount(0, $second->filter('[data-testid=jobs-next]'));
        self::assertCount(1, $second->filter('[data-testid=jobs-previous]'));

        $this->open('/admin/jobs?page=abc');
        self::assertResponseStatusCodeSame(200);
    }

    public function testEmptyState(): void
    {
        $crawler = $this->open('/admin/jobs');

        self::assertCount(1, $crawler->filter('[data-testid=jobs-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=job-row]'));
    }
}
