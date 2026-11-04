<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class JobControllerTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('develop', 'full');
            new ProjectRepository($em)->save($project);
            new TemplateRepository($em)->save(new Template($project, 'full'));
            $instance = new Instance($project, 'i1', 'full', 'develop', 'admin');
            $instance->changeState(InstanceState::Stopped);
            new InstanceRepository($em)->createMany([$instance]);
        }
    }

    private function job(string $project, string $type = 'start', ?JobStatus $status = null, ?int $retryOf = null): Job
    {
        $em = $this->em();
        $job = new Job($type, $project.'--i1', $project, JobTrigger::Ui, 'admin', [], null, false, $retryOf);
        if (null !== $status) {
            $job->markRunning(new \DateTimeImmutable());
            if ($status->isFinal()) {
                $job->finish($status, JobStatus::Succeeded === $status ? 0 : 1, JobStatus::Failed === $status ? 'It broke.' : null, new \DateTimeImmutable());
            }
        }
        new JobRepository($em)->add($job);

        return $job;
    }

    private function writeLog(Job $job, string $text): void
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $path = $paths->jobLog((string) $job->id(), $job->instanceId());
        @mkdir(\dirname($path), 0o750, true);
        file_put_contents($path, $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function logJson(Job $job, string $from = '0'): array
    {
        $this->client->request('GET', self::BASE.'/jobs/'.$job->id().'/log?from='.$from, server: $this->browserServer());
        $decoded = json_decode($this->body(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function jobCount(): int
    {
        return (int) $this->em()->createQuery(\sprintf('SELECT COUNT(j.id) FROM %s j', Job::class))->getSingleScalarResult();
    }

    public function testAdminSeesAnyJob(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Succeeded);

        $crawler = $this->open('/jobs/'.$job->id());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('[data-testid=job-log]'));
        self::assertCount(1, $crawler->filter('pre[data-testid=job-log][role=log][aria-live=off][aria-label]'));
        self::assertStringContainsString('acme--i1', $this->body());
    }

    public function testMemberSeesJobsOfOwnProjectsOnly(): void
    {
        $own = $this->job('demo');
        $foreign = $this->job('acme');
        $this->as('member');

        $this->open('/jobs/'.$own->id());
        self::assertResponseStatusCodeSame(200);

        $this->open('/jobs/'.$foreign->id());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', self::BASE.'/jobs/'.$foreign->id().'/log', server: $this->browserServer());
        self::assertResponseStatusCodeSame(404);
    }

    public function testLogEndpointReturnsChunkAndNextOffset(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Running);
        $this->writeLog($job, "first line\nsecond line\npartial");

        $one = $this->logJson($job);

        self::assertResponseStatusCodeSame(200);
        self::assertSame("first line\nsecond line\n", $one['text']);
        self::assertSame(23, $one['next']);
        self::assertFalse($one['done']);
        self::assertSame('running', $one['status']);
        self::assertSame(\strlen("first line\nsecond line\npartial"), $one['size']);

        $two = $this->logJson($job, (string) $one['next']);
        self::assertSame('', $two['text']);
        self::assertSame(23, $two['next']);
    }

    public function testLogWorksForAFinishedJobAndIsNoStore(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Succeeded);
        $this->writeLog($job, "done\nlast words");

        $json = $this->logJson($job);

        self::assertSame("done\nlast words", $json['text']);
        self::assertTrue($json['done']);
        self::assertSame('succeeded', $json['status']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badOffsets(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'float' => ['1.5'];
        yield 'empty' => [''];
        yield 'huge' => [str_repeat('9', 40)];
    }

    #[DataProvider('badOffsets')]
    public function testLogOffsetMustBeANonNegativeInteger(string $from): void
    {
        $job = $this->job('acme');

        $this->logJson($job, $from);

        self::assertResponseStatusCodeSame(400);
    }

    public function testLogNeverReadsAnotherJobsFile(): void
    {
        $first = $this->job('acme', 'start', JobStatus::Succeeded);
        $second = $this->job('acme');
        $this->writeLog($first, "secret of the first\n");

        self::assertSame('', $this->logJson($second)['text']);

        $this->client->request('GET', self::BASE.'/jobs/'.$second->id().'/../'.$first->id().'/log', server: $this->browserServer());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRetryNeedsCsrf(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Failed);

        $this->post('/jobs/'.$job->id().'/retry', [], [], 'cross-site');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->jobCount());
    }

    public function testMemberCannotRetryADestroy(): void
    {
        $job = $this->job('demo', 'destroy', JobStatus::Failed);
        $this->as('member');

        $this->post('/jobs/'.$job->id().'/retry', []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->jobCount());
    }

    public function testRetryOfFailedDeployQueuesRedeploy(): void
    {
        $job = $this->job('acme', 'deploy', JobStatus::Failed);

        $this->post('/jobs/'.$job->id().'/retry', []);

        self::assertResponseStatusCodeSame(303);
        $retry = $this->em()->createQuery(\sprintf('SELECT j FROM %s j WHERE j.retryOf = :id', Job::class))->setParameter('id', $job->id())->getSingleResult();
        self::assertInstanceOf(Job::class, $retry);
        self::assertSame('redeploy', $retry->type());
        self::assertSame('/jobs/'.$retry->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testRetryOfARunningJobIsRefused(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Running);

        $this->post('/jobs/'.$job->id().'/retry', []);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/jobs/'.$job->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        self::assertSame(1, $this->jobCount());
    }

    public function testRetryButtonOnlyOnFailedJobs(): void
    {
        $failed = $this->job('acme', 'start', JobStatus::Failed);
        $interrupted = $this->job('acme', 'stop', JobStatus::Interrupted);
        $done = $this->job('acme', 'start', JobStatus::Succeeded);
        $running = $this->job('acme', 'stop', JobStatus::Running);

        self::assertCount(1, $this->open('/jobs/'.$failed->id())->filter('[data-testid=job-retry]'));
        self::assertCount(1, $this->open('/jobs/'.$interrupted->id())->filter('[data-testid=job-retry]'));
        self::assertCount(0, $this->open('/jobs/'.$done->id())->filter('[data-testid=job-retry]'));
        self::assertCount(0, $this->open('/jobs/'.$running->id())->filter('[data-testid=job-retry]'));
    }

    public function testMercureCookieIsScopedToTheJobTopic(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Running);

        $this->open('/jobs/'.$job->id());

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if (str_contains($candidate->getName(), 'mercure')) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie);
        $payload = json_decode((string) base64_decode(strtr(explode('.', (string) $cookie->getValue())[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"\/jobs\/'.$job->id().'"', $json);
        self::assertStringNotContainsString('"\/instances"', $json);
        self::assertStringNotContainsString('"*"', $json);
    }

    public function testInstancePageShowsTheLiveLogOfItsActiveJob(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Running);

        $crawler = $this->open('/instances/acme--i1');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=active-job] [data-testid=job-log]'));
        self::assertStringContainsString('/jobs/'.$job->id().'/log', $this->body());
    }

    public function testFinishedJobShowsItsFactsAndHasNoRetry(): void
    {
        $job = $this->job('acme', 'deploy', JobStatus::Succeeded);
        $id = $job->id();

        $crawler = $this->open('/jobs/'.$id);

        self::assertSame('Job #'.$id, trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('deploy · acme--i1', $crawler->text());
        $meta = $crawler->filter('[data-testid=job-meta]');
        self::assertSame('succeeded', $meta->filter('[data-testid=job-status]')->attr('data-status'));
        self::assertStringContainsString('ui', $meta->text());
        self::assertStringContainsString('Requested by', $meta->text());
        self::assertSame('/instances/acme--i1', $meta->filter('a')->attr('href'));
        foreach (['Queued', 'Started', 'Finished'] as $label) {
            self::assertMatchesRegularExpression('/'.$label.'\s*\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC/', $meta->text(), $label);
        }
        self::assertStringNotContainsString('Not yet', $meta->text());
        foreach (['job-error', 'retry-of', 'job-retry'] as $absent) {
            self::assertCount(0, $crawler->filter('[data-testid='.$absent.']'), $absent);
        }
        self::assertSame('Log of job #'.$id, $crawler->filter('[data-testid=job-log]')->attr('aria-label'));
    }

    public function testFailedJobShowsItsErrorAndRetryQueuesALinkedJob(): void
    {
        $failed = $this->job('acme', 'start', JobStatus::Failed);
        $failedId = $failed->id();

        $crawler = $this->open('/jobs/'.$failedId);
        self::assertSame('failed', $crawler->filter('[data-testid=job-meta] [data-testid=job-status]')->attr('data-status'));
        self::assertSame('It broke.', trim($crawler->filter('[data-testid=job-error]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=job-retry]'));

        $this->post('/jobs/'.$failedId.'/retry', []);
        self::assertResponseStatusCodeSame(303);
        $retry = $this->em()->createQuery(\sprintf('SELECT j FROM %s j WHERE j.retryOf = :id', Job::class))->setParameter('id', $failedId)->getSingleResult();
        self::assertInstanceOf(Job::class, $retry);
        self::assertSame('start', $retry->type());
        self::assertSame(2, $this->jobCount());

        $page = $this->open('/jobs/'.$retry->id());
        self::assertSame('#'.$failedId, trim($page->filter('[data-testid=retry-of]')->text()));
        self::assertSame('/jobs/'.$failedId, $page->filter('[data-testid=retry-of]')->attr('href'));
        self::assertStringContainsString('ui', $page->filter('[data-testid=job-meta]')->text());
        self::assertSame('failed', $this->open('/jobs/'.$failedId)->filter('[data-testid=job-status]')->attr('data-status'));
        self::assertCount(1, $this->open('/jobs/'.$failedId)->filter('[data-testid=job-retry]'));
    }

    public function testRetryIsRefusedWithTheReasonWhileTheInstanceHasAJobActive(): void
    {
        $failed = $this->job('acme', 'start', JobStatus::Failed);
        $this->job('acme', 'stop', JobStatus::Running);

        $this->post('/jobs/'.$failed->id().'/retry', []);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/jobs/'.$failed->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $this->client->followRedirect();
        $flashes = $this->client->getCrawler()->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('Busy: acme--i1 has a job queued or running. Nothing was started.', $flashes);
        self::assertSame(2, $this->jobCount());
    }

    /**
     * @return iterable<string, array{?JobStatus}>
     */
    public static function notRetryableStatuses(): iterable
    {
        yield 'succeeded' => [JobStatus::Succeeded];
        yield 'running' => [JobStatus::Running];
        yield 'queued' => [null];
    }

    #[DataProvider('notRetryableStatuses')]
    public function testAJobThatIsNotFailedOrInterruptedCannotBeRetried(?JobStatus $status): void
    {
        $job = $this->job('acme', 'start', $status);

        $this->post('/jobs/'.$job->id().'/retry', []);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/jobs/'.$job->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $this->client->followRedirect();
        self::assertStringContainsString('Only a failed or interrupted job can be retried.', $this->client->getCrawler()->filter('[data-testid=flashes]')->text());
        self::assertSame(1, $this->jobCount());
    }

    public function testLogEndpointAnswersInPiecesAndHasTheDocumentedKeys(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Succeeded);
        $this->writeLog($job, "fake line 1 of 5\nfake line 2 of 5\nfake line 5 of 5\n");

        $all = $this->logJson($job);
        $keys = array_keys($all);
        sort($keys);
        self::assertSame(['done', 'next', 'size', 'status', 'text'], $keys);
        self::assertSame($all['size'], $all['next']);
        self::assertStringContainsString('fake line 1 of 5', $all['text']);
        self::assertStringContainsString('fake line 5 of 5', $all['text']);

        $size = (int) $all['size'];
        $tail = $this->logJson($job, (string) ($size - 10));
        self::assertStringEndsWith($tail['text'], $all['text']);
        self::assertLessThanOrEqual(10, \strlen($tail['text']));

        foreach ([$size, $size + 500] as $from) {
            $end = $this->logJson($job, (string) $from);
            self::assertSame('', $end['text']);
            self::assertSame($size, $end['next']);
            self::assertTrue($end['done']);
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'unknown job' => ['GET', '/jobs/999999999', 404];
        yield 'text number' => ['GET', '/jobs/abc', 404];
        yield 'hex number' => ['GET', '/jobs/0x10', 404];
        yield 'unknown job log' => ['GET', '/jobs/999999999/log', 404];
        yield 'unknown job retry' => ['POST', '/jobs/999999999/retry', 404];
        yield 'retry by GET' => ['GET', '/jobs/{id}/retry', 405];
        yield 'POST on the page' => ['POST', '/jobs/{id}', 405];
    }

    #[DataProvider('refusedRequests')]
    public function testUnknownJobsBadNumbersAndWrongMethodsAreRefused(string $method, string $path, int $status): void
    {
        $job = $this->job('acme', 'start', JobStatus::Succeeded);

        $this->client->request($method, self::BASE.str_replace('{id}', (string) $job->id(), $path), ['_token' => 'csrf-token'], server: $this->browserServer());

        self::assertResponseStatusCodeSame($status);
    }

    public function testRetryWithoutOrWithAWrongTokenIsForbidden(): void
    {
        $job = $this->job('acme', 'start', JobStatus::Failed);

        $this->client->request('POST', self::BASE.'/jobs/'.$job->id().'/retry', server: $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::BASE.'/jobs/'.$job->id().'/retry', ['_token' => 'wrong'], server: $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->jobCount());
    }

    public function testMemberCanRetryTheirOwnFailedJobButNotAForeignOne(): void
    {
        $own = $this->job('demo', 'start', JobStatus::Failed);
        $foreign = $this->job('acme', 'start', JobStatus::Failed);
        $this->as('member');

        $this->logJson($own);
        self::assertResponseStatusCodeSame(200);
        $page = $this->open('/jobs/'.$own->id());
        self::assertSame('It broke.', trim($page->filter('[data-testid=job-error]')->text()));
        self::assertCount(1, $page->filter('[data-testid=job-retry]'));

        $this->post('/jobs/'.$foreign->id().'/retry', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, $this->jobCount());

        $this->post('/jobs/'.$own->id().'/retry', []);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(3, $this->jobCount());
        $location = (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH);
        $retryPage = $this->open($location);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('#'.$own->id(), trim($retryPage->filter('[data-testid=retry-of]')->text()));
    }
}
