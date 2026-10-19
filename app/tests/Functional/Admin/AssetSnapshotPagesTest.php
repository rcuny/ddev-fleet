<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\ProjectRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;

/**
 * The "Snapshot a running instance" card and its POST. The test environment runs a queued job inside the request with the
 * recording runner, so a post leaves a finished job and a dump.
 */
final class AssetSnapshotPagesTest extends AdminTestCase
{
    private function makeProject(string $name = 'blog'): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    private function instance(string $project, string $label, InstanceState $state): string
    {
        $em = $this->em();
        $row = new Instance(new ProjectRepository($em)->findByName($project) ?? throw new \LogicException(), $label, 'full', 'develop', 'admin');
        $row->changeState($state);
        new InstanceRepository($em)->createMany([$row]);
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        mkdir($paths->instanceDir($row->id()), 0o750, true);

        return $row->id();
    }

    /**
     * @return list<string>
     */
    private function dumps(): array
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        return array_map(basename(...), glob($paths->assetsDir('blog').'/dumps/*') ?: []);
    }

    public function testTheCardListsOnlyRunningInstancesOfTheProject(): void
    {
        $this->makeProject();
        $this->makeProject('shop');
        $this->instance('blog', 'main', InstanceState::Running);
        $this->instance('blog', 'old', InstanceState::Stopped);
        $this->instance('shop', 'main', InstanceState::Running);

        $crawler = $this->open('/admin/projects/blog/assets');

        $options = $crawler->filter('[data-testid=snapshot-instance] option')->each(static fn ($o): string => $o->attr('value') ?? '');
        self::assertSame(['blog--main'], $options);
        self::assertSame('/admin/projects/blog/assets/snapshot', $crawler->filter('[data-testid=snapshot-form]')->attr('action'));
        self::assertCount(0, $crawler->filter('[data-testid=snapshot-empty]'));
    }

    public function testTheCardShowsAnEmptyStateWithoutARunningInstance(): void
    {
        $this->makeProject();
        $this->instance('blog', 'old', InstanceState::Stopped);

        $crawler = $this->open('/admin/projects/blog/assets');

        self::assertCount(1, $crawler->filter('[data-testid=snapshot-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=snapshot-form]'));
    }

    public function testPostingCreatesOneJobAndTheDumpAppearsInTheListing(): void
    {
        $this->makeProject();
        $this->instance('blog', 'main', InstanceState::Running);

        $this->post('/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main']);
        self::assertResponseRedirects('/admin/projects/blog/assets');
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Snapshot of blog--main queued as job', $crawler->filter('[data-testid=flashes]')->text());
        $jobs = new JobRepository($this->em())->forInstance('blog--main');
        self::assertCount(1, $jobs);
        self::assertSame('snapshot', $jobs[0]->type());
        self::assertSame(JobTrigger::Ui, $jobs[0]->trigger());
        self::assertSame(JobStatus::Succeeded, $jobs[0]->status());
        self::assertCount(1, $this->dumps());

        $listing = $this->client->request('GET', self::BASE.'/admin/projects/blog/assets?path=dumps', server: $this->browserServer());
        self::assertStringContainsString('default-blog--main-', $listing->filter('[data-testid=asset-name]')->text());
        $href = $listing->filter('[data-testid=asset-download]')->attr('href');
        $this->client->request('GET', self::BASE.$href, server: $this->browserServer());
        self::assertResponseIsSuccessful();
    }

    public function testASecondSnapshotKeepsTheFirst(): void
    {
        $this->makeProject();
        $this->instance('blog', 'main', InstanceState::Running);

        $this->post('/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main']);
        $this->post('/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main']);

        self::assertCount(2, $this->dumps());
    }

    public function testStoppedUnknownForeignAndBusyInstancesAreRefusedWithAMessage(): void
    {
        $this->makeProject();
        $this->makeProject('shop');
        $this->instance('blog', 'old', InstanceState::Stopped);
        $this->instance('blog', 'busy', InstanceState::Running);
        $this->instance('shop', 'main', InstanceState::Running);
        new JobRepository($this->em())->add(new Job('start', 'blog--busy', 'blog', JobTrigger::Ui, 'admin', [], null, false, null, new \DateTimeImmutable()));

        foreach (['blog--old' => 'not running', 'blog--nope' => 'does not exist', 'shop--main' => 'does not exist', 'blog--busy' => 'Busy'] as $id => $text) {
            $this->post('/admin/projects/blog/assets/snapshot', ['instance' => $id]);
            self::assertResponseRedirects('/admin/projects/blog/assets');
            $flash = $this->client->followRedirect()->filter('[data-testid=flashes]')->text();
            self::assertStringContainsString('No snapshot was started.', $flash);
            self::assertStringContainsString($text, $flash, $id);
        }
        self::assertSame([], $this->dumps());
    }

    public function testItNeedsAValidSameOriginRequest(): void
    {
        $this->makeProject();
        $this->instance('blog', 'main', InstanceState::Running);

        $this->client->request('POST', self::BASE.'/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main', '_token' => 'wrong'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.'/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main'], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::BASE.'/admin/projects/blog/assets/snapshot', server: $this->browserServer());
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->dumps());
    }

    public function testMembersAndAnonymousCannotCallIt(): void
    {
        $this->makeProject();
        $this->instance('blog', 'main', InstanceState::Running);

        foreach (['member' => 403, 'none' => 401] as $role => $status) {
            $this->as($role);
            $this->post('/admin/projects/blog/assets/snapshot', ['instance' => 'blog--main']);
            self::assertResponseStatusCodeSame($status, $role);
        }
        $this->as('admin');
        self::assertSame([], $this->dumps());
        self::assertCount(0, new JobRepository($this->em())->forInstance('blog--main'));
    }

    public function testAnUnknownProjectIs404(): void
    {
        $this->post('/admin/projects/ghost/assets/snapshot', ['instance' => 'ghost--main']);

        self::assertResponseStatusCodeSame(404);
    }
}
