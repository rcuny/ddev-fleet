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
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com');
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
}
