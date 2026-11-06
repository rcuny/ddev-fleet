<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\HostPageTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Capacity tab of the host page and the CPU threads / Slots fields of the project and template forms (FLE-203).
 * The test kernel reads 48 threads from a fake cpuinfo file (reserve 2: 46 allocatable).
 */
final class HostCapacityPageTest extends HostPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents($this->paths()->home().'/test-cpuinfo', str_repeat("processor\t: 0\n\n", 48));
    }

    private function projects(): ProjectService
    {
        $service = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);

        return $service;
    }

    private function templates(): TemplateService
    {
        $service = self::getContainer()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $service);

        return $service;
    }

    private function text(Crawler $crawler, string $testId): string
    {
        return trim(preg_replace('/\s+/', ' ', $crawler->filter('[data-testid='.$testId.']')->text()) ?? '');
    }

    public function testHostFiguresAndNoWarningWhileTheAllotmentsFit(): void
    {
        $this->projects()->create(new ProjectInput('ci', 'https://git.example.com/acme/ci.git', cpuThreads: 24, slots: 3));
        $this->projects()->create(new ProjectInput('renovate', 'https://git.example.com/acme/r.git', cpuThreads: 22));
        $this->templates()->create('ci', new TemplateInput('gates', slots: 3));

        $crawler = $this->open('/admin/host?tab=capacity');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('48', $this->text($crawler, 'capacity-threads'));
        self::assertSame('46', $this->text($crawler, 'capacity-allocatable'));
        self::assertSame('2', $this->text($crawler, 'capacity-reserve'));
        self::assertSame('46 / 46', $this->text($crawler, 'capacity-committed'));
        self::assertSame('0 / 25', $this->text($crawler, 'capacity-instances'));
        self::assertCount(0, $crawler->filter('[data-testid=capacity-overcommit]'));
        self::assertCount(2, $crawler->filter('[data-testid=capacity-project-row]'));
        $template = $crawler->filter('[data-testid=capacity-template-row][data-template=gates]');
        self::assertStringContainsString('24 (inherited)', preg_replace('/\s+/', ' ', $template->text()) ?? '');
        self::assertStringContainsString('3 (0)', preg_replace('/\s+/', ' ', $template->text()) ?? '');
        self::assertCount(1, $crawler->filter('[data-testid=capacity-unsliced-none]'));
    }

    public function testTheOvercommitWarningShowsAt47Of46(): void
    {
        $this->projects()->create(new ProjectInput('ci', 'https://git.example.com/acme/ci.git', cpuThreads: 24));
        $this->projects()->create(new ProjectInput('renovate', 'https://git.example.com/acme/r.git', cpuThreads: 23));

        $crawler = $this->open('/admin/host?tab=capacity');
        self::assertSame('47 / 46', $this->text($crawler, 'capacity-committed'));
        self::assertStringContainsString('Overcommitted: 47 of 46 threads', $this->text($crawler, 'capacity-overcommit'));
    }

    public function testReserveAndInstanceCapAreSavedAndValidated(): void
    {
        $this->submit('capacity', 'Save', ['host_capacity[reserve]' => '4', 'host_capacity[maxInstances]' => '40']);
        self::assertResponseRedirects('/admin/host?tab=capacity');
        self::assertSame([4, 40], [$this->host()->cpuReserveThreads(), $this->host()->maxInstances()]);
        $crawler = $this->client->followRedirect();
        self::assertSame('44', $this->text($crawler, 'capacity-allocatable'));
        self::assertSame('0 / 40', $this->text($crawler, 'capacity-instances'));

        foreach ([['-1', '40'], ['48', '40'], ['abc', '40'], ['4', '0'], ['4', '10001'], ['', '']] as [$reserve, $max]) {
            $this->submit('capacity', 'Save', ['host_capacity[reserve]' => $reserve, 'host_capacity[maxInstances]' => $max]);
            self::assertResponseStatusCodeSame(422, $reserve.'/'.$max);
            self::assertSame([4, 40], [$this->host()->cpuReserveThreads(), $this->host()->maxInstances()]);
        }
        self::assertStringContainsString('from 0 to 47', $this->body());
    }

    public function testInstancesNotUnderASliceAreListed(): void
    {
        $this->projects()->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $em = $this->em();
        $project = new \App\Domain\Project\ProjectRepository($em)->findByName('blog') ?? self::fail('project');
        $em->persist(new \App\Domain\Instance\Instance($project, 'main', 'default', 'main', 'tester'));
        $em->flush();

        $crawler = $this->open('/admin/host?tab=capacity');
        self::assertStringContainsString('blog--main', $this->text($crawler, 'capacity-unsliced-list'));

        $dir = $this->paths()->instanceDir('blog--main').'/.ddev';
        mkdir($dir, 0o755, true);
        touch($dir.'/docker-compose.fleet-cgroup.yaml');
        $crawler = $this->open('/admin/host?tab=capacity');
        self::assertCount(1, $crawler->filter('[data-testid=capacity-unsliced-none]'));
    }

    public function testProjectFormSavesAndRefusesCapacity(): void
    {
        $this->projects()->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $this->templates()->create('blog', new TemplateInput('web', cpuThreads: 8, slots: 2));

        $crawler = $this->open('/admin/projects/blog/source');
        self::assertStringContainsString('This host can allocate 46.', $crawler->filter('[data-testid=project-capacity-fields]')->text());
        $form = $crawler->selectButton('Save')->form(['project[cpuThreads]' => '12', 'project[slots]' => '4']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $project = new \App\Domain\Project\ProjectRepository($this->em())->findByName('blog') ?? self::fail('project');
        self::assertSame([12, 4], [$project->cpuThreads(), $project->slots()]);

        // Below the template's explicit value: refused, naming the template.
        $crawler = $this->open('/admin/projects/blog/source');
        $this->client->submit($crawler->selectButton('Save')->form(['project[cpuThreads]' => '4', 'project[slots]' => '1']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('web', $this->text(new Crawler($this->body()), 'project-capacity-fields'));

        $this->client->submit($this->client->request('GET', '/admin/projects/blog/source')->selectButton('Save')->form(['project[cpuThreads]' => '0', 'project[slots]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('at least 1', $this->body());

        // Emptied: unlimited again.
        $this->client->submit($this->client->request('GET', '/admin/projects/blog/source')->selectButton('Save')->form(['project[cpuThreads]' => '', 'project[slots]' => '']));
        self::assertResponseRedirects();
        $project = new \App\Domain\Project\ProjectRepository($this->em())->findByName('blog') ?? self::fail('project');
        self::assertSame([null, null], [$project->cpuThreads(), $project->slots()]);
    }

    public function testTemplateFormShowsInheritanceAndRefusesAboveTheProject(): void
    {
        $this->projects()->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', cpuThreads: 10, slots: 2));

        $crawler = $this->open('/admin/projects/blog/templates/new');
        $fields = $crawler->filter('[data-testid=template-capacity-fields]');
        self::assertStringContainsString('Leave empty to inherit the project: 10.', $fields->text());
        self::assertSame('inherits project: 10', $fields->filter('input[name="template[cpuThreads]"]')->attr('placeholder'));

        $this->client->submit($crawler->selectButton('Create template')->form(['template[name]' => 'big', 'template[cpuThreads]' => '11', 'template[slots]' => '3']));
        self::assertResponseStatusCodeSame(422);
        $text = $this->text(new Crawler($this->body()), 'template-capacity-fields');
        self::assertStringContainsString('CPU threads', $text);
        self::assertStringContainsString('Slots', $text);

        $this->client->submit($this->client->request('GET', '/admin/projects/blog/templates/new')->selectButton('Create template')->form(['template[name]' => 'big', 'template[cpuThreads]' => '6', 'template[slots]' => '2']));
        self::assertResponseRedirects();
        $template = $this->templates()->get('blog', 'big');
        self::assertSame([6, 2], [$template->cpuThreads(), $template->slots()]);

        // Edit keeps them, and clearing returns to inheriting.
        $edit = $this->client->request('GET', '/admin/projects/blog/templates/big');
        self::assertSame('6', $edit->filter('input[name="template[cpuThreads]"]')->attr('value'));
        $this->client->submit($edit->selectButton('Save')->form(['template[cpuThreads]' => '', 'template[slots]' => '']));
        self::assertResponseRedirects();
        $template = $this->templates()->get('blog', 'big');
        self::assertSame([null, null], [$template->cpuThreads(), $template->slots()]);
    }
}
