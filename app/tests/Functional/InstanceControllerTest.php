<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
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
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com');
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
}
