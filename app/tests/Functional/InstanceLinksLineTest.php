<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectLinkService;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;

/** FLE-299: the instance page shows one Links line per link of the instance, live or pending with the reason. */
final class InstanceLinksLineTest extends AdminTestCase
{
    private function seed(bool $link): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $projects->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        if ($link) {
            $links = $this->container()->get(ProjectLinkService::class);
            self::assertInstanceOf(ProjectLinkService::class, $links);
            $links->create('blog', 'shop', 'typesense', 8108, 'same', '', 'alice');
        }
        $em = $this->em();
        $repository = new ProjectRepository($em);
        $blog = $repository->findByName('blog') ?? self::fail();
        $instance = new Instance($blog, 'i1', 'full', 'develop', 'admin');
        $instance->changeState(InstanceState::Running);
        new InstanceRepository($em)->createMany([$instance]);
    }

    public function testAPendingLinkIsShownWithItsReason(): void
    {
        $this->seed(true);
        $crawler = $this->open('/instances/blog--i1');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=instance-link]'));
        $line = $crawler->filter('[data-testid=instance-link]');
        self::assertSame('pending', $line->attr('data-state'));
        self::assertStringContainsString('typesense.shop:8108', $line->text());
        self::assertStringContainsString('shop--i1', $line->text());
    }

    public function testNoLinkMeansNoLinksLine(): void
    {
        $this->seed(false);
        $crawler = $this->open('/instances/blog--i1');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=instance-links]'));
    }
}
