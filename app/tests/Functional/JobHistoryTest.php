<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testEveryFilterAloneAndTogetherNewestFirst(): void
    {
        $deploy = $this->add('deploy', JobTrigger::Ui, JobStatus::Succeeded);
        $ok = $this->add('start', JobTrigger::Cli, JobStatus::Succeeded);
        $failed = $this->add('start', JobTrigger::Cli, JobStatus::Failed);
        $this->add('deploy', JobTrigger::Ui, JobStatus::Succeeded, 'demo');
        [$f, $o, $d] = [(string) $failed->id(), (string) $ok->id(), (string) $deploy->id()];

        self::assertSame([$f, $o, $d], $this->shown($this->open('/admin/jobs?project=acme')));
        self::assertSame([$f, $o, $d], $this->shown($this->open('/admin/jobs?instance=acme--i1')));
        self::assertSame([$f, $o, $d], $this->shown($this->open('/admin/jobs?q=acme--i1')));
        self::assertContains($d, $this->shown($this->open('/admin/jobs?q='.$d)));
        self::assertSame([$f], $this->shown($this->open('/admin/jobs?project=acme&status=failed')));
        self::assertSame([$o, $d], $this->shown($this->open('/admin/jobs?project=acme&status=succeeded')));
        self::assertSame([$d], $this->shown($this->open('/admin/jobs?project=acme&trigger=ui')));
        self::assertSame([$f, $o], $this->shown($this->open('/admin/jobs?project=acme&trigger=cli')));
        self::assertSame([$o], $this->shown($this->open('/admin/jobs?project=acme&trigger=cli&status=succeeded')));
        self::assertSame([$d], $this->shown($this->open('/admin/jobs?project=acme&type=deploy')));
        self::assertSame([$d], $this->shown($this->open('/admin/jobs?instance=acme--i1&type=deploy&trigger=ui&status=succeeded')));
        self::assertSame([], $this->shown($this->open('/admin/jobs?instance=acme--i1&type=deploy&trigger=cli')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filtersMatchingNothing(): iterable
    {
        foreach (['status=queued', 'status=running', 'status=interrupted', 'trigger=webhook', 'trigger=schedule', 'type=stop', 'type=redeploy', 'type=destroy'] as $query) {
            yield 'no such job: '.$query => [$query];
        }
        foreach (['status=bogus', 'trigger=bogus', 'type=bogus', 'type=fake', 'type=DEPLOY', 'status=Failed', 'project=ACME', 'instance=acme--i1-x'] as $query) {
            yield 'names nothing: '.$query => [$query];
        }
    }

    #[DataProvider('filtersMatchingNothing')]
    public function testAFilterThatNamesNothingOrMatchesNoJobShowsNoRow(string $query): void
    {
        $this->add('start', JobTrigger::Cli, JobStatus::Failed);
        $this->add('deploy', JobTrigger::Ui, JobStatus::Succeeded);

        $crawler = $this->open('/admin/jobs?project=acme&'.$query);

        self::assertSame([], $this->shown($crawler));
        self::assertCount(1, $crawler->filter('[data-testid=jobs-empty]'));
    }

    public function testSearchIsTrimmedAndNotCaseSensitive(): void
    {
        $a = $this->add('start', JobTrigger::Ui, JobStatus::Succeeded);

        self::assertSame([(string) $a->id()], $this->shown($this->open('/admin/jobs?q='.rawurlencode('  ACME ').'')));
    }

    public function testSearchTreatsPercentAndUnderscoreAsPlainCharactersAndCutsAt100(): void
    {
        $a = $this->add('start', JobTrigger::Ui, JobStatus::Succeeded);

        foreach (['%', '_', 'acme%', 'acme_', 'a%e', 'acme--i_'] as $q) {
            self::assertSame([], $this->shown($this->open('/admin/jobs?q='.rawurlencode($q))), $q);
        }
        self::assertSame([], $this->shown($this->open('/admin/jobs?q='.rawurlencode('acme--i1'.str_repeat('z', 150)))));
        self::assertSame([(string) $a->id()], $this->shown($this->open('/admin/jobs?q=acme--i1')));
        // 100 characters are kept: the text is cut after them, so 100 chars of an id that is longer still ends there.
        $cut = $this->open('/admin/jobs?q='.rawurlencode(str_repeat('a', 100).'--i1'));
        self::assertSame([], $this->shown($cut));
        self::assertSame(str_repeat('a', 100), $cut->filter('input[name=q]')->attr('value'));
    }

    public function testTheFormKeepsItsChoicesAndTheHiddenProjectAndInstance(): void
    {
        $this->add('start', JobTrigger::Cli, JobStatus::Failed);

        $crawler = $this->open('/admin/jobs?project=acme&instance=acme--i1');
        $options = static fn (string $name): array => $crawler->filter('[data-testid=job-filters] select[name='.$name.'] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertSame(['Any status', 'Queued', 'Running', 'Succeeded', 'Failed', 'Interrupted'], $options('status'));
        self::assertSame(['Any trigger', 'Ui', 'Cli', 'Webhook', 'Schedule', 'Api'], $options('trigger'));
        self::assertSame(['Any type', 'Deploy', 'Start', 'Stop', 'Redeploy', 'Destroy'], $options('type'));
        self::assertSame('acme', $crawler->filter('input[type=hidden][name=project]')->attr('value'));
        self::assertSame('acme--i1', $crawler->filter('input[type=hidden][name=instance]')->attr('value'));

        $result = $this->submit($crawler, 'Filter', ['status' => 'failed', 'trigger' => 'cli', 'q' => 'acme--i1']);
        $query = (string) parse_url($this->client->getRequest()->getUri(), \PHP_URL_QUERY);
        parse_str($query, $sent);
        self::assertEquals(['status' => 'failed', 'trigger' => 'cli', 'type' => '', 'q' => 'acme--i1', 'project' => 'acme', 'instance' => 'acme--i1'], $sent);
        self::assertCount(1, $result->filter('[data-testid=job-row]'));
        self::assertSame('failed', $result->filter('[data-testid=job-row] [data-testid=job-status]')->attr('data-status'));
        self::assertSame('failed', $result->filter('select[name=status] option[selected]')->attr('value'));
        self::assertSame('cli', $result->filter('select[name=trigger] option[selected]')->attr('value'));
        self::assertSame('acme--i1', $result->filter('input[name=q]')->attr('value'));

        $empty = $this->submit($result, 'Filter', ['type' => 'deploy']);
        self::assertStringContainsString('No jobs', $empty->filter('[data-testid=jobs-empty]')->text());
        self::assertStringContainsString('Nothing has run yet, or no job matches the filters.', $empty->filter('[data-testid=jobs-empty]')->text());
        self::assertCount(0, $empty->filter('[data-testid=job-table]'));
    }

    public function testARowShowsTheFactsOfTheJobAndLinksToIt(): void
    {
        $job = $this->add('deploy', JobTrigger::Ui, JobStatus::Succeeded);
        $id = $job->id();

        $crawler = $this->open('/admin/jobs?project=acme');
        $row = $crawler->filter('[data-testid=job-row][data-job="'.$id.'"]');

        self::assertCount(1, $row);
        self::assertStringContainsString('#'.$id.' deploy', $row->text());
        self::assertStringContainsString('acme--i1', $row->text());
        self::assertStringContainsString('ui', $row->text());
        self::assertSame('succeeded', trim($row->filter('[data-testid=job-status]')->text()));
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $row->text());
        self::assertSame('/jobs/'.$id, $row->filter('a')->attr('href'));
        $this->client->click($row->filter('a')->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Job #'.$id, trim($this->client->getCrawler()->filter('h1')->text()));
    }

    public function testPagingOf50PerPageKeepsTheFiltersAndHandlesPagesPastTheEnd(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->add('deploy', JobTrigger::Ui, JobStatus::Succeeded);
        }
        for ($i = 0; $i < 31; ++$i) {
            $this->add('stop', JobTrigger::Ui, JobStatus::Succeeded);
        }

        $first = $this->open('/admin/jobs?project=acme');
        self::assertCount(50, $first->filter('[data-testid=job-row]'));
        self::assertCount(0, $first->filter('[data-testid=jobs-previous]'));
        self::assertStringContainsString('Page 1', $first->filter('nav[aria-label=Pages]')->text());

        $second = $this->client->click($first->filter('[data-testid=jobs-next]')->link());
        parse_str((string) parse_url($this->client->getRequest()->getUri(), \PHP_URL_QUERY), $sent);
        self::assertEquals(['project' => 'acme', 'page' => '2'], array_intersect_key($sent, ['project' => 1, 'page' => 1]));
        self::assertCount(1, $second->filter('[data-testid=job-row]'));
        self::assertCount(0, $second->filter('[data-testid=jobs-next]'));
        self::assertStringContainsString('Page 2', $second->filter('nav[aria-label=Pages]')->text());
        $back = $this->client->click($second->filter('[data-testid=jobs-previous]')->link());
        self::assertCount(50, $back->filter('[data-testid=job-row]'));

        self::assertCount(0, $this->open('/admin/jobs?project=acme&type=deploy')->filter('nav[aria-label=Pages]'));
        self::assertCount(20, $this->open('/admin/jobs?project=acme&type=deploy')->filter('[data-testid=job-row]'));
        $page1 = $this->shown($this->open('/admin/jobs?project=acme&status=succeeded'));
        $href = (string) $this->client->getCrawler()->filter('[data-testid=jobs-next]')->attr('href');
        self::assertStringContainsString('project=acme', $href);
        self::assertStringContainsString('status=succeeded', $href);
        $page2 = $this->shown($this->open('/admin/jobs?project=acme&status=succeeded&page=2'));
        self::assertCount(50, $page1);
        self::assertCount(1, $page2);
        self::assertSame([], array_intersect($page1, $page2));

        $past = $this->open('/admin/jobs?project=acme&page=3');
        self::assertSame([], $this->shown($past));
        self::assertStringContainsString('There are no jobs on this page.', $past->filter('[data-testid=jobs-empty]')->text());
        self::assertCount(1, $past->filter('[data-testid=jobs-previous]'));
        foreach (['abc', '0', '-2', '1.5', '1234567'] as $bad) {
            self::assertCount(50, $this->open('/admin/jobs?project=acme&page='.$bad)->filter('[data-testid=job-row]'), 'page='.$bad);
        }
    }
}
