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
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class InstanceControllerTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $projects = new ProjectRepository($em);
        foreach (['acme', 'demo'] as $name) {
            $project = new Project($name, \sprintf('https://git.example.com/%s/blog.git', $name));
            $project->changeDefaults('develop', 'full');
            $projects->save($project);
            new TemplateRepository($em)->save(new Template($project, 'full'));
        }
    }

    /**
     * @param list<InstanceState> $states
     */
    private function seed(string $project, array $states): void
    {
        $em = $this->em();
        $owner = new ProjectRepository($em)->findByName($project);
        self::assertNotNull($owner);
        $instances = [];
        foreach ($states as $i => $state) {
            $instance = new Instance($owner, 'i'.($i + 1), 'full', 'develop', 'admin');
            $instance->changeState($state);
            $instances[] = $instance;
        }
        new InstanceRepository($em)->createMany($instances);
    }

    /**
     * @return list<string> the instance ids shown in the list
     */
    private function listed(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid=instance-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-instance'));
    }

    private function jobCount(): int
    {
        return (int) $this->em()->createQuery(\sprintf('SELECT COUNT(j.id) FROM %s j', Job::class))->getSingleScalarResult();
    }

    /**
     * A job on the instance: queued by default, running or ended when a status is given.
     */
    private function jobOn(string $instance, string $type = 'deploy', JobStatus $status = JobStatus::Queued): Job
    {
        $job = new Job($type, $instance, explode('--', $instance)[0], JobTrigger::Ui, 'admin', []);
        if (JobStatus::Queued !== $status) {
            $job->markRunning(new \DateTimeImmutable());
            if ($status->isFinal()) {
                $job->finish($status, JobStatus::Succeeded === $status ? 0 : 1, JobStatus::Failed === $status ? 'It broke.' : null, new \DateTimeImmutable());
            }
        }
        new JobRepository($this->em())->add($job);

        return $job;
    }

    /**
     * A POST without the CSRF token (the helper `post()` always sends one).
     *
     * @param array<string, mixed> $parameters
     */
    private function postWithoutToken(string $path, array $parameters = []): void
    {
        $this->client->request('POST', self::BASE.$path, $parameters, [], $this->browserServer());
    }

    private function flashes(): string
    {
        return $this->client->followRedirect()->filter('[data-testid=flashes]')->text();
    }

    public function testAdminListShowsEveryInstance(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->seed('demo', [InstanceState::Stopped]);

        $crawler = $this->open('/instances');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['acme--i1', 'demo--i1'], $this->listed($crawler));
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function testMemberListShowsOwnProjectsOnly(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->seed('demo', [InstanceState::Stopped]);
        $this->as('member');

        $crawler = $this->open('/instances');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['demo--i1'], $this->listed($crawler));
        self::assertStringNotContainsString('acme--i1', $this->body());
    }

    /**
     * @return array<string, mixed> the claims of the Mercure cookie of the last response
     */
    private function mercureClaims(): array
    {
        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if (str_contains($candidate->getName(), 'mercure')) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the page sets a Mercure cookie');
        $payload = json_decode((string) base64_decode(strtr(explode('.', (string) $cookie->getValue())[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function subscribeTopics(array $claims): array
    {
        $topics = [];
        foreach ($claims['authorization_details'] as $detail) {
            if (\in_array('subscribe', $detail['actions'], true)) {
                foreach ($detail['topics'] as $topic) {
                    $topics[] = $topic['match'];
                }
            }
        }
        sort($topics);

        return $topics;
    }

    public function testMemberCookieAuthorisesOnlyTheirProjectsInstanceTopic(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->seed('demo', [InstanceState::Running]);
        $this->as('member');

        $crawler = $this->open('/instances');

        $claims = $this->mercureClaims();
        self::assertSame(['/instances/demo'], $this->subscribeTopics($claims));
        self::assertSame('["\/instances\/demo"]', json_encode(json_decode((string) $crawler->filter('[data-controller=instance-list]')->attr('data-instance-list-topics-value'))));
        self::assertStringNotContainsString('acme', json_encode($claims, \JSON_THROW_ON_ERROR));
    }

    public function testAdminCookieCoversEveryProjectsInstanceTopic(): void
    {
        $this->open('/instances');

        self::assertSame(['/instances/acme', '/instances/demo'], $this->subscribeTopics($this->mercureClaims()));
    }

    public function testMemberNeverSeesAForeignInstanceByUrl(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->as('member');

        $this->open('/instances/acme--i1');
        self::assertResponseStatusCodeSame(404);

        $this->post('/instances/acme--i1/start', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->jobCount());
    }

    public function testAnonymousIs401(): void
    {
        $this->as('none');

        $this->open('/instances');

        self::assertResponseStatusCodeSame(401);
    }

    public function testFiltersApplyServerSide(): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Stopped]);
        $this->seed('demo', [InstanceState::Stopped]);

        self::assertSame(['acme--i2'], $this->listed($this->open('/instances?q=i2')));
        self::assertSame(['demo--i1'], $this->listed($this->open('/instances?project=demo')));
        self::assertSame(['acme--i2', 'demo--i1'], $this->listed($this->open('/instances?state=stopped')));
        self::assertSame([], $this->listed($this->open('/instances?state=nonsense')));
    }

    public function testOpenSiteLinkIsThePrimaryHostname(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $link = $this->open('/instances')->filter('[data-testid=instance-row] [data-testid=open-site]');

        self::assertCount(1, $link);
        self::assertSame('https://acme--i1.fleet.example.com', $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertStringContainsString('noopener', (string) $link->attr('rel'));
    }

    public function testDeployFormIsAdminOnly(): void
    {
        $this->as('member');
        $this->open('/instances/new');
        self::assertResponseStatusCodeSame(403);

        $this->as('admin');
        $crawler = $this->open('/instances/new');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=deploy-form]'));
        self::assertCount(1, $crawler->filter('h1'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function deploy(array $values): Crawler
    {
        $crawler = $this->open('/instances/new');

        return $this->submit($crawler, 'Deploy', $values);
    }

    public function testDeployRedirectsToTheJob(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[branch]' => 'feature/x', 'deploy[count]' => 1]);

        self::assertResponseStatusCodeSame(303);
        $job = $this->em()->createQuery(\sprintf('SELECT j FROM %s j', Job::class))->getSingleResult();
        self::assertInstanceOf(Job::class, $job);
        self::assertSame('deploy', $job->type());
        self::assertSame('/jobs/'.$job->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testBatchRedirectsToTheListWithAFlash(): void
    {
        $this->deploy(['deploy[project]' => 'acme', 'deploy[count]' => 2]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/instances', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('2 jobs queued', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame(2, $this->jobCount());
    }

    public function testDeployFieldErrorsRenderOnTheForm(): void
    {
        $crawler = $this->deploy(['deploy[project]' => 'acme', 'deploy[branch]' => '--upload-pack=x']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-testid=deploy-form] [aria-invalid=true]'));
        self::assertCount(1, $crawler->filter('[data-testid=deploy-form] input[name="deploy[branch]"][aria-invalid=true]'));
        self::assertSame(0, $this->jobCount());
    }

    #[DataProvider('operations')]
    public function testMemberCanStartStopAndRedeployOwnInstance(string $action, InstanceState $state): void
    {
        $this->seed('demo', [$state]);
        $this->as('member');

        $this->post('/instances/demo--i1/'.$action, []);

        self::assertResponseStatusCodeSame(303);
        $job = $this->em()->createQuery(\sprintf('SELECT j FROM %s j', Job::class))->getSingleResult();
        self::assertInstanceOf(Job::class, $job);
        self::assertSame($action, $job->type());
        self::assertSame('demo--i1', $job->instanceId());
        self::assertSame('/jobs/'.$job->id(), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    /**
     * @return iterable<string, array{string, InstanceState}>
     */
    public static function operations(): iterable
    {
        yield 'start' => ['start', InstanceState::Stopped];
        yield 'stop' => ['stop', InstanceState::Running];
        yield 'redeploy' => ['redeploy', InstanceState::Running];
    }

    public function testMemberCannotDestroy(): void
    {
        $this->seed('demo', [InstanceState::Running]);
        $this->as('member');

        $this->post('/instances/demo--i1/destroy', []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->jobCount());
    }

    public function testActionNeedsACsrfToken(): void
    {
        $this->seed('acme', [InstanceState::Stopped]);

        $this->post('/instances/acme--i1/start', [], [], 'cross-site');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->jobCount());
    }

    public function testBulkWithAForeignIdIsRefusedAsAWhole(): void
    {
        $this->seed('acme', [InstanceState::Stopped]);
        $this->seed('demo', [InstanceState::Stopped]);
        $this->as('member');

        $this->post('/instances/bulk', ['action' => 'start', 'ids' => ['demo--i1', 'acme--i1']]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->jobCount());
    }

    public function testBulkStartQueuesOneJobPerInstanceAndReturnsToTheList(): void
    {
        $this->seed('demo', [InstanceState::Stopped, InstanceState::Stopped]);
        $this->as('member');

        $this->post('/instances/bulk', ['action' => 'start', 'ids' => ['demo--i1', 'demo--i2']]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/instances', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        self::assertSame(2, $this->jobCount());
    }

    public function testBulkDestroyIsAdminOnly(): void
    {
        $this->seed('demo', [InstanceState::Running, InstanceState::Running]);
        $this->as('member');

        $this->post('/instances/bulk', ['action' => 'destroy', 'ids' => ['demo--i1', 'demo--i2']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->jobCount());

        $crawler = $this->open('/instances');
        self::assertCount(1, $crawler->filter('[data-testid=bulk-bar]'));
        self::assertStringNotContainsString('bulk-destroy', $this->body());
    }

    public function testEveryStateRendersABadge(): void
    {
        $this->seed('acme', InstanceState::cases());

        $crawler = $this->open('/instances');

        $badges = $crawler->filter('[data-testid=instance-state]')->each(static fn (Crawler $b): string => (string) $b->attr('data-state'));
        sort($badges);
        $expected = array_map(static fn (InstanceState $s): string => $s->value, InstanceState::cases());
        sort($expected);
        self::assertSame($expected, $badges);
        foreach ($crawler->filter('[data-testid=instance-state]') as $badge) {
            self::assertNotSame('', trim($badge->textContent));
        }
    }

    #[DataProvider('hostileIds')]
    public function testHostileIdSegmentsAre404(string $segment): void
    {
        $this->open('/instances/'.$segment);
        self::assertResponseStatusCodeSame(404);

        $this->post('/instances/'.$segment.'/start', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->jobCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIds(): iterable
    {
        yield 'dot dot' => ['..%2f..'];
        yield 'space' => ['a%20b'];
        yield 'too long' => [str_repeat('a', 30).'--'.str_repeat('b', 33)];
    }

    public function testDetailPageShowsTheInstance(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $crawler = $this->open('/instances/acme--i1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('acme--i1', trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('acme--i1.fleet.example.com', $this->body());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function filterCombinations(): iterable
    {
        // Seed: acme--i1 running, acme--i2 stopped, acme--i3 running, demo--i1 stopped.
        yield 'project' => ['project=acme', ['acme--i1', 'acme--i2', 'acme--i3']];
        yield 'project and state' => ['project=acme&state=running', ['acme--i1', 'acme--i3']];
        yield 'state stopped' => ['project=acme&state=stopped', ['acme--i2']];
        yield 'search' => ['q=--i', ['acme--i1', 'acme--i2', 'acme--i3', 'demo--i1']];
        yield 'search narrows' => ['q=i2', ['acme--i2']];
        yield 'search and state' => ['q=--i&state=running', ['acme--i1', 'acme--i3']];
        yield 'search, project and state' => ['q=--i&project=acme&state=stopped', ['acme--i2']];
        yield 'search and project' => ['q=i1&project=demo', ['demo--i1']];
        yield 'search misses the state' => ['q=i2&state=running', []];
        yield 'search misses the project' => ['q=acme&project=demo', []];
        yield 'search ignores case and spaces' => ['q=%20%20ACME--I3%20', ['acme--i3']];
        yield 'unknown state' => ['project=acme&state=bogus', []];
        yield 'state nobody has' => ['project=acme&state=failed', []];
        yield 'unknown project' => ['project=nothing', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('filterCombinations')]
    public function testEveryCombinationOfTheThreeFilters(string $query, array $expected): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Stopped, InstanceState::Running]);
        $this->seed('demo', [InstanceState::Stopped]);

        $crawler = $this->open('/instances?'.$query);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected, $this->listed($crawler));
        if ([] === $expected) {
            $empty = $crawler->filter('[data-testid=instances-empty]')->text();
            self::assertStringContainsString('No instance matches', $empty);
            self::assertStringContainsString('Change or clear the filters.', $empty);
        } else {
            self::assertCount(0, $crawler->filter('[data-testid=instances-empty]'));
        }
    }

    public function testTheFilterFormListsTheChoicesAndKeepsItsValues(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->seed('demo', [InstanceState::Stopped]);

        $crawler = $this->open('/instances?q=--i1&project=demo&state=stopped');

        $filters = $crawler->filter('[data-testid=instance-filters]');
        // Only projects that have an instance are offered.
        self::assertSame(['All projects', 'acme', 'demo'], $filters->filter('select[name=project] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame(['Any state', 'Deploying', 'Running', 'Stopped', 'Failed', 'Destroying', 'Unknown'], $filters->filter('select[name=state] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame('--i1', $filters->filter('input[name=q]')->attr('value'));
        self::assertSame('demo', $filters->filter('select[name=project] option[selected]')->attr('value'));
        self::assertSame('stopped', $filters->filter('select[name=state] option[selected]')->attr('value'));
        self::assertSame(['demo--i1'], $this->listed($crawler));
    }

    public function testARunningJobReplacesTheStateAndTurnsTheRowButtonsOff(): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Running]);
        $this->jobOn('acme--i1', 'deploy', JobStatus::Running);

        $crawler = $this->open('/instances');

        $busy = $crawler->filter('[data-instance="acme--i1"]');
        self::assertSame('Deploying…', trim($busy->filter('[data-testid=instance-state]')->text()));
        self::assertCount(1, $busy->filter('button[formaction$="/acme--i1/stop"][disabled]'));
        self::assertCount(1, $busy->filter('button[formaction$="/acme--i1/redeploy"][disabled]'));
        $idle = $crawler->filter('[data-instance="acme--i2"]');
        self::assertCount(0, $idle->filter('[disabled]'));
        self::assertCount(1, $idle->filter('button[formaction$="/acme--i2/stop"]'));
    }

    public function testAFailedLastJobLinksToItsPage(): void
    {
        $this->seed('acme', [InstanceState::Failed]);
        $job = $this->jobOn('acme--i1', 'deploy', JobStatus::Failed);

        $link = $this->open('/instances')->filter('[data-instance="acme--i1"] [data-testid=last-job-failed]');

        self::assertSame('Last job failed', trim($link->text()));
        self::assertSame('/jobs/'.$job->id(), $link->attr('href'));
    }

    public function testBulkSaysHowManyJobsWereQueued(): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Stopped, InstanceState::Running]);

        // A mixed selection (stopped and running) is accepted.
        $this->post('/instances/bulk', ['action' => 'stop', 'ids' => ['acme--i1', 'acme--i2', 'acme--i3']]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('3 jobs queued.', trim($this->flashes()));
        self::assertSame(3, $this->jobCount());

        $this->seed('demo', [InstanceState::Stopped]);
        $this->post('/instances/bulk', ['action' => 'start', 'ids' => ['demo--i1']]);
        self::assertSame('1 job queued.', trim($this->flashes()));
    }

    public function testBulkRedeployAndDestroyQueueOneSequentialJobPerInstance(): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Running, InstanceState::Running]);

        $this->post('/instances/bulk', ['action' => 'redeploy', 'ids' => ['acme--i1', 'acme--i2']]);
        $this->post('/instances/bulk', ['action' => 'destroy', 'ids' => ['acme--i3']]);

        /** @var list<Job> $jobs */
        $jobs = $this->em()->createQuery(\sprintf('SELECT j FROM %s j ORDER BY j.id', Job::class))->getResult();
        self::assertSame(['redeploy', 'redeploy', 'destroy'], array_map(static fn (Job $j): string => $j->type(), $jobs));
        self::assertTrue($jobs[0]->isSequential());
    }

    public function testBulkWithABusyInstanceIsRefusedAsAWholeAndNothingIsQueued(): void
    {
        $this->seed('acme', [InstanceState::Running, InstanceState::Running]);
        $this->jobOn('acme--i1', 'deploy', JobStatus::Running);
        self::assertSame(1, $this->jobCount());

        $this->post('/instances/bulk', ['action' => 'stop', 'ids' => ['acme--i1', 'acme--i2']]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('Busy: acme--i1 has a job queued or running. Nothing was started.', trim($this->flashes()));
        self::assertSame(1, $this->jobCount());
    }

    public function testBulkWithoutIdsAsksForASelection(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $this->post('/instances/bulk', ['action' => 'stop']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('Select at least one instance first.', trim($this->flashes()));
        self::assertSame(0, $this->jobCount());
    }

    public function testBulkWithAnUnknownActionIs400(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $this->post('/instances/bulk', ['action' => 'explode', 'ids' => ['acme--i1']]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->jobCount());
    }

    public function testBulkWithOneUnknownIdIs404AndTheRealInstanceIsNotTouched(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $this->post('/instances/bulk', ['action' => 'stop', 'ids' => ['acme--i1', 'acme--nothing-here']]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->jobCount());
    }

    public function testBulkWithoutOrWithAWrongTokenIs403(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $this->postWithoutToken('/instances/bulk', ['action' => 'stop', 'ids' => ['acme--i1']]);
        self::assertResponseStatusCodeSame(403);

        $this->postWithoutToken('/instances/bulk', ['action' => 'stop', 'ids' => ['acme--i1'], '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->jobCount());
    }

    public function testMemberSeesNoDeployLinkNoDestroyAndOnlyTheirProjectInTheFilter(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->seed('demo', [InstanceState::Running]);
        $this->as('member');

        $crawler = $this->open('/instances');

        self::assertCount(0, $crawler->filter('[data-testid=deploy-link]'));
        self::assertSame(['All projects', 'demo'], $crawler->filter('select[name=project] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertStringNotContainsString('Destroy', $crawler->filter('[data-instance="demo--i1"]')->text('', false));
        self::assertCount(0, $crawler->filter('[data-instance="demo--i1"] button[formaction$="/destroy"]'));
        self::assertCount(1, $crawler->filter('[data-instance="demo--i1"] button[formaction$="/redeploy"]'));

        $empty = $this->open('/instances?project=acme');
        self::assertSame([], $this->listed($empty));
        self::assertStringContainsString('No instance matches', $empty->filter('[data-testid=instances-empty]')->text());
    }

    public function testMemberDetailPageHasNoDestroyAndABulkStopWorksOnTheirInstance(): void
    {
        $this->seed('demo', [InstanceState::Running]);
        $this->seed('acme', [InstanceState::Running]);
        $this->as('member');

        $detail = $this->open('/instances/demo--i1');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $detail->filter('button[formaction$="/destroy"]'));
        self::assertCount(1, $detail->filter('[data-testid=instance-actions] button[formaction$="/redeploy"]'));

        // The member's own token, the foreign id: the role's answer, and nothing queued.
        $this->post('/instances/bulk', ['action' => 'stop', 'ids' => ['demo--i1', 'acme--i1']]);
        self::assertResponseStatusCodeSame(404);
        $this->post('/instances/acme--i1/stop', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->jobCount());

        $this->post('/instances/bulk', ['action' => 'stop', 'ids' => ['demo--i1']]);
        self::assertSame('1 job queued.', trim($this->flashes()));
    }

    public function testDetailPageShowsTheFactsTheAddressesTheActionsOfTheStateAndTheTimeline(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->jobOn('acme--i1', 'deploy', JobStatus::Succeeded);

        $crawler = $this->open('/instances/acme--i1');

        $detail = $crawler->filter('[data-testid=instance-detail]')->text();
        foreach (['Template', 'full', 'Requested by', 'admin', 'Head', 'Memory', 'develop'] as $fact) {
            self::assertStringContainsString($fact, $detail);
        }
        $address = $crawler->filter('[data-testid=instance-detail] a[href="https://acme--i1.fleet.example.com"]');
        self::assertCount(1, $address);
        self::assertSame('_blank', $address->attr('target'));
        self::assertSame('_blank', $crawler->filter('[data-testid=open-site]')->attr('target'));

        $actions = $crawler->filter('[data-testid=instance-actions]');
        self::assertCount(1, $actions->filter('button[formaction$="/stop"]:not([disabled])'));
        self::assertCount(0, $actions->filter('button[formaction$="/start"]'));
        self::assertCount(1, $actions->filter('button[formaction$="/redeploy"]:not([disabled])'));
        self::assertCount(1, $actions->filter('button[formaction$="/destroy"]'));
        self::assertCount(0, $crawler->filter('[data-testid=active-job]'));
        $timeline = $crawler->filter('[data-testid=timeline-job]');
        self::assertCount(1, $timeline);
        self::assertStringContainsString('deploy', $timeline->text());
    }

    public function testAStoppedInstanceOffersStartAndNoSiteLink(): void
    {
        $this->seed('acme', [InstanceState::Stopped]);

        $crawler = $this->open('/instances/acme--i1');

        self::assertStringContainsString('Stopped', $crawler->filter('[data-testid=instance-detail]')->text());
        self::assertCount(0, $crawler->filter('[data-testid=open-site]'));
        $actions = $crawler->filter('[data-testid=instance-actions]');
        self::assertCount(0, $actions->filter('button[formaction$="/stop"]'));
        self::assertCount(1, $actions->filter('button[formaction$="/start"]'));
    }

    public function testWhileAJobRunsTheButtonsAreOffAndTheRunningJobIsOnThePage(): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $job = $this->jobOn('acme--i1', 'deploy', JobStatus::Running);

        $crawler = $this->open('/instances/acme--i1');

        $actions = $crawler->filter('[data-testid=instance-actions]');
        self::assertCount(1, $actions->filter('button[formaction$="/stop"][disabled]'));
        self::assertCount(1, $actions->filter('button[formaction$="/redeploy"][disabled]'));
        // Destroy is not switched off: the service says no.
        self::assertCount(0, $actions->filter('button[formaction$="/destroy"][disabled]'));
        $active = $crawler->filter('[data-testid=active-job]');
        self::assertStringContainsString('Job #'.$job->id().' is running.', preg_replace('/\s+/', ' ', $active->text()) ?? '');
        self::assertCount(1, $active->filter('[data-testid=job-log]'));
    }

    #[DataProvider('operationsOnABusyInstance')]
    public function testAStalePageIsRefusedWithTheReason(string $action): void
    {
        $this->seed('acme', [InstanceState::Running]);
        $this->jobOn('acme--i1', 'deploy', JobStatus::Running);

        $this->post('/instances/acme--i1/'.$action, []);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/instances/acme--i1', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        self::assertStringContainsString('Busy: acme--i1 has a job queued or running. Nothing was started.', $this->flashes());
        self::assertSame(1, $this->jobCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationsOnABusyInstance(): iterable
    {
        yield 'stop' => ['stop'];
        yield 'redeploy' => ['redeploy'];
        yield 'destroy' => ['destroy'];
    }

    public function testUnknownIdsBadActionsAndAMissingTokenAreRefused(): void
    {
        $this->seed('acme', [InstanceState::Running]);

        $this->open('/instances/acme--nothing-here');
        self::assertResponseStatusCodeSame(404);
        $this->open('/instances/Not_An_Id');
        self::assertResponseStatusCodeSame(404);
        $this->open('/instances/acme--i1/stop');
        self::assertResponseStatusCodeSame(405);
        $this->post('/instances/acme--i1/explode', []);
        self::assertResponseStatusCodeSame(404);
        $this->post('/instances/acme--nothing-here/stop', []);
        self::assertResponseStatusCodeSame(404);
        $this->postWithoutToken('/instances/acme--i1/stop');
        self::assertResponseStatusCodeSame(403);
        $this->postWithoutToken('/instances/acme--i1/stop', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->jobCount());
    }
}
