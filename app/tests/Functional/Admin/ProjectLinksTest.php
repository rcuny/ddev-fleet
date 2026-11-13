<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Project\ProjectLinkRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectLinkService;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * FLE-299: the Links card of a project's Network tab: lists, create behind re-authentication, delete with a
 * confirmation, errors per field, admin only.
 */
final class ProjectLinksTest extends AdminTestCase
{
    private const string PATH = '/admin/projects/blog/network';

    private function seed(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $service->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
    }

    private function links(): ProjectLinkService
    {
        $service = $this->container()->get(ProjectLinkService::class);
        self::assertInstanceOf(ProjectLinkService::class, $service);

        return $service;
    }

    private function linkCount(): int
    {
        return \count(new ProjectLinkRepository($this->em())->all());
    }

    /** @return list<array{string, string, string}> */
    private function audit(): array
    {
        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em())->latest(50),
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function create(array $fields): Crawler
    {
        return $this->post('/admin/projects/blog/links', ['project_link' => $fields + ['target' => 'shop', 'service' => 'typesense', 'port' => '8108', 'label' => 'same', 'note' => '']]);
    }

    public function testTheCardListsOutgoingAndIncomingLinks(): void
    {
        $this->seed();
        $this->links()->create('blog', 'shop', 'typesense', 8108, 'same', 'search', 'alice');
        $this->links()->create('shop', 'blog', 'web', 80, 'main', '', 'bob');

        $crawler = $this->open(self::PATH);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=link-row]'));
        $row = $crawler->filter('[data-testid=link-row]')->text();
        self::assertStringContainsString('shop', $row);
        self::assertStringContainsString('typesense:8108', $row);
        self::assertStringContainsString('same label', $row);
        self::assertStringContainsString('search', $row);
        self::assertStringContainsString('alice', $row);
        self::assertCount(1, $crawler->filter('[data-testid=link-in-row]'));
        self::assertStringContainsString('bob', $crawler->filter('[data-testid=link-in-row]')->text());
        self::assertSame('/admin/projects/shop/network', $crawler->filter('[data-testid=link-in-row] a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid=link-in-row] form'));
    }

    public function testCreateAsksForReauthenticationAndStoresNothingWhileTheWindowIsClosed(): void
    {
        $this->seed();
        $this->create([]);

        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/network', 303);
        self::assertSame(0, $this->linkCount());
    }

    public function testCreateInsideTheWindowStoresTheLinkAuditsItAndClosesTheWindow(): void
    {
        $this->seed();
        $this->confirmPassword();
        $this->create(['note' => 'search backend']);

        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(1, $this->linkCount());
        $crawler = $this->open(self::PATH);
        self::assertCount(1, $crawler->filter('[data-testid=link-row]'));
        self::assertSame('dev-admin', $this->audit()[0][0]);
        self::assertSame('project.link-create', $this->audit()[0][1]);

        $this->create(['port' => '9000']);
        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/network', 303);
    }

    public function testARefusedCreateShowsTheMessageUnderItsField(): void
    {
        $this->seed();
        $this->confirmPassword();
        $this->create(['port' => '99999']);
        self::assertResponseRedirects(self::PATH, 303);
        $crawler = $this->open(self::PATH);

        self::assertSame(0, $this->linkCount());
        self::assertStringContainsString('Enter a TCP port between 1 and 65535.', $crawler->filter('#project_link_port')->ancestors()->first()->text());
        self::assertSame('99999', $crawler->filter('#project_link_port')->attr('value'));
    }

    public function testADuplicateIsRefusedOnTheNextVisit(): void
    {
        $this->seed();
        $this->links()->create('blog', 'shop', 'typesense', 8108, 'same', '', 'alice');
        $this->confirmPassword();
        $this->create([]);
        $crawler = $this->open(self::PATH);

        self::assertSame(1, $this->linkCount());
        self::assertStringContainsString('This link already exists.', $crawler->filter('#project_link_port')->ancestors()->first()->text());
    }

    public function testDeleteRemovesTheLinkAndAuditsIt(): void
    {
        $this->seed();
        $this->links()->create('blog', 'shop', 'typesense', 8108, 'same', '', 'alice');
        $crawler = $this->open(self::PATH);
        self::assertCount(1, $crawler->filter('[data-testid=link-delete]'));
        self::assertCount(1, $crawler->filter('[data-testid=link-delete-confirm]'));

        $this->post('/admin/projects/blog/links/delete', ['target' => 'shop', 'service' => 'typesense', 'port' => '8108', 'label' => 'same']);

        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(0, $this->linkCount());
        self::assertSame('project.link-delete', $this->audit()[0][1]);

        $this->post('/admin/projects/blog/links/delete', ['target' => 'shop', 'service' => 'typesense', 'port' => '8108', 'label' => 'same']);
        self::assertResponseRedirects(self::PATH, 303);
    }

    public function testAMemberCannotSeeOrChangeLinks(): void
    {
        $this->seed();
        $this->links()->create('blog', 'shop', 'typesense', 8108, 'same', '', 'alice');
        $this->as('member');

        $this->open(self::PATH);
        self::assertResponseStatusCodeSame(403);
        $this->create([]);
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/projects/blog/links/delete', ['target' => 'shop', 'service' => 'typesense', 'port' => '8108', 'label' => 'same']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->linkCount());
        self::assertNotNull(new ProjectRepository($this->em())->findByName('blog'));
    }

    public function testAMissingCsrfTokenIsRefused(): void
    {
        $this->seed();
        $this->client->request('POST', self::BASE.'/admin/projects/blog/links', ['project_link' => ['target' => 'shop']], [], $this->browserServer());

        self::assertResponseStatusCodeSame(403);
    }
}
