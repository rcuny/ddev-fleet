<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Rule\GitUrl;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class ProjectPagesTest extends AdminTestCase
{
    private const string GIT = 'https://git.example.com/acme/blog.git';

    private function makeProject(string $name = 'blog', ?ProjectInput $input = null): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create($input ?? new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    private function makeTemplate(string $project, string $name): void
    {
        $service = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $service);
        $service->create($project, new TemplateInput($name));
    }

    private function makePort(string $name, int $public, int $router): void
    {
        $service = $this->container()->get(NamedPortService::class);
        self::assertInstanceOf(NamedPortService::class, $service);
        $service->create(new NamedPortInput($name, $public, $router));
    }

    private function stored(string $name): ?Project
    {
        return new ProjectRepository($this->em())->findByName($name);
    }

    private function projectCount(): int
    {
        return \count(new ProjectRepository($this->em())->all());
    }

    // --- list

    public function testListShowsProjectsAndAnEmptyState(): void
    {
        $crawler = $this->open('/admin/projects');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Projects', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=projects-empty]'));
        self::assertSame('/admin/projects/new', $crawler->filter('[data-testid=projects-empty] a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid=project-row]'));
        self::assertSame('/admin/projects/new', $crawler->filter('main header a')->attr('href'));

        $this->makeProject('blog', new ProjectInput('blog', self::GIT, 'develop'));
        $this->makeProject('shop');
        $this->makeTemplate('blog', 'full');
        $this->makeTemplate('blog', 'light');

        $crawler = $this->open('/admin/projects');
        self::assertCount(0, $crawler->filter('[data-testid=projects-empty]'));
        $rows = $crawler->filter('[data-testid=project-row]');
        self::assertCount(2, $rows);
        $blog = $rows->eq(0);
        self::assertSame('/admin/projects/blog', $blog->filter('a')->attr('href'));
        self::assertStringContainsString('blog', $blog->text());
        self::assertStringContainsString(self::GIT, $blog->text());
        self::assertStringContainsString('develop', $blog->text());
        self::assertStringContainsString('2 templates', $blog->text());
        self::assertStringContainsString('0 templates', $rows->eq(1)->text());
        self::assertStringContainsString('shop', $rows->eq(1)->text());
    }

    // --- create

    public function testCreateRedirectsToEditWithAFlash(): void
    {
        $crawler = $this->open('/admin/projects/new');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('New project', trim($crawler->filter('main h1')->text()));
        self::assertCount(0, $crawler->filter('select[name="project[defaultTemplate]"]'), 'no default template on create');

        $this->submit($crawler, 'Create project', ['project[name]' => '  blog ', 'project[git]' => ' '.self::GIT."\n", 'project[defaultBranch]' => 'main']);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        $project = $this->stored('blog') ?? self::fail('the project is stored');
        self::assertSame(self::GIT, $project->git());
        self::assertSame('main', $project->defaultBranch());

        $crawler = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Project created.', $crawler->filter('[data-testid=flashes]')->text());

        // The flash is shown once.
        $crawler = $this->open('/admin/projects/blog');
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
    }

    public function testBadInputReturns422WithFieldErrors(): void
    {
        $crawler = $this->open('/admin/projects/new');
        $crawler = $this->submit($crawler, 'Create project', ['project[name]' => 'Bad_Name', 'project[git]' => 'http://example.com/x.git']);

        self::assertResponseStatusCodeSame(422);
        $name = (string) ResourceName::error('Bad_Name');
        $git = (string) GitUrl::error('http://example.com/x.git');
        self::assertNotSame('', $name);
        self::assertNotSame('', $git);
        self::assertSame($name, trim($crawler->filter('#project_name_errors')->text()));
        self::assertSame($git, trim($crawler->filter('#project_git_errors')->text()));
        self::assertSame('true', $crawler->filter('#project_name')->attr('aria-invalid'));
        self::assertSame('true', $crawler->filter('#project_git')->attr('aria-invalid'));
        self::assertStringContainsString('project_name_errors', (string) $crawler->filter('#project_name')->attr('aria-describedby'));
        self::assertSame('Bad_Name', $crawler->filter('#project_name')->attr('value'));
        self::assertSame('http://example.com/x.git', $crawler->filter('#project_git')->attr('value'));
        self::assertSame(0, $this->projectCount());
    }

    public function testFormsAreNovalidateSoServerErrorsShow(): void
    {
        $crawler = $this->open('/admin/projects/new');
        self::assertNotNull($crawler->filter('main form[data-testid=project-form]')->attr('novalidate'));

        $this->makeProject();
        $crawler = $this->open('/admin/projects/blog');
        self::assertNotNull($crawler->filter('main form[data-testid=project-form]')->attr('novalidate'));

        // An empty submit reaches the server and comes back as field errors.
        $crawler = $this->open('/admin/projects/new');
        $crawler = $this->submit($crawler, 'Create project', ['project[name]' => '', 'project[git]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#project_name_errors'));
        self::assertCount(1, $crawler->filter('#project_git_errors'));
    }

    public function testDuplicateAndReservedNamesAreFieldErrors(): void
    {
        $this->makeProject();

        foreach (['blog' => 'already exists', 'new' => 'reserved'] as $name => $needle) {
            $crawler = $this->open('/admin/projects/new');
            $crawler = $this->submit($crawler, 'Create project', ['project[name]' => $name, 'project[git]' => self::GIT]);

            self::assertResponseStatusCodeSame(422, $name);
            self::assertStringContainsString($needle, $crawler->filter('#project_name_errors')->text());
        }
        self::assertSame(1, $this->projectCount());
    }

    // --- edit

    public function testEditSavesEveryField(): void
    {
        $this->makeProject();
        $this->makeTemplate('blog', 'full');
        $this->makeTemplate('blog', 'light');

        $crawler = $this->open('/admin/projects/blog');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertStringContainsString(self::GIT, $crawler->filter('main header')->text());
        self::assertSame(['', 'full', 'light'], $crawler->filter('select[name="project[defaultTemplate]"] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));

        $this->submit($crawler, 'Save', [
            'project[git]' => 'git@git.example.com:acme/blog.git',
            'project[defaultBranch]' => 'develop',
            'project[defaultTemplate]' => 'light',
            'project[additionalHostnames]' => "www\r\n\r\n app \n",
            'project[gitBotMode]' => 'override',
            'project[gitBotName]' => 'Fleet Bot',
            'project[gitBotEmail]' => 'bot@example.com',
            'project[issueIdRegexp]' => '(?:ABC|XYZ)-\d{1,6}',
            'project[displaySubmoduleBranch]' => 'web/modules/custom/blog',
            'project[typesense]' => true,
        ]);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame('git@git.example.com:acme/blog.git', $project->git());
        self::assertSame('develop', $project->defaultBranch());
        self::assertSame('light', $project->defaultTemplate());
        self::assertSame(['www', 'app'], $project->additionalHostnames());
        self::assertSame(GitBotMode::Override, $project->gitBotMode());
        self::assertSame('Fleet Bot', $project->gitBotName());
        self::assertSame('bot@example.com', $project->gitBotEmail());
        self::assertSame('(?:ABC|XYZ)-\d{1,6}', $project->issueIdRegexp());
        self::assertSame('web/modules/custom/blog', $project->displaySubmoduleBranch());
        self::assertTrue($project->typesense());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Project saved.', $crawler->filter('[data-testid=flashes]')->text());
        // The saved values come back in the form.
        self::assertSame("www\napp", str_replace("\r\n", "\n", trim($crawler->filter('#project_additionalHostnames')->html())));
        self::assertSame('develop', $crawler->filter('#project_defaultBranch')->attr('value'));
        self::assertSame('light', $crawler->filter('#project_defaultTemplate option[selected]')->attr('value'));
        self::assertSame('Fleet Bot', $crawler->filter('#project_gitBotName')->attr('value'));
        self::assertNotNull($crawler->filter('#project_typesense')->attr('checked'));

        // Choosing the host default again drops the identity.
        $this->submit($crawler, 'Save', ['project[gitBotMode]' => 'inherit', 'project[typesense]' => false, 'project[defaultTemplate]' => '']);
        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(GitBotMode::Inherit, $project->gitBotMode());
        self::assertNull($project->gitBotName());
        self::assertNull($project->defaultTemplate());
        self::assertFalse($project->typesense());
    }

    public function testEditShowsFieldErrorsAndKeepsTheStoredProject(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');
        $crawler = $this->submit($crawler, 'Save', [
            'project[git]' => self::GIT,
            'project[issueIdRegexp]' => '(a+)+$',
            'project[gitBotMode]' => 'override',
            'project[gitBotName]' => '',
            'project[gitBotEmail]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#project_issueIdRegexp_errors'));
        self::assertSame('true', $crawler->filter('#project_issueIdRegexp')->attr('aria-invalid'));
        self::assertCount(1, $crawler->filter('#project_gitBotName_errors'));
        self::assertNull(($this->stored('blog') ?? self::fail())->issueIdRegexp());
        self::assertSame(GitBotMode::Inherit, ($this->stored('blog') ?? self::fail())->gitBotMode());
    }

    public function testSavingGeneralKeepsPortAssignments(): void
    {
        $this->makePort('typesense', 9108, 8108);
        $this->makeProject('blog', new ProjectInput('blog', self::GIT, ports: ['typesense']));

        $crawler = $this->open('/admin/projects/blog');
        $this->submit($crawler, 'Save', ['project[defaultBranch]' => 'develop']);
        self::assertResponseRedirects('/admin/projects/blog', 303);

        $project = $this->stored('blog') ?? self::fail();
        self::assertSame(['typesense'], array_map(static fn ($p): string => $p->name(), $project->ports()));
        self::assertSame('develop', $project->defaultBranch());
    }

    public function testNameIsNotEditable(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');
        self::assertCount(0, $crawler->filter('[name="project[name]"]'));

        // A crafted request that adds a name is refused as an extra field; nothing is renamed or created.
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['project']['name'] = 'other';
        $this->client->request('POST', self::BASE.'/admin/projects/blog', $values, [], $this->browserServer());

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->stored('other'));
        self::assertNotNull($this->stored('blog'));
    }

    public function testAMillionCharacterTextareaIsAFieldErrorNot500(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');
        $crawler = $this->submit($crawler, 'Save', ['project[additionalHostnames]' => str_repeat("a\n", 500_000)]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('#project_additionalHostnames_errors'));
        self::assertSame([], ($this->stored('blog') ?? self::fail())->additionalHostnames());
    }

    public function testTooManyHostnamesIsAFieldError(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');
        $crawler = $this->submit($crawler, 'Save', ['project[additionalHostnames]' => implode("\n", array_map(static fn (int $i): string => 'h'.$i, range(1, 11)))]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('At most 10', $crawler->filter('#project_additionalHostnames_errors')->text());
    }

    // --- layout

    public function testPagesHaveOneH1AndTheTabNavigation(): void
    {
        $this->makeProject();
        $this->makeTemplate('blog', 'full');

        foreach (['/admin/projects', '/admin/projects/new', '/admin/projects/blog', '/admin/projects/blog/ports', '/admin/projects/blog/templates'] as $path) {
            $crawler = $this->open($path);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertCount(1, $crawler->filter('h1'), $path);
            self::assertCount(1, $crawler->filter('main#main'), $path);
        }

        foreach (['/admin/projects/blog' => 'General', '/admin/projects/blog/templates' => 'Templates', '/admin/projects/blog/ports' => 'Ports', '/admin/projects/blog/assets' => 'Assets'] as $path => $active) {
            $crawler = $this->open($path);
            $tabs = $crawler->filter('nav[aria-label="Project sections"]');
            self::assertCount(1, $tabs, $path);
            self::assertSame(['General', 'Templates', 'Secrets', 'Ports', 'Assets'], array_map('trim', $tabs->filter('a')->each(static fn (Crawler $a): string => $a->text())));
            self::assertSame(
                ['/admin/projects/blog', '/admin/projects/blog/templates', '/admin/projects/blog/secrets', '/admin/projects/blog/ports', '/admin/projects/blog/assets'],
                $tabs->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')),
            );
            self::assertSame($active, trim($tabs->filter('a[aria-current="page"]')->text()), $path);

            // One tab strip at every width: no phone select that waits for a script.
            self::assertCount(0, $crawler->filter('select[aria-label="Section"]'), $path);
            self::assertCount(0, $crawler->filter('[data-controller~="select-nav"]'), $path);
            self::assertStringNotContainsString('hidden', (string) $tabs->attr('class'), $path);
        }
    }

    public function testNavigationMarksProjectsAsCurrentOnEveryProjectPage(): void
    {
        $this->makeProject();

        foreach (['/admin/projects', '/admin/projects/new', '/admin/projects/blog', '/admin/projects/blog/ports'] as $path) {
            $current = $this->open($path)->filter('nav[aria-label="Main"] a[aria-current="page"]');
            self::assertCount(1, $current, $path);
            self::assertSame('Projects', trim($current->text()), $path);
        }
    }

    #[DataProvider('getPages')]
    public function testAPlainGetStartsNoSession(string $path): void
    {
        $this->makeProject();
        $this->client->getCookieJar()->clear();
        $this->open($path);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->client->getResponse()->headers->getCookies(), $path.' does not start a session');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function getPages(): iterable
    {
        yield 'list' => ['/admin/projects'];
        yield 'new' => ['/admin/projects/new'];
        yield 'edit' => ['/admin/projects/blog'];
        yield 'ports' => ['/admin/projects/blog/ports'];
    }

    public function testTheSectionTabsAreFullTapTargetsAtEveryWidth(): void
    {
        $this->makeProject();

        $tabs = $this->open('/admin/projects/blog')->filter('nav[aria-label="Project sections"]');
        // py-3 around a text-sm line is 44 px; the strip scrolls inside itself instead of the page.
        self::assertStringContainsString('overflow-x-auto', (string) $tabs->attr('class'));
        foreach ($tabs->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('class')) as $class) {
            self::assertStringContainsString('py-3', $class);
            self::assertStringContainsString('whitespace-nowrap', $class);
        }
    }

    public function testRowsOfChoicesAreClickableAcrossTheRow(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog');
        foreach (['radio-row', 'checkbox-row'] as $slot) {
            $rows = $crawler->filter('[data-slot='.$slot.']');
            self::assertGreaterThan(0, $rows->count(), $slot);
            foreach ($rows as $row) {
                $row = new Crawler($row);
                self::assertStringContainsString('relative', (string) $row->attr('class'));
                self::assertStringContainsString('min-h-11', (string) $row->attr('class'));
                // The label stretches over the row.
                self::assertStringContainsString('after:absolute after:inset-0', (string) $row->filter('label[for]')->last()->attr('class'));
            }
        }
    }

    public function testALongGitUrlWrapsInsteadOfWideningThePage(): void
    {
        $url = 'https://git.example.com/'.str_repeat('a', 180).'/blog.git';
        $this->makeProject('blog', new ProjectInput('blog', $url));

        foreach (['/admin/projects/blog', '/admin/projects/blog/ports'] as $path) {
            $header = $this->open($path)->filter('main header');
            self::assertStringContainsString($url, $header->text(), $path);
            self::assertStringContainsString('[overflow-wrap:anywhere]', (string) $header->filter('h1')->attr('class'), $path);
            self::assertStringContainsString('[overflow-wrap:anywhere]', (string) $header->filter('p')->first()->attr('class'), $path);
        }
        // The list row truncates it.
        self::assertStringContainsString('truncate', (string) $this->open('/admin/projects')->filter('[data-testid=project-row] span.truncate')->first()->attr('class'));
    }

    public function testHostileValuesAreEscapedOnTheErrorPage(): void
    {
        $crawler = $this->open('/admin/projects/new');
        $values = [
            'project[name]' => '<script>alert(1)</script>',
            'project[git]' => '"><img src=x onerror=alert(2)>',
            'project[defaultBranch]' => '</textarea><svg onload=alert(3)>',
            'project[additionalHostnames]' => "</textarea><script>alert(4)</script>\n\"onfocus=\"alert(5)",
            'project[issueIdRegexp]' => '(?<x><b>)',
            'project[gitBotName]' => '<i>bot</i>',
            'project[displaySubmoduleBranch]' => '<u>path</u>',
        ];
        $this->submit($crawler, 'Create project', $values);

        self::assertResponseStatusCodeSame(422);
        $body = $this->body();
        foreach (['<script>alert', '<img src=x', '<svg onload', '<i>bot', '<u>path', '<b>'] as $raw) {
            self::assertStringNotContainsString($raw, $body, $raw.' must be escaped');
        }
        self::assertStringNotContainsString('" onfocus', $body);
        // The values come back as text, not markup.
        self::assertSame('<script>alert(1)</script>', $this->client->getCrawler()->filter('#project_name')->attr('value'));
        self::assertStringContainsString('&lt;script&gt;alert(4)', $body);
        self::assertSame(0, $this->projectCount());
        self::assertCount(0, $this->client->getCrawler()->filter('main script, main img, main [onerror], main [onload], main [onfocus]'));
    }

    // --- ports tab

    public function testPortsTabIsReadOnly(): void
    {
        $this->makePort('typesense', 9108, 8108);
        $this->makePort('mail', 8025, 18025);
        $this->makeProject('blog', new ProjectInput('blog', self::GIT, ports: ['typesense']));

        $crawler = $this->open('/admin/projects/blog/ports');
        self::assertResponseStatusCodeSame(200);

        $main = $crawler->filter('main');
        self::assertCount(0, $main->filter('form, input, textarea'));
        self::assertCount(0, $main->filter('button[type=submit]'));
        $rows = $main->filter('[data-testid=port-row]');
        self::assertCount(2, $rows);
        self::assertStringContainsString('mail', $rows->eq(0)->text());
        self::assertStringContainsString('9108', $rows->eq(1)->text());
        self::assertStringContainsString('typesense', $rows->eq(1)->text());
        self::assertCount(1, $main->filter('[data-testid=port-assigned]'));
        self::assertStringContainsString('Assigned', $rows->eq(1)->filter('[data-testid=port-assigned]')->text());
        self::assertStringContainsString('M0.07', $main->filter('[data-testid=ports-note]')->text());
    }

    public function testPortsTabWithAnEmptyCatalogue(): void
    {
        $this->makeProject();

        $crawler = $this->open('/admin/projects/blog/ports');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=port-row]'));
        self::assertCount(1, $crawler->filter('[data-testid=ports-empty]'));
    }

    // --- delete

    public function testDeleteRemovesTheProjectAndItsTemplates(): void
    {
        $this->makeProject();
        $this->makeProject('shop');
        $this->makeTemplate('blog', 'full');

        $crawler = $this->open('/admin/projects/blog');
        $form = $crawler->filter('form[action="/admin/projects/blog/delete"]');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[name=_token]'));
        $this->client->submit($form->selectButton('Delete project')->form());

        self::assertResponseRedirects('/admin/projects', 303);
        self::assertNull($this->stored('blog'));
        self::assertNotNull($this->stored('shop'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM template'));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('blog', $crawler->filter('[data-testid=flashes]')->text());
        self::assertStringContainsString('deleted', $crawler->filter('[data-testid=flashes]')->text());
    }

    public function testDeleteShowsTheAccessWarningLeftByTheRefresh(): void
    {
        $this->makeProject();
        $refresher = $this->container()->get(ProjectAccessRefresher::class);
        self::assertInstanceOf(ProjectAccessRefresher::class, $refresher);
        // What a failed install leaves behind (see ProjectAccessRefresher::refresh()).
        new \ReflectionProperty($refresher, 'warning')->setValue($refresher, 'The project was saved, but the sign-in service was not updated: boom');

        $crawler = $this->open('/admin/projects/blog');
        $this->client->submit($crawler->filter('form[action="/admin/projects/blog/delete"]')->selectButton('Delete project')->form());
        $crawler = $this->client->followRedirect();

        $flashes = $crawler->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('deleted', $flashes);
        self::assertStringContainsString('sign-in service was not updated: boom', $flashes);
    }

    public function testDeleteIsRefusedWhileInstancesExist(): void
    {
        $this->makeProject();
        $this->makeTemplate('blog', 'full');
        $this->guard()->set('blog', 2);

        $this->post('/admin/projects/blog/delete', []);

        self::assertResponseRedirects('/admin/projects/blog', 303);
        self::assertNotNull($this->stored('blog'));
        $crawler = $this->client->followRedirect();
        $flash = $crawler->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('2 instance', $flash);
        self::assertStringContainsString('blog--i1', $flash);
        self::assertStringContainsString('blog--i2', $flash);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM template'));
    }

    public function testDeleteTwiceIsA404(): void
    {
        $this->makeProject();

        $this->post('/admin/projects/blog/delete', []);
        self::assertResponseRedirects('/admin/projects', 303);

        $this->post('/admin/projects/blog/delete', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteNeedsAValidSameOriginRequest(): void
    {
        $this->makeProject();

        $this->post('/admin/projects/blog/delete', [], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->stored('blog'));

        // No token at all.
        $this->client->request('POST', self::BASE.'/admin/projects/blog/delete', [], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->stored('blog'));

        // A token that is neither the double-submit value nor long enough.
        $this->client->request('POST', self::BASE.'/admin/projects/blog/delete', ['_token' => 'x'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->stored('blog'));
    }

    public function testDeleteIsPostOnly(): void
    {
        $this->makeProject();

        $this->open('/admin/projects/blog/delete');

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->stored('blog'));
    }

    public function testCrossSiteSaveChangesNothing(): void
    {
        $this->makeProject();
        $crawler = $this->open('/admin/projects/blog');
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['project']['defaultBranch'] = 'evil';

        $this->client->request('POST', self::BASE.'/admin/projects/blog', $values, [], $this->browserServer('cross-site'));

        self::assertResponseStatusCodeSame(422);
        self::assertNull(($this->stored('blog') ?? self::fail())->defaultBranch());
    }

    // --- odd URLs

    #[DataProvider('oddPaths')]
    public function testUnknownOrOddNamesAre404(string $path): void
    {
        $this->makeProject();

        $this->open($path);

        self::assertResponseStatusCodeSame(404, $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oddPaths(): iterable
    {
        yield 'unknown' => ['/admin/projects/nope'];
        yield 'uppercase' => ['/admin/projects/Nope'];
        yield 'encoded dots' => ['/admin/projects/a%2e%2e'];
        yield 'unknown ports' => ['/admin/projects/nope/ports'];
        yield 'unknown sub page' => ['/admin/projects/blog/nope'];
        yield 'trailing slash' => ['/admin/projects/blog/ports/x'];
    }

    public function testUnknownProjectWritesAre404(): void
    {
        $this->post('/admin/projects/nope/delete', []);
        self::assertResponseStatusCodeSame(404);

        $this->post('/admin/projects/nope', []);
        self::assertResponseStatusCodeSame(404);
    }

    // --- access

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'list' => ['GET', '/admin/projects'];
        yield 'new' => ['GET', '/admin/projects/new'];
        yield 'create' => ['POST', '/admin/projects/new'];
        yield 'edit' => ['GET', '/admin/projects/blog'];
        yield 'save' => ['POST', '/admin/projects/blog'];
        yield 'ports' => ['GET', '/admin/projects/blog/ports'];
        yield 'delete' => ['POST', '/admin/projects/blog/delete'];
    }

    #[DataProvider('routes')]
    public function testMemberGets403OnEveryRoute(string $method, string $path): void
    {
        $this->makeProject();
        $this->as('member');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->stored('blog'));
        self::assertSame(1, $this->projectCount());
    }

    #[DataProvider('routes')]
    public function testAnonymousGets401OnEveryRoute(string $method, string $path): void
    {
        $this->makeProject();
        $this->as('none');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(401);
        self::assertNotNull($this->stored('blog'));
    }

    #[DataProvider('routes')]
    public function testAdminReachesEveryRoute(string $method, string $path): void
    {
        $this->makeProject();

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        // GETs render, a bare POST to a form is a 422 (nothing valid submitted), delete redirects.
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 303, 422], $method.' '.$path);
    }
}
