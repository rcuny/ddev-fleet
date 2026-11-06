<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Network tab: hostnames, Typesense, ports and the editable port access, with the confirmation for risky changes.
 */
final class ProjectNetworkTest extends AdminTestCase
{
    private const string PATH = '/admin/projects/blog/network';

    private function service(): ProjectService
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);

        return $service;
    }

    private function makePort(string $name, int $public, int $router, ?string $access = null): void
    {
        $service = $this->container()->get(NamedPortService::class);
        self::assertInstanceOf(NamedPortService::class, $service);
        $service->create(new NamedPortInput($name, $public, $router, $access));
    }

    private function makeProject(?ProjectInput $input = null): void
    {
        $this->service()->create($input ?? new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
    }

    private function accessOf(string $port): string
    {
        return new NamedPortRepository($this->em())->findByName($port)?->access() ?? self::fail();
    }

    /**
     * @return list<array{string, string, string}> [actor, action, subject], newest first
     */
    private function audit(): array
    {
        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em())->latest(50),
        );
    }

    /**
     * @param array<string, mixed>  $extra
     * @param array<string, string> $access
     * @param list<string>          $ports
     */
    private function save(array $extra = [], string $hostnames = '', array $access = [], array $ports = ['mail']): Crawler
    {
        return $this->post(self::PATH, ['network' => ['additionalHostnames' => $hostnames], 'ports' => $ports, 'access' => $access] + $extra);
    }

    public function testTheAccessColumnShowsABadgeAndASelectOnlyForAssignedPorts(): void
    {
        $this->makePort('mail', 8025, 18025, 'public');
        $this->makePort('docs', 9300, 19300, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $crawler = $this->open(self::PATH);

        $rows = $crawler->filter('[data-testid=port-row]');
        self::assertCount(2, $rows);
        // Rows are ordered by name: docs (gated, not assigned), mail (public, assigned).
        self::assertSame('gated', $rows->eq(0)->filter('[data-testid=port-access-badge]')->attr('data-access'));
        self::assertCount(0, $rows->eq(0)->filter('select'));
        self::assertSame('public', $rows->eq(1)->filter('[data-testid=port-access-badge]')->attr('data-access'));
        self::assertStringContainsString('signal', (string) $rows->eq(1)->filter('[data-testid=port-access-badge]')->attr('class'));
        self::assertSame('public', $rows->eq(1)->filter('select option[selected]')->attr('value'));
        self::assertSame('Access of port mail', $rows->eq(1)->filter('select')->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-testid=network-confirm]'));
    }

    public function testFlippingTheAccessAsksForAConfirmationThenSavesAndAudits(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $crawler = $this->save(access: ['mail' => 'public']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('gated', $this->accessOf('mail'), 'nothing is saved before the confirmation');
        $dialog = $crawler->filter('[data-testid=network-confirm]');
        self::assertCount(1, $dialog);
        self::assertStringContainsString('from gated to public', $dialog->text());
        self::assertStringContainsString('every project that uses the port', $dialog->text());
        self::assertSame('network-form', $dialog->filter('[data-testid=network-confirm-save]')->attr('form'));
        self::assertSame('public', $crawler->filter('[data-testid=port-access-select] option[selected]')->attr('value'), 'the typed choice stays');

        $this->save(['confirmed' => '1'], access: ['mail' => 'public']);

        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame('public', $this->accessOf('mail'));
        self::assertSame(['dev-admin', 'port.access', 'blog port mail gated -> public'], $this->audit()[0]);

        // And back: flipping to gated is a change of access too.
        $this->save(['confirmed' => '1'], access: ['mail' => 'gated']);
        self::assertSame('gated', $this->accessOf('mail'));
        self::assertSame('blog port mail public -> gated', $this->audit()[0][2]);
    }

    public function testASubmissionWithTheSameAccessChangesNothingAndNeedsNoConfirmation(): void
    {
        $this->makePort('mail', 8025, 18025, 'public');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $this->save(access: ['mail' => 'public']);

        self::assertResponseRedirects(self::PATH, 303);
        self::assertStringContainsString('No change', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertSame([], $this->audit());
    }

    public function testAssigningAGatedPortNeedsNoConfirmationButAPublicOneDoes(): void
    {
        $this->makePort('docs', 9300, 19300, 'gated');
        $this->makePort('mail', 8025, 18025, 'public');
        $this->makeProject();

        $this->save(ports: ['docs']);
        self::assertResponseRedirects(self::PATH, 303);

        $crawler = $this->save(ports: ['docs', 'mail']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Assign the public port "mail"', $crawler->filter('[data-testid=network-confirm]')->text());

        $this->save(['confirmed' => '1'], ports: ['docs', 'mail']);
        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(['dev-admin', 'project.port', 'blog mail added'], $this->audit()[0]);

        // Unassigning the public port asks again; unassigning a gated one does not.
        $crawler = $this->save(ports: ['docs']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Unassign the public port "mail"', $crawler->filter('[data-testid=network-confirm]')->text());
        $this->save(ports: ['mail']);
        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(['mail'], array_map(static fn ($p): string => $p->name(), $this->stored()->ports()));
    }

    public function testAddingAHostnameAsksForAConfirmationAndRemovingOneDoesNot(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', additionalHostnames: ['www']));

        $crawler = $this->save(hostnames: "www\nshop");
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Add the hostname "shop"', $crawler->filter('[data-testid=network-confirm]')->text());
        self::assertSame(['www'], $this->stored()->additionalHostnames());
        self::assertSame("www\nshop", str_replace("\r\n", "\n", trim($crawler->filter('#network_additionalHostnames')->html())), 'the typed text stays');

        $this->save(['confirmed' => '1'], hostnames: "www\nshop");
        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(['www', 'shop'], $this->stored()->additionalHostnames());
        self::assertSame(['dev-admin', 'project.hostname', 'blog shop added'], $this->audit()[0]);

        $this->save(hostnames: 'shop');
        self::assertResponseRedirects(self::PATH, 303);
        self::assertSame(['shop'], $this->stored()->additionalHostnames());
        self::assertSame('blog www removed', $this->audit()[0][2]);
    }

    public function testASaveFromNetworkLeavesTheOtherFieldsAlone(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', 'main', cpuThreads: 2, slots: 1, issueIdRegexp: 'ABC-\d+', containerSsh: true));

        $this->save();

        self::assertResponseRedirects(self::PATH, 303);
        $project = $this->stored();
        self::assertSame('main', $project->defaultBranch());
        self::assertSame(2, $project->cpuThreads());
        self::assertSame(1, $project->slots());
        self::assertSame('ABC-\d+', $project->issueIdRegexp());
        self::assertTrue($project->containerSsh());
        self::assertSame(['mail'], array_map(static fn ($p): string => $p->name(), $project->ports()));
    }

    public function testTheTypesenseToggleSavesWithoutTouchingTheRest(): void
    {
        $this->makePort('typesense', 9108, 8108, 'gated');
        $this->makeProject();

        $this->post(self::PATH, ['network' => ['additionalHostnames' => '', 'typesense' => '1'], 'ports' => []]);

        self::assertResponseRedirects(self::PATH, 303);
        self::assertTrue($this->stored()->typesense());
    }

    public function testAnAccessForAPortThatIsNotAssignedYetIsRefused(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makePort('docs', 9300, 19300, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $crawler = $this->save(['confirmed' => '1'], access: ['docs' => 'public']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Assign the port "docs"', $crawler->filter('[data-testid=ports-errors]')->text());
        self::assertSame('gated', $this->accessOf('docs'));
        self::assertSame([], $this->audit());
    }

    public function testAnAccessOtherThanGatedOrPublicIsRefusedAndNothingIsSaved(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $crawler = $this->save(['confirmed' => '1'], hostnames: 'shop', access: ['mail' => 'open']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Access must be "gated" or "public"', $crawler->filter('[data-testid=ports-errors]')->text());
        self::assertSame('gated', $this->accessOf('mail'));
        self::assertSame([], $this->stored()->additionalHostnames(), 'the hostname is not saved either');
    }

    public function testAMemberAndACrossSitePostCannotChangeTheAccess(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', ports: ['mail']));

        $this->as('member');
        $this->save(['confirmed' => '1'], access: ['mail' => 'public']);
        self::assertResponseStatusCodeSame(403);

        $this->as('admin');
        $this->client->request('POST', self::BASE.self::PATH, ['network' => ['additionalHostnames' => ''], 'ports' => ['mail'], 'access' => ['mail' => 'public'], 'confirmed' => '1'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403, 'no token');
        self::assertSame('gated', $this->accessOf('mail'));
    }

    public function testTheCommandLineStillChangesTheAccessAndTheOldPortsAddressRedirects(): void
    {
        $this->makePort('mail', 8025, 18025, 'gated');
        $this->makeProject();

        $service = $this->container()->get(NamedPortService::class);
        self::assertInstanceOf(NamedPortService::class, $service);
        $service->setAccess('mail', 'public');
        self::assertSame('public', $this->accessOf('mail'));

        $this->open('/admin/projects/blog/ports');
        self::assertResponseRedirects(self::PATH, 308);
    }

    private function stored(): \App\Domain\Project\Project
    {
        return new ProjectRepository($this->em())->findByName('blog') ?? self::fail();
    }
}
