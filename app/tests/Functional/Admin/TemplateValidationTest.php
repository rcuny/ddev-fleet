<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every message the template form can show, from the Rule classes and TemplateService, on the create form and the edit
 * form. They moved here from the Playwright spec (FLE-127), which keeps only what a browser adds (accessibility,
 * overflow). A refused form answers 422, keeps what was typed and stores nothing.
 */
final class TemplateValidationTest extends AdminTestCase
{
    private const string LIST = '/admin/projects/blog/templates';

    private const string NAME_RULE = 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';
    private const string ENV_RULE = 'Use 1 to 64 letters, digits, dots, underscores or dashes.';
    private const string TAB_RULE = 'contains a control character (a command is exactly one line, without tabs).';
    private const string MAX_COMMANDS = 'At most 50 commands are allowed.';

    protected function setUp(): void
    {
        parent::setUp();
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $service->create(new ProjectInput('shop', 'https://git.example.com/acme/shop.git'));
    }

    private function makeTemplate(string $name, ?TemplateInput $input = null, string $project = 'blog'): void
    {
        $service = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $service);
        $service->create($project, $input ?? new TemplateInput($name));
    }

    private function project(string $name = 'blog'): Project
    {
        return new ProjectRepository($this->em())->findByName($name) ?? self::fail('the project exists');
    }

    private function stored(string $name, string $project = 'blog'): ?Template
    {
        return new TemplateRepository($this->em())->find($this->project($project), $name);
    }

    private function templateCount(string $project = 'blog'): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM template WHERE project_id = ?', [$this->project($project)->id()]);
    }

    /**
     * @param array<string, mixed> $fields template form field name (without the "template[...]" wrapper) => value
     *
     * @return array<string, mixed>
     */
    private static function wrap(array $fields): array
    {
        $values = [];
        foreach ($fields as $name => $value) {
            $values['template['.$name.']'] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(array $fields, string $project = 'blog'): Crawler
    {
        $crawler = $this->open('/admin/projects/'.$project.'/templates/new');

        return $this->submit($crawler, 'Create template', self::wrap($fields));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function edit(array $fields, string $name = 'web'): Crawler
    {
        $crawler = $this->open(self::LIST.'/'.$name);

        return $this->submit($crawler, 'Save', self::wrap($fields));
    }

    /**
     * The field's wrapper (label, widget, help, error), found by the field id the form gives it.
     */
    private function row(Crawler $crawler, string $field): string
    {
        return $crawler->filter('#template_'.$field)->ancestors()->first()->text();
    }

    /**
     * @return array<string, mixed> the values of the form as the page now shows them
     */
    private function shown(Crawler $crawler, string $button): array
    {
        return $crawler->selectButton($button)->form()->getValues();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}> fields, form field the message belongs to, message
     */
    public static function refusedOnCreate(): iterable
    {
        $lines = static fn (int $count): string => implode("\n", array_map(static fn (int $i): string => 'cmd '.$i, range(0, $count - 1)));

        yield 'empty name' => [['name' => ''], 'name', 'Enter a name.'];
        yield 'blank name' => [['name' => '   '], 'name', 'Enter a name.'];
        yield 'uppercase name' => [['name' => 'Web'], 'name', self::NAME_RULE];
        yield 'name with a space' => [['name' => 'my web'], 'name', self::NAME_RULE];
        yield 'name with an underscore' => [['name' => 'my_web'], 'name', self::NAME_RULE];
        yield 'name starting with a dash' => [['name' => '-web'], 'name', self::NAME_RULE];
        yield 'name ending with a dash' => [['name' => 'web-'], 'name', self::NAME_RULE];
        yield 'name with a double dash' => [['name' => 'my--web'], 'name', 'The name must not contain two dashes in a row ("--" separates the project from the instance label).'];
        yield 'name of 41 characters' => [['name' => str_repeat('a', 41)], 'name', 'The name must be at most 40 characters.'];
        yield 'reserved name' => [['name' => 'new'], 'name', 'This name is reserved.'];
        yield 'env with a space' => [['name' => 'ok', 'drupalEnv' => 'a b'], 'drupalEnv', self::ENV_RULE];
        yield 'env with a quote' => [['name' => 'ok', 'drupalEnv' => 'a"b'], 'drupalEnv', self::ENV_RULE];
        yield 'env with a shell metacharacter' => [['name' => 'ok', 'drupalEnv' => 'dev;rm'], 'drupalEnv', self::ENV_RULE];
        yield 'env of 65 characters' => [['name' => 'ok', 'drupalEnv' => str_repeat('e', 65)], 'drupalEnv', self::ENV_RULE];
        yield 'post-deploy line with an inner tab' => [['name' => 'ok', 'postDeploy' => "good\nbad\tline"], 'postDeploy', 'Command line 2 '.self::TAB_RULE];
        yield 'terminal 1 line with an escape character' => [['name' => 'ok', 'tty1' => "a\u{1b}b"], 'tty1', 'Command line 1 '.self::TAB_RULE];
        yield 'terminal 2 line with a zero-width space' => [['name' => 'ok', 'tty2' => "one\ntwo\nth\u{200b}ree"], 'tty2', 'Command line 3 '.self::TAB_RULE];
        yield 'terminal 2 line with a line separator' => [['name' => 'ok', 'tty2' => "a\u{2028}b"], 'tty2', 'Command line 1 '.self::TAB_RULE];
        yield '51 post-deploy lines' => [['name' => 'ok', 'postDeploy' => $lines(51)], 'postDeploy', self::MAX_COMMANDS];
        yield '51 terminal 1 lines' => [['name' => 'ok', 'tty1' => $lines(51)], 'tty1', self::MAX_COMMANDS];
        yield 'a command line of 2001 characters' => [['name' => 'ok', 'postDeploy' => "ok\n".str_repeat('x', 2001)], 'postDeploy', 'Command line 2 is longer than 2000 characters.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('refusedOnCreate')]
    public function testTheCreateFormRefuses(array $fields, string $field, string $message): void
    {
        $crawler = $this->create($fields);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->row($crawler, $field));
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
        // What was typed is still there (the name only when it was not blank).
        $name = $fields['name'] ?? '';
        if ('' !== trim((string) $name)) {
            self::assertSame($name, $this->shown($crawler, 'Create template')['template[name]']);
        }
        // Nothing was stored.
        self::assertSame(0, $this->templateCount());
    }

    public function testAllRefusalsShowAtOnceAndFixingTheFieldsLetsTheFormThrough(): void
    {
        $crawler = $this->create(['name' => 'Bad Name', 'drupalEnv' => 'no good', 'postDeploy' => "a\tb", 'tty1' => "c\td", 'tty2' => "e\tf"]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::NAME_RULE, $this->row($crawler, 'name'));
        self::assertStringContainsString(self::ENV_RULE, $this->row($crawler, 'drupalEnv'));
        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            self::assertStringContainsString('Command line 1 '.self::TAB_RULE, $this->row($crawler, $field), $field);
        }

        // Fixing the fields one by one: the form then goes through.
        $this->submit($crawler, 'Create template', self::wrap(['name' => 'fixed', 'drupalEnv' => 'dev', 'postDeploy' => 'a b', 'tty1' => 'c d', 'tty2' => 'e f']));
        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Template created.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        self::assertNotNull($this->stored('fixed'));
    }

    public function testTheInputsCarryTheLengthLimitsTheRulesEnforce(): void
    {
        $crawler = $this->open(self::LIST.'/new');

        self::assertSame('40', $crawler->filter('#template_name')->attr('maxlength'));
        self::assertSame('none', $crawler->filter('#template_name')->attr('autocapitalize'));
        self::assertNotSame('true', $crawler->filter('#template_name')->attr('required'));
        self::assertSame('64', $crawler->filter('#template_drupalEnv')->attr('maxlength'));
        foreach (['postDeploy', 'tty1', 'tty2'] as $field) {
            self::assertSame((string) (50 * 2002), $crawler->filter('#template_'.$field)->attr('maxlength'), $field);
        }
        self::assertSame('off', $crawler->filter('#template_postDeploy')->attr('wrap'));
    }

    public function testANameThatExistsInTheProjectIsRefusedTheSameNameInAnotherProjectIsFine(): void
    {
        $this->makeTemplate('web');

        $crawler = $this->create(['name' => 'web', 'drupalEnv' => 'other']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A template with this name already exists in this project.', $this->row($crawler, 'name'));
        self::assertSame(1, $this->templateCount());
        self::assertNull(($this->stored('web') ?? self::fail())->drupalEnv(), 'the first template is untouched');

        $this->create(['name' => 'web'], 'shop');
        self::assertResponseRedirects('/admin/projects/shop/templates', 303);
        self::assertNotNull($this->stored('web', 'shop'));
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $name = 'a'.str_repeat('b', 38).'c';
        $postDeploy = implode("\n", array_map(static fn (int $i): string => 'cmd '.$i, range(0, 49)));

        $this->create(['name' => $name, 'drupalEnv' => str_repeat('E', 64), 'postDeploy' => $postDeploy, 'tty1' => str_repeat('x', 2000), 'tty2' => 'echo "a  b"  &&  ls']);

        self::assertResponseRedirects(self::LIST, 303);
        $template = $this->stored($name) ?? self::fail('the template is stored');
        self::assertSame(str_repeat('E', 64), $template->drupalEnv());
        self::assertCount(50, $template->postDeploy());
        self::assertCount(1, $template->tty1());
        self::assertSame(['echo "a  b"  &&  ls'], $template->tty2(), 'inner spaces are kept');

        $this->create(['name' => '1-2-3']);
        self::assertResponseRedirects(self::LIST, 303);
        self::assertNotNull($this->stored('1-2-3'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function refusedOnEdit(): iterable
    {
        yield 'env with a space' => [['drupalEnv' => 'a b'], 'drupalEnv', self::ENV_RULE];
        yield 'env of 65 characters' => [['drupalEnv' => str_repeat('e', 65)], 'drupalEnv', self::ENV_RULE];
        yield 'post-deploy line with a tab' => [['postDeploy' => "a\tb"], 'postDeploy', 'Command line 1 '.self::TAB_RULE];
        yield 'terminal 1 line with a control character' => [['tty1' => "ok\nb\u{7}c"], 'tty1', 'Command line 2 '.self::TAB_RULE];
        yield '51 terminal 2 lines' => [['tty2' => implode("\n", array_map(static fn (int $i): string => 'c'.$i, range(0, 50)))], 'tty2', self::MAX_COMMANDS];
        yield 'a 2001 character line' => [['tty1' => str_repeat('y', 2001)], 'tty1', 'Command line 1 is longer than 2000 characters.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('refusedOnEdit')]
    public function testTheEditFormRefusesAndKeepsTheStoredTemplate(array $fields, string $field, string $message): void
    {
        $this->makeTemplate('web', new TemplateInput('web', 'dev', ['keep me']));

        $crawler = $this->edit($fields);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->row($crawler, $field));
        self::assertCount(0, $crawler->filter('[data-testid=flashes]'));
        $template = $this->stored('web') ?? self::fail();
        self::assertSame('dev', $template->drupalEnv());
        self::assertSame(['keep me'], $template->postDeploy());
    }

    public function testAPostedNameCannotRenameTheTemplate(): void
    {
        $this->makeTemplate('web');

        // The edit form has no Name field; a crafted extra field is not accepted by the form at all.
        $crawler = $this->open(self::LIST.'/web');
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['template']['name'] = 'renamed';
        $this->client->request('POST', self::BASE.self::LIST.'/web', $values, [], $this->browserServer());

        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->stored('web'));
        self::assertNull($this->stored('renamed'));
        self::assertSame(1, $this->templateCount());
    }

    public function testTheDeleteRouteRefusesAMissingOrWrongCsrfToken(): void
    {
        $this->makeTemplate('web');

        $this->client->request('POST', self::BASE.self::LIST.'/web/delete', [], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE.self::LIST.'/web/delete', ['_token' => 'wrong'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($this->stored('web'));
        self::assertCount(1, $this->open(self::LIST)->filter('[data-testid=template-row]'));
    }
}
