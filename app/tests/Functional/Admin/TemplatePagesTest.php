<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\Rule\DrupalEnv;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class TemplatePagesTest extends AdminTestCase
{
    private const string LIST = '/admin/projects/blog/templates';

    protected function setUp(): void
    {
        parent::setUp();
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
    }

    private function makeTemplate(string $name, ?TemplateInput $input = null): void
    {
        $service = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $service);
        $service->create('blog', $input ?? new TemplateInput($name));
    }

    private function project(): Project
    {
        return new ProjectRepository($this->em())->findByName('blog') ?? self::fail('the project exists');
    }

    private function stored(string $name): ?Template
    {
        $repository = new TemplateRepository($this->em());

        return $repository->find($this->project(), $name);
    }

    private function templateCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM template');
    }

    private function setDefault(string $name): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->update('blog', ProjectInput::fromProject($service->get('blog'))->with(['defaultTemplate' => $name]));
    }

    /**
     * A new-template submission with raw values (the DomCrawler form refuses control characters and invalid UTF-8, a
     * crafted request does not).
     *
     * @param array<string, string> $fields
     */
    private function rawCreate(array $fields): Crawler
    {
        $crawler = $this->open(self::LIST.'/new');
        $values = $crawler->selectButton('Create template')->form()->getPhpValues();
        $values['template'] = $fields + $values['template'];

        return $this->client->request('POST', self::BASE.self::LIST.'/new', $values, [], $this->browserServer());
    }

    // --- list

    public function testListEmptyStateAndRows(): void
    {
        $crawler = $this->open(self::LIST);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=templates-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=template-row]'));
        self::assertSame('/admin/projects/blog/templates/new', $crawler->filter('main a:contains("New template")')->first()->attr('href'));

        $this->makeTemplate('full', new TemplateInput('full', 'production', ['composer install', 'drush cr'], ['npm run dev'], []));
        $this->makeTemplate('light');
        $this->setDefault('light');

        $crawler = $this->open(self::LIST);
        self::assertCount(0, $crawler->filter('[data-testid=templates-empty]'));
        $rows = $crawler->filter('[data-testid=template-row]');
        self::assertCount(2, $rows);

        $full = $rows->eq(0);
        self::assertSame('full', trim($full->filter('[data-testid=template-name]')->text()));
        self::assertSame('production', trim($full->filter('[data-testid=template-env]')->text()));
        self::assertSame('2', trim($full->filter('[data-testid=template-post-deploy]')->text()));
        self::assertSame('1', trim($full->filter('[data-testid=template-tty1]')->text()));
        self::assertSame('0', trim($full->filter('[data-testid=template-tty2]')->text()));
        self::assertCount(0, $full->filter('[data-testid=template-default]'));
        self::assertSame('/admin/projects/blog/templates/full', $full->filter('a')->attr('href'));

        $light = $rows->eq(1);
        self::assertSame('-', trim($light->filter('[data-testid=template-env]')->text()));
        self::assertSame('Default', trim($light->filter('[data-testid=template-default]')->text()));
        self::assertSame('/admin/projects/blog/templates/light', $light->filter('a')->attr('href'));
    }

    // --- create

    public function testCreateStoresCommandsOneLineEach(): void
    {
        $crawler = $this->open(self::LIST.'/new');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('blog', trim($crawler->filter('main h1')->text()));
        self::assertSame('New template', trim($crawler->filter('[data-testid=template-heading]')->text()));

        $this->submit($crawler, 'Create template', [
            'template[name]' => '  full ',
            'template[drupalEnv]' => ' production ',
            'template[postDeploy]' => "  composer install  \r\n\r\n   \r\ndrush cr\r\ncomposer install\n",
            'template[tty1]' => "npm run dev\n",
            'template[tty2]' => '',
        ]);

        self::assertResponseRedirects(self::LIST, 303);
        $template = $this->stored('full') ?? self::fail('the template is stored');
        self::assertSame('production', $template->drupalEnv());
        self::assertSame(['composer install', 'drush cr', 'composer install'], $template->postDeploy());
        self::assertSame(['npm run dev'], $template->tty1());
        self::assertSame([], $template->tty2());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Template created.', $crawler->filter('[data-testid=flashes]')->text());
    }

    public function testCreateWithOnlyAName(): void
    {
        $crawler = $this->open(self::LIST.'/new');
        $this->submit($crawler, 'Create template', ['template[name]' => 'bare']);

        self::assertResponseRedirects(self::LIST, 303);
        $template = $this->stored('bare') ?? self::fail();
        self::assertNull($template->drupalEnv());
        self::assertSame([], $template->postDeploy());
    }

    public function testTextareasAreMonospaceCommandBoxes(): void
    {
        $crawler = $this->open(self::LIST.'/new');

        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            $box = $crawler->filter('#template_'.$field);
            self::assertSame('textarea', $box->nodeName(), $field);
            self::assertStringContainsString('font-mono', (string) $box->attr('class'), $field);
            self::assertSame('false', $box->attr('spellcheck'), $field);
            self::assertSame('off', $box->attr('wrap'), $field);
        }
        self::assertSame('8', $crawler->filter('#template_postDeploy')->attr('rows'));
        self::assertStringContainsString('DRUPAL_ENV=<value>', $crawler->filter('#template_drupalEnv_help')->text());
        self::assertStringContainsString('aborts the deploy', $crawler->filter('#template_postDeploy_help')->text());
        self::assertStringContainsString('middle terminal pane', $crawler->filter('#template_tty1_help')->text());
        self::assertStringContainsString('right terminal pane', $crawler->filter('#template_tty2_help')->text());
        self::assertNotNull($crawler->filter('main form[data-testid=template-form]')->attr('novalidate'));
    }

    // --- edit

    public function testEditShowsTheStoredLinesAndSaves(): void
    {
        $this->makeTemplate('full', new TemplateInput('full', 'production', ['composer install', 'drush cr'], ['npm run dev'], ['tail -f log']));

        $crawler = $this->open(self::LIST.'/full');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[name="template[name]"]'), 'the name is read-only');
        self::assertSame('Template full', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-testid=template-heading]')->text())));
        self::assertSame('production', $crawler->filter('#template_drupalEnv')->attr('value'));
        $fields = $crawler->selectButton('Save')->form()->getValues();
        self::assertSame("composer install\ndrush cr", str_replace("\r\n", "\n", $fields['template[postDeploy]']));
        self::assertSame('npm run dev', $fields['template[tty1]']);
        self::assertSame('tail -f log', $fields['template[tty2]']);

        $this->submit($crawler, 'Save', [
            'template[drupalEnv]' => '',
            'template[postDeploy]' => "drush updb -y\r\ndrush cr",
            'template[tty1]' => '',
            'template[tty2]' => "  htop \n",
        ]);

        self::assertResponseRedirects(self::LIST, 303);
        $template = $this->stored('full') ?? self::fail();
        self::assertNull($template->drupalEnv());
        self::assertSame(['drush updb -y', 'drush cr'], $template->postDeploy());
        self::assertSame([], $template->tty1());
        self::assertSame(['htop'], $template->tty2());
        self::assertStringContainsString('Template saved.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
    }

    public function testTheNameCannotBeChangedByACraftedRequest(): void
    {
        $this->makeTemplate('full');

        $crawler = $this->open(self::LIST.'/full');
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['template']['name'] = 'other';
        $this->client->request('POST', self::BASE.self::LIST.'/full', $values, [], $this->browserServer());

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->stored('other'));
        self::assertNotNull($this->stored('full'));
    }

    // --- errors

    public function testBadCommandsGiveLevelledFieldErrors(): void
    {
        $crawler = $this->open(self::LIST.'/new');
        $crawler = $this->submit($crawler, 'Create template', [
            'template[name]' => 'full',
            'template[postDeploy]' => "ok\n".str_repeat('a', CommandLines::MAX_LINE + 1),
            'template[tty1]' => implode("\n", array_map(static fn (int $i): string => 'cmd'.$i, range(1, CommandLines::MAX_LINES + 1))),
            'template[tty2]' => "echo\tfoo",
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->templateCount());
        self::assertStringContainsString('line 2', $crawler->filter('#template_postDeploy_errors')->text());
        self::assertStringContainsString('At most 50', $crawler->filter('#template_tty1_errors')->text());
        self::assertStringContainsString('line 1', $crawler->filter('#template_tty2_errors')->text());
        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            self::assertSame('true', $crawler->filter('#template_'.$field)->attr('aria-invalid'), $field);
            self::assertStringContainsString('template_'.$field.'_errors', (string) $crawler->filter('#template_'.$field)->attr('aria-describedby'), $field);
        }
        // What was typed comes back for correction.
        self::assertStringContainsString('cmd51', $crawler->filter('#template_tty1')->text());
    }

    public function testALongFirstLineNamesLineOne(): void
    {
        $crawler = $this->rawCreate(['name' => 'full', 'tty1' => str_repeat('x', CommandLines::MAX_LINE + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('line 1', $crawler->filter('#template_tty1_errors')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileTexts(): iterable
    {
        yield 'NUL' => ["echo a\0b"];
        yield 'invalid UTF-8' => ["echo \xC3\x28"];
        yield 'escape' => ["echo \x1b[31m"];
        yield 'line separator' => ["echo a\u{2028}rm -rf x"];
        yield 'a megabyte' => [str_repeat("a\n", 500_000)];
    }

    #[DataProvider('hostileTexts')]
    public function testHostileCommandTextIsAFieldErrorNot500(string $text): void
    {
        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            $crawler = $this->rawCreate(['name' => 'full', $field => $text]);

            self::assertResponseStatusCodeSame(422, $field);
            self::assertCount(1, $crawler->filter('#template_'.$field.'_errors'), $field);
            self::assertSame(0, $this->templateCount(), $field);
        }
    }

    public function testBadDrupalEnvIsAFieldError(): void
    {
        $crawler = $this->open(self::LIST.'/new');
        $crawler = $this->submit($crawler, 'Create template', ['template[name]' => 'full', 'template[drupalEnv]' => 'a b']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame((string) DrupalEnv::error('a b'), trim($crawler->filter('#template_drupalEnv_errors')->text()));
        self::assertSame('true', $crawler->filter('#template_drupalEnv')->attr('aria-invalid'));
        self::assertSame('a b', $crawler->filter('#template_drupalEnv')->attr('value'));
        self::assertSame(0, $this->templateCount());
    }

    public function testBadEditKeepsTheStoredTemplate(): void
    {
        $this->makeTemplate('full', new TemplateInput('full', 'production', ['drush cr']));

        $crawler = $this->open(self::LIST.'/full');
        $this->submit($crawler, 'Save', ['template[drupalEnv]' => '$(x)', 'template[postDeploy]' => '']);

        self::assertResponseStatusCodeSame(422);
        $template = $this->stored('full') ?? self::fail();
        self::assertSame('production', $template->drupalEnv());
        self::assertSame(['drush cr'], $template->postDeploy());
    }

    public function testDuplicateAndReservedNames(): void
    {
        $this->makeTemplate('full');

        foreach (['full' => 'already exists', 'new' => 'reserved', 'Bad_Name' => ''] as $name => $needle) {
            $crawler = $this->open(self::LIST.'/new');
            $crawler = $this->submit($crawler, 'Create template', ['template[name]' => $name]);

            self::assertResponseStatusCodeSame(422, $name);
            self::assertCount(1, $crawler->filter('#template_name_errors'), $name);
            self::assertStringContainsString($needle, $crawler->filter('#template_name_errors')->text(), $name);
        }
        self::assertSame(1, $this->templateCount());
    }

    public function testHostileValuesAreEscapedOnTheErrorPage(): void
    {
        $crawler = $this->open(self::LIST.'/new');
        $this->submit($crawler, 'Create template', [
            'template[name]' => '<script>alert(1)</script>',
            'template[drupalEnv]' => '"><img src=x onerror=alert(2)>',
            'template[postDeploy]' => "</textarea><script>alert(3)</script>\n\"onfocus=\"alert(4)",
            'template[tty1]' => '</textarea><svg onload=alert(5)>',
            'template[tty2]' => '<i>x</i>',
        ]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->body();
        foreach (['<script>alert', '<img src=x', '<svg onload', '<i>x'] as $raw) {
            self::assertStringNotContainsString($raw, $body, $raw.' must be escaped');
        }
        self::assertStringNotContainsString('" onfocus', $body);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(3)', $body);
        self::assertSame('<script>alert(1)</script>', $this->client->getCrawler()->filter('#template_name')->attr('value'));
        self::assertCount(0, $this->client->getCrawler()->filter('main script, main img, main svg[onload], main [onerror], main [onfocus]'));
        self::assertSame(0, $this->templateCount());
    }

    public function testStoredCommandsAreRenderedAsTextOnTheEditPage(): void
    {
        $this->makeTemplate('full', new TemplateInput('full', null, ['</textarea><script>alert(1)</script>', '$(rm -rf /) `x` ; echo "&amp;"']));

        $crawler = $this->open(self::LIST.'/full');
        $body = $this->body();

        self::assertStringNotContainsString('<script>alert', $body);
        self::assertSame("</textarea><script>alert(1)</script>\n\$(rm -rf /) `x` ; echo \"&amp;\"", str_replace("\r\n", "\n", $crawler->selectButton('Save')->form()->getValues()['template[postDeploy]']));
    }

    // --- default template and delete

    public function testDefaultTemplateHasABadgeAndCannotBeDeleted(): void
    {
        $this->makeTemplate('full');
        $this->makeTemplate('light');
        $this->setDefault('full');

        $this->post(self::LIST.'/full/delete', []);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNotNull($this->stored('full'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('default template', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('full', trim($crawler->filter('[data-testid=template-default]')->ancestors()->filter('[data-testid=template-name]')->text()));
        self::assertSame('full', $this->project()->defaultTemplate());

        // Another default frees it.
        $this->setDefault('light');
        $this->post(self::LIST.'/full/delete', []);
        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->stored('full'));
    }

    public function testDeleteRemovesATemplate(): void
    {
        $this->makeTemplate('full');
        $this->makeTemplate('light');

        $crawler = $this->open(self::LIST.'/full');
        $form = $crawler->filter('form[action="'.self::LIST.'/full/delete"]');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[name=_token]'));
        $this->client->submit($form->selectButton('Delete template')->form());

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->stored('full'));
        self::assertNotNull($this->stored('light'));
        self::assertStringContainsString('Template deleted.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
    }

    public function testDeleteTwiceIsA404(): void
    {
        $this->makeTemplate('full');

        $this->post(self::LIST.'/full/delete', []);
        self::assertResponseRedirects(self::LIST, 303);

        $this->post(self::LIST.'/full/delete', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteNeedsAValidSameOriginRequest(): void
    {
        $this->makeTemplate('full');

        $this->post(self::LIST.'/full/delete', [], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::BASE.self::LIST.'/full/delete', [], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::BASE.self::LIST.'/full/delete', ['_token' => 'x'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($this->stored('full'));
    }

    public function testDeleteIsPostOnly(): void
    {
        $this->makeTemplate('full');

        $this->open(self::LIST.'/full/delete');

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->stored('full'));
    }

    // --- odd URLs

    #[DataProvider('oddPaths')]
    public function testUnknownTemplateOrProjectIs404(string $path): void
    {
        $this->makeTemplate('full');

        $this->open($path);

        self::assertResponseStatusCodeSame(404, $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oddPaths(): iterable
    {
        yield 'unknown template' => ['/admin/projects/blog/templates/nope'];
        yield 'uppercase template' => ['/admin/projects/blog/templates/Full'];
        yield 'encoded dots' => ['/admin/projects/blog/templates/a%2e%2e'];
        yield 'unknown project list' => ['/admin/projects/nope/templates'];
        yield 'unknown project new' => ['/admin/projects/nope/templates/new'];
        yield 'unknown project template' => ['/admin/projects/nope/templates/full'];
        yield 'uppercase project' => ['/admin/projects/Blog/templates'];
        yield 'extra segment' => ['/admin/projects/blog/templates/full/x'];
    }

    public function testUnknownWritesAre404(): void
    {
        $this->makeTemplate('full');

        foreach (['/admin/projects/nope/templates/new', '/admin/projects/nope/templates/full', '/admin/projects/nope/templates/full/delete', '/admin/projects/blog/templates/nope', '/admin/projects/blog/templates/nope/delete'] as $path) {
            $this->post($path, []);
            self::assertResponseStatusCodeSame(404, $path);
        }
        self::assertSame(1, $this->templateCount());
    }

    public function testATemplateOfAnotherProjectIsNotReachable(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
        $this->makeTemplate('full');

        $this->open('/admin/projects/shop/templates/full');
        self::assertResponseStatusCodeSame(404);

        $this->post('/admin/projects/shop/templates/full/delete', []);
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->stored('full'));
    }

    // --- layout

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'list' => [self::LIST];
        yield 'new' => [self::LIST.'/new'];
        yield 'edit' => [self::LIST.'/full'];
    }

    #[DataProvider('pages')]
    public function testPageHasOneH1AndTheSameTabs(string $path): void
    {
        $this->makeTemplate('full');

        $crawler = $this->open($path);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main#main'));

        $tabs = $crawler->filter('nav[aria-label="Project sections"]');
        self::assertCount(1, $tabs);
        self::assertSame(['General', 'Templates', 'Ports'], array_map('trim', $tabs->filter('a')->each(static fn (Crawler $a): string => $a->text())));
        self::assertSame(
            ['/admin/projects/blog', '/admin/projects/blog/templates', '/admin/projects/blog/ports'],
            $tabs->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')),
        );
        self::assertSame('Templates', trim($tabs->filter('a[aria-current="page"]')->text()));
        self::assertSame(self::LIST, $crawler->filter('select[aria-label="Section"] option[selected]')->attr('value'));
    }

    #[DataProvider('pages')]
    public function testNavigationMarksProjectsAsCurrent(string $path): void
    {
        $this->makeTemplate('full');

        $current = $this->open($path)->filter('nav[aria-label="Main"] a[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('Projects', trim($current->text()));
    }

    #[DataProvider('pages')]
    public function testAPlainGetStartsNoSession(string $path): void
    {
        $this->makeTemplate('full');
        $this->client->getCookieJar()->clear();
        $this->open($path);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->client->getResponse()->headers->getCookies());
    }

    public function testLongValuesWrapInsteadOfWideningThePage(): void
    {
        $env = str_repeat('e', 64);
        $name = str_repeat('n', 40);
        $this->makeTemplate($name, new TemplateInput($name, $env));

        $crawler = $this->open(self::LIST);
        // Cells scroll inside the table container, never the page.
        self::assertSame('table-container', $crawler->filter('main table')->ancestors()->first()->attr('data-slot'));
        self::assertStringContainsString('overflow-x-auto', (string) $crawler->filter('main table')->ancestors()->first()->attr('class'));

        $heading = $this->open(self::LIST.'/'.$name)->filter('[data-testid=template-heading]');
        self::assertStringContainsString('[overflow-wrap:anywhere]', (string) $heading->attr('class'));
        self::assertStringContainsString($name, $heading->text());
    }

    public function testEveryControlIsAFullTapTarget(): void
    {
        $this->makeTemplate('full');

        foreach ([self::LIST => ['a:contains("New template")', 'a:contains("Edit")'], self::LIST.'/new' => ['button[type=submit]', 'a:contains("Cancel")'], self::LIST.'/full' => ['button[type=submit]', 'a:contains("Cancel")', 'button:contains("Delete this template")']] as $path => $selectors) {
            $crawler = $this->open($path);
            foreach ($selectors as $selector) {
                $nodes = $crawler->filter('main '.$selector);
                self::assertGreaterThan(0, $nodes->count(), $path.' '.$selector);
                foreach ($nodes as $node) {
                    self::assertStringContainsString('h-11', (string) new Crawler($node)->attr('class'), $path.' '.$selector);
                }
            }
        }
    }

    // --- access

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'list' => ['GET', self::LIST];
        yield 'new' => ['GET', self::LIST.'/new'];
        yield 'create' => ['POST', self::LIST.'/new'];
        yield 'edit' => ['GET', self::LIST.'/full'];
        yield 'save' => ['POST', self::LIST.'/full'];
        yield 'delete' => ['POST', self::LIST.'/full/delete'];
    }

    #[DataProvider('routes')]
    public function testMemberGets403OnEveryTemplateRoute(string $method, string $path): void
    {
        $this->makeTemplate('full');
        $this->as('member');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->templateCount());
        self::assertNotNull($this->stored('full'));
    }

    #[DataProvider('routes')]
    public function testAnonymousGets401OnEveryTemplateRoute(string $method, string $path): void
    {
        $this->makeTemplate('full');
        $this->as('none');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(401);
        self::assertNotNull($this->stored('full'));
    }

    #[DataProvider('routes')]
    public function testAdminReachesEveryTemplateRoute(string $method, string $path): void
    {
        $this->makeTemplate('full');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 303, 422], $method.' '.$path);
    }

    public function testCrossSiteSaveChangesNothing(): void
    {
        $this->makeTemplate('full', new TemplateInput('full', 'production'));
        $crawler = $this->open(self::LIST.'/full');
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['template']['drupalEnv'] = 'evil';

        $this->client->request('POST', self::BASE.self::LIST.'/full', $values, [], $this->browserServer('cross-site'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('production', ($this->stored('full') ?? self::fail())->drupalEnv());
    }
}
