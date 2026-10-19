<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Project;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\ProjectSnapshot;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string GIT = 'https://git.example.com/acme/blog.git';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $more
     */
    private function create(string $name = 'blog', array $more = []): CommandTester
    {
        return $this->cli('fleet:project:create', ['name' => $name, '--git' => self::GIT] + $more);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $name): array
    {
        $this->entityManager()->clear();
        $project = self::getContainer()->get(ProjectRepository::class)->findByName($name);
        self::assertInstanceOf(Project::class, $project);

        return ProjectSnapshot::fromProject($project)->toArray();
    }

    private function port(string $name = 'typesense', int $public = 9108, int $router = 8108): void
    {
        self::getContainer()->get(NamedPortService::class)->create(new NamedPortInput($name, $public, $router));
    }

    public function testCreateListShowJsonRoundTrip(): void
    {
        $this->port();
        $created = $this->cli('fleet:project:create', [
            'name' => 'blog',
            '--git' => self::GIT,
            '--default-branch' => 'develop',
            '--hostname' => ['www', 'staging'],
            '--port' => ['typesense'],
            '--git-bot' => 'custom',
            '--git-bot-name' => 'Deploy Bot',
            '--git-bot-email' => 'bot@example.com',
            '--issue-id-regexp' => '(?:ABC|XYZ)-\d{1,6}',
            '--display-submodule-branch' => 'web/modules/custom',
            '--typesense' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertSame('', $created->getErrorOutput());
        $expected = $this->snapshot('blog');
        self::assertSame($expected, $this->json($created));
        self::assertSame('develop', $expected['default_branch']);
        self::assertSame(['www', 'staging'], $expected['additional_hostnames']);
        self::assertSame(['typesense'], $expected['ports']);
        self::assertSame(['mode' => 'override', 'name' => 'Deploy Bot', 'email' => 'bot@example.com'], $expected['git_bot']);
        self::assertSame('(?:ABC|XYZ)-\d{1,6}', $expected['issue_id_regexp']);
        self::assertTrue($expected['typesense']);

        self::assertSame($expected, $this->json($this->cli('fleet:project:show', ['name' => 'blog', '--format' => 'json'])));
        self::assertSame([$expected], $this->json($this->cli('fleet:project:list', ['--json' => true])));
    }

    public function testHumanListIsATable(): void
    {
        $this->create();
        $this->create('shop', ['--default-branch' => 'main']);
        self::getContainer()->get(TemplateService::class)->create('blog', new TemplateInput('web'));

        $list = $this->cli('fleet:project:list');

        self::assertSame(0, $list->getStatusCode());
        $text = $this->squash($list->getDisplay());
        self::assertStringContainsString('NAME', $text);
        self::assertStringContainsString('GIT', $text);
        self::assertStringContainsString('DEFAULT BRANCH', $text);
        self::assertStringContainsString('TEMPLATES', $text);
        self::assertMatchesRegularExpression('#\bblog '.preg_quote(self::GIT, '#').' - 1\b#', $text);
        self::assertMatchesRegularExpression('#\bshop \S+ main 0\b#', $text);
        self::assertSame('', $list->getErrorOutput());
    }

    public function testEmptyListIsStillValid(): void
    {
        self::assertSame([], $this->json($this->cli('fleet:project:list', ['--json' => true])));
        self::assertSame(0, $this->cli('fleet:project:list')->getStatusCode());
    }

    public function testShowPrintsKeyValueLines(): void
    {
        $this->port();
        $this->create('blog', ['--port' => ['typesense'], '--hostname' => ['www'], '--typesense' => true]);

        $show = $this->cli('fleet:project:show', ['name' => 'blog']);

        self::assertSame(0, $show->getStatusCode());
        $text = $show->getDisplay();
        self::assertStringContainsString('name: blog', $text);
        self::assertStringContainsString('git: '.self::GIT, $text);
        self::assertStringContainsString("additional hostnames:\n  - www", $text);
        self::assertStringContainsString("ports:\n  - typesense", $text);
        self::assertStringContainsString('git bot: inherit', $text);
        self::assertStringContainsString('typesense: yes', $text);
        self::assertStringContainsString('default branch: -', $text);
    }

    public function testBadInputPrintsOptionErrorsOnStderrAndExits1(): void
    {
        $bad = $this->cli('fleet:project:create', ['name' => 'Bad', '--git' => 'http://x/y', '--git-bot' => 'custom', '--hostname' => ['a.b'], '--port' => ['nope']]);

        self::assertSame(1, $bad->getStatusCode());
        self::assertSame('', $bad->getDisplay());
        $lines = explode("\n", trim($bad->getErrorOutput()));
        $keys = array_map(static fn (string $line): string => explode(': ', $line, 2)[0], $lines);
        self::assertSame(['<name>', '--git', '--hostname', '--port', '--git-bot-name'], $keys);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^(<name>|--[a-z-]+): \S.*/', $line);
        }
        self::assertSame([], self::getContainer()->get(ProjectRepository::class)->all());
    }

    public function testJsonModeKeepsStdoutValidJsonAndStderrEmpty(): void
    {
        $bad = $this->cli('fleet:project:create', ['name' => 'Bad', '--git' => 'http://x/y', '--json' => true]);

        self::assertSame(1, $bad->getStatusCode());
        self::assertSame('', $bad->getErrorOutput());
        $document = $this->json($bad);
        self::assertSame(['error', 'fields'], array_keys($document));
        self::assertIsString($document['error']);
        self::assertIsArray($document['fields']);
        self::assertSame(['<name>', '--git'], array_keys($document['fields']));

        $this->create();
        foreach ([
            [1, 'fleet:project:create', ['name' => 'blog', '--git' => self::GIT]],
            [3, 'fleet:project:show', ['name' => 'nope']],
            [3, 'fleet:project:update', ['name' => 'nope', '--default-branch' => 'x']],
            [2, 'fleet:project:delete', ['name' => 'blog']],
        ] as [$code, $command, $input]) {
            $tester = $this->cli($command, $input + ['--json' => true]);
            self::assertSame($code, $tester->getStatusCode(), $command);
            self::assertSame('', $tester->getErrorOutput(), $command);
            self::assertSame([], array_diff(['error', 'fields'], array_keys($this->json($tester))), $command);
        }
    }

    public function testCreateRefusesADuplicateNameAsAFieldError(): void
    {
        $this->create();

        $again = $this->create();

        self::assertSame(1, $again->getStatusCode());
        self::assertStringContainsString('<name>: A project with this name already exists.', $again->getErrorOutput());
    }

    public function testUpdateChangesOnlyGivenOptions(): void
    {
        $this->port();
        $this->create('blog', ['--default-branch' => 'develop', '--hostname' => ['www'], '--port' => ['typesense'], '--issue-id-regexp' => 'ABC-\d+', '--typesense' => true]);
        $before = $this->snapshot('blog');

        $update = $this->cli('fleet:project:update', ['name' => 'blog', '--default-branch' => 'main', '--json' => true]);

        self::assertSame(0, $update->getStatusCode(), $update->getErrorOutput());
        $after = $this->snapshot('blog');
        self::assertSame($after, $this->json($update));
        self::assertSame('main', $after['default_branch']);
        $changed = array_keys(array_diff_assoc($after, $before));
        self::assertSame(['default_branch'], array_values(array_diff($changed, ['updated_at'])));
        self::assertSame($before['ports'], $after['ports']);
        self::assertSame($before['additional_hostnames'], $after['additional_hostnames']);
        self::assertSame($before['issue_id_regexp'], $after['issue_id_regexp']);
        self::assertTrue($after['typesense']);

        $this->cli('fleet:project:update', ['name' => 'blog', '--no-typesense' => true]);
        self::assertFalse($this->snapshot('blog')['typesense']);
        $this->cli('fleet:project:update', ['name' => 'blog', '--typesense' => true]);
        self::assertTrue($this->snapshot('blog')['typesense']);
        $this->cli('fleet:project:update', ['name' => 'blog', '--git-bot' => 'off']);
        self::assertSame('disabled', $this->snapshot('blog')['git_bot']['mode']);
        $this->cli('fleet:project:update', ['name' => 'blog', '--git-bot' => 'inherit']);
        self::assertSame('inherit', $this->snapshot('blog')['git_bot']['mode']);
    }

    public function testUpdateSetsTheDefaultTemplateOnceItExists(): void
    {
        $this->create();

        $early = $this->cli('fleet:project:update', ['name' => 'blog', '--default-template' => 'web']);
        self::assertSame(1, $early->getStatusCode());
        self::assertStringContainsString('--default-template:', $early->getErrorOutput());

        self::getContainer()->get(TemplateService::class)->create('blog', new TemplateInput('web'));
        $late = $this->cli('fleet:project:update', ['name' => 'blog', '--default-template' => 'web']);
        self::assertSame(0, $late->getStatusCode(), $late->getErrorOutput());
        self::assertSame('web', $this->snapshot('blog')['default_template']);
    }

    public function testUnsetClearsAndRepeatedHostnameReplacesTheList(): void
    {
        $this->port();
        $this->create('blog', ['--default-branch' => 'develop', '--hostname' => ['www', 'staging'], '--port' => ['typesense'], '--issue-id-regexp' => 'ABC-\d+', '--display-submodule-branch' => 'web/x']);

        $this->cli('fleet:project:update', ['name' => 'blog', '--hostname' => ['only']]);
        self::assertSame(['only'], $this->snapshot('blog')['additional_hostnames']);

        $unset = $this->cli('fleet:project:update', ['name' => 'blog', '--unset' => ['default-branch', 'hostnames', 'ports', 'issue-id-regexp', 'display-submodule-branch']]);
        self::assertSame(0, $unset->getStatusCode(), $unset->getErrorOutput());
        $after = $this->snapshot('blog');
        self::assertNull($after['default_branch']);
        self::assertSame([], $after['additional_hostnames']);
        self::assertSame([], $after['ports']);
        self::assertNull($after['issue_id_regexp']);
        self::assertNull($after['display_submodule_branch']);
    }

    public function testUnsetDefaultTemplate(): void
    {
        $this->create();
        self::getContainer()->get(TemplateService::class)->create('blog', new TemplateInput('web'));
        $this->cli('fleet:project:update', ['name' => 'blog', '--default-template' => 'web']);

        $this->cli('fleet:project:update', ['name' => 'blog', '--unset' => ['default-template']]);

        self::assertNull($this->snapshot('blog')['default_template']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badCliValues(): iterable
    {
        yield 'unknown git bot word' => [['--git-bot' => 'disabled'], '--git-bot'];
        yield 'unknown unset word' => [['--unset' => ['git']], '--unset'];
        yield 'unset together with a value' => [['--unset' => ['hostnames'], '--hostname' => ['www']], '--unset'];
        yield 'a bad git url' => [['--git' => 'ext::sh -c x'], '--git'];
        yield 'a blank git url' => [['--git' => ''], '--git'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('badCliValues')]
    public function testUpdateRefusesBadValuesNamingTheOption(array $options, string $key): void
    {
        $this->create();
        $before = $this->snapshot('blog');

        $bad = $this->cli('fleet:project:update', ['name' => 'blog'] + $options);

        self::assertSame(1, $bad->getStatusCode());
        self::assertStringContainsString($key.': ', $bad->getErrorOutput());
        self::assertSame($before, $this->snapshot('blog'));
    }

    public function testGitBotNameAndEmailNeedTheCustomMode(): void
    {
        $bad = $this->create('blog', ['--git-bot-name' => 'Bob']);
        self::assertSame(1, $bad->getStatusCode());
        self::assertSame("--git-bot-name: Only used with --git-bot=custom.\n", $bad->getErrorOutput());
        self::assertSame([], self::getContainer()->get(ProjectRepository::class)->all());

        $bad = $this->create('blog', ['--git-bot' => 'off', '--git-bot-email' => 'bot@example.com']);
        self::assertSame(1, $bad->getStatusCode());
        self::assertSame("--git-bot-email: Only used with --git-bot=custom.\n", $bad->getErrorOutput());

        $this->create();
        $before = $this->snapshot('blog');
        $bad = $this->cli('fleet:project:update', ['name' => 'blog', '--git-bot-name' => 'Bob']);
        self::assertSame(1, $bad->getStatusCode());
        self::assertStringContainsString('--git-bot-name: Only used with --git-bot=custom.', $bad->getErrorOutput());
        $json = $this->json($this->cli('fleet:project:update', ['name' => 'blog', '--git-bot' => 'inherit', '--git-bot-name' => 'Bob', '--json' => true]));
        self::assertSame(['--git-bot-name'], array_keys($json['fields']));
        self::assertSame($before, $this->snapshot('blog'));
    }

    public function testGitBotNameAndEmailAreAcceptedWhenTheStoredModeIsCustom(): void
    {
        $this->create('blog', ['--git-bot' => 'custom', '--git-bot-name' => 'Bot', '--git-bot-email' => 'bot@example.com']);

        $ok = $this->cli('fleet:project:update', ['name' => 'blog', '--git-bot-name' => 'Other Bot']);

        self::assertSame(0, $ok->getStatusCode(), $ok->getErrorOutput());
        self::assertSame('Other Bot', $this->snapshot('blog')['git_bot']['name']);
    }

    public function testUpdateUnknownProjectExits3(): void
    {
        $update = $this->cli('fleet:project:update', ['name' => 'nope', '--default-branch' => 'main']);

        self::assertSame(3, $update->getStatusCode());
        self::assertSame('Project "nope" does not exist.', trim($update->getErrorOutput()));
    }

    public function testHostileNamesAreNotFoundAndNotEchoedRaw(): void
    {
        $show = $this->cli('fleet:project:show', ['name' => "x\e[31m<info>"]);

        self::assertSame(3, $show->getStatusCode());
        self::assertStringNotContainsString("\e", $show->getErrorOutput());
    }

    public function testDeleteNeedsYes(): void
    {
        $this->create();

        $refused = $this->cli('fleet:project:delete', ['name' => 'blog']);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('--yes', $refused->getErrorOutput());
        self::assertCount(1, self::getContainer()->get(ProjectRepository::class)->all());
    }

    public function testDeleteAsksWhenInteractive(): void
    {
        $this->create();

        $no = $this->cli('fleet:project:delete', ['name' => 'blog'], ['no'], interactive: true);
        self::assertSame(2, $no->getStatusCode());
        self::assertCount(1, self::getContainer()->get(ProjectRepository::class)->all());

        $yes = $this->cli('fleet:project:delete', ['name' => 'blog'], ['yes'], interactive: true);
        self::assertSame(0, $yes->getStatusCode(), $yes->getErrorOutput());
        self::assertSame([], self::getContainer()->get(ProjectRepository::class)->all());
    }

    public function testJsonDeleteNeverPrompts(): void
    {
        $this->create();

        $refused = $this->cli('fleet:project:delete', ['name' => 'blog', '--json' => true], ['yes'], interactive: true);

        self::assertSame(2, $refused->getStatusCode());
        self::assertCount(1, self::getContainer()->get(ProjectRepository::class)->all());
    }

    public function testDeleteRefusedWhileInstancesExist(): void
    {
        $this->create();
        $guard = self::getContainer()->get(FakeInstanceGuard::class);
        self::assertInstanceOf(FakeInstanceGuard::class, $guard);
        $guard->set('blog', 2);

        $refused = $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true]);

        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('2 instance(s) exist', $refused->getErrorOutput());
        self::assertStringContainsString('blog--i1', $refused->getErrorOutput());
        self::assertCount(1, self::getContainer()->get(ProjectRepository::class)->all());

        $guard->set('blog', 0);
        self::assertSame(0, $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true])->getStatusCode());
    }

    public function testDeleteWithYes(): void
    {
        $this->create();
        self::getContainer()->get(TemplateService::class)->create('blog', new TemplateInput('web'));

        $deleted = $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true, '--json' => true]);

        self::assertSame(0, $deleted->getStatusCode(), $deleted->getErrorOutput());
        self::assertSame(['deleted' => 'blog'], $this->json($deleted));
        self::assertSame([], self::getContainer()->get(ProjectRepository::class)->all());
        self::assertSame(3, $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true])->getStatusCode());
    }

    public function testUnknownFormatIsAnOptionError(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->cli('fleet:project:list', ['--format' => 'xml']);
    }

    public function testNothingSecretIsPrinted(): void
    {
        $this->create('blog', ['--git-bot' => 'custom', '--git-bot-email' => 'bot@example.com']);

        $text = $this->cli('fleet:project:show', ['name' => 'blog'])->getDisplay().$this->cli('fleet:project:show', ['name' => 'blog', '--json' => true])->getDisplay();

        self::assertDoesNotMatchRegularExpression('/(token|secret|password|passphrase)/i', $text);
    }
}
