<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SecondWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateServiceTest extends KernelTestCase
{
    use FleetDatabase;
    use SecondWriter;

    private EntityManagerInterface $em;
    private ProjectRepository $projects;
    private TemplateRepository $templates;
    private TemplateService $service;
    private Project $blog;
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle190-tpl-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        $this->em = $this->setUpFleetDatabase();
        $this->projects = new ProjectRepository($this->em);
        $this->templates = new TemplateRepository($this->em);
        $this->service = new TemplateService($this->projects, $this->templates, new \App\Fleet\Ssh\ExtraSshKey($paths = new \App\Fleet\FleetPaths($this->home, $this->home.'/caddy')), new \App\Fleet\Ssh\SshKeysLock($paths));
        $this->blog = new Project('blog', 'https://git.example.com/acme/blog.git');
        $this->projects->save($this->blog);
        $this->projects->save(new Project('shop', 'https://git.example.com/acme/shop.git'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        exec('rm -rf '.escapeshellarg($this->home));
        parent::tearDown();
    }

    public function testCreateStoresCommandsInOrder(): void
    {
        $this->service->create('blog', new TemplateInput(
            name: ' full ',
            drupalEnv: ' dev ',
            postDeploy: ['composer install', ' drush deploy ', '', 'composer install'],
            tty1: ['npm run watch'],
            tty2: ['tail -f log', 'ls'],
        ));
        $this->em->clear();

        $found = $this->templates->find($this->projects->findByName('blog') ?? self::fail(), 'full') ?? self::fail('Not stored.');
        self::assertSame('dev', $found->drupalEnv());
        self::assertSame(['composer install', 'drush deploy', 'composer install'], $found->postDeploy());
        self::assertSame(['npm run watch'], $found->tty1());
        self::assertSame(['tail -f log', 'ls'], $found->tty2());
    }

    public function testBlankDrupalEnvIsNull(): void
    {
        $template = $this->service->create('blog', new TemplateInput('light', '  '));

        self::assertNull($template->drupalEnv());
        self::assertSame([], $template->postDeploy());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function badFields(): iterable
    {
        yield 'newline inside a post deploy line' => [['postDeploy' => ['ok', "a\nb"]], 'postDeploy', 'line 2'];
        yield 'escape in a tty line' => [['tty1' => ["a\e[2Jb"]], 'tty1', 'line 1'];
        yield 'nul in a tty line' => [['tty2' => ['x', 'y', "a\0b"]], 'tty2', 'line 3'];
        yield 'too many lines' => [['postDeploy' => array_fill(0, 51, 'ls')], 'postDeploy', '50'];
        yield 'line too long' => [['tty1' => [str_repeat('a', 2001)]], 'tty1', 'line 1'];
        yield 'invalid utf-8' => [['tty1' => ["a\xC3"]], 'tty1', 'line 1'];
        yield 'drupal env with a space' => [['drupalEnv' => 'dev prod'], 'drupalEnv', ''];
        yield 'drupal env with a newline' => [['drupalEnv' => "dev\nX=1"], 'drupalEnv', ''];
        yield 'drupal env too long' => [['drupalEnv' => str_repeat('a', 65)], 'drupalEnv', ''];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('badFields')]
    public function testFieldErrorsPerList(array $changes, string $field, string $contains): void
    {
        try {
            $this->service->create('blog', new TemplateInput('full')->with($changes));
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame([$field], array_keys($e->errors));
            self::assertStringContainsString($contains, $e->errors[$field]);
        }
        self::assertSame([], $this->templates->forProject($this->blog));
    }

    public function testSeveralListsAreReportedTogether(): void
    {
        try {
            $this->service->create('blog', new TemplateInput('Bad', 'a b', ["x\ny"], ["\e"], ["\0"]));
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'drupalEnv', 'postDeploy', 'tty1', 'tty2'], array_keys($e->errors));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'reserved' => ['new'];
        yield 'capitals' => ['Full'];
        yield 'double dash' => ['a--b'];
        yield 'leading dash' => ['-a'];
        yield 'trailing dash' => ['a-'];
        yield 'dot' => ['a.b'];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'empty' => [''];
        yield 'traversal' => ['a%2e%2e'];
    }

    #[DataProvider('badNames')]
    public function testReservedAndInvalidNames(string $name): void
    {
        try {
            $this->service->create('blog', new TemplateInput($name));
            self::fail('The name was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name'], array_keys($e->errors));
        }
    }

    public function testDuplicateNameIsRefusedWithinAProjectButAllowedAcrossProjects(): void
    {
        $this->service->create('blog', new TemplateInput('full'));
        $this->service->create('shop', new TemplateInput('full'));

        try {
            $this->service->create('blog', new TemplateInput('full'));
            self::fail('A duplicate was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'A template with this name already exists in this project.'], $e->errors);
        }
        self::assertCount(1, $this->service->list('blog'));
        self::assertCount(1, $this->service->list('shop'));
    }

    public function testSimultaneousCreateBecomesAFieldError(): void
    {
        $second = $this->secondEntityManager($this->em);
        $this->beforeNextPersist($this->em, static function () use ($second): void {
            $project = $second->getRepository(Project::class)->findOneBy(['name' => 'blog']) ?? self::fail();
            new TemplateRepository($second)->save(new Template($project, 'full'));
        });

        try {
            $this->service->create('blog', new TemplateInput('full', 'dev'));
            self::fail('The racing create was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'A template with this name already exists in this project.'], $e->errors);
        }

        $rows = $second->getConnection()->fetchAllAssociative('SELECT name, drupal_env FROM template');
        self::assertSame([['name' => 'full', 'drupal_env' => null]], $rows);
    }

    private function key(string $name, int $mode = 0o600): void
    {
        $file = $this->home.'/ssh/'.$name.'_ed25519';
        touch($file);
        chmod($file, $mode);
    }

    public function testExtraSshKeysAreStoredForAProjectWithContainerSsh(): void
    {
        $this->blog->changeContainerSsh(true);
        $this->projects->save($this->blog);
        $this->key('staging');

        $this->service->create('blog', new TemplateInput('full', sshKeys: [' staging ']));
        $this->em->clear();

        self::assertSame(['staging'], $this->service->get('blog', 'full')->sshKeys());
        self::assertSame(['staging'], TemplateInput::fromTemplate($this->service->get('blog', 'full'))->sshKeys);

        $this->service->update('blog', 'full', new TemplateInput('full', sshKeys: []));
        self::assertSame([], $this->service->get('blog', 'full')->sshKeys());
    }

    public function testExtraSshKeysNeedContainerSsh(): void
    {
        $this->key('staging');

        try {
            $this->service->create('blog', new TemplateInput('full', sshKeys: ['staging']));
            self::fail('A key was accepted without container_ssh.');
        } catch (ValidationFailed $e) {
            self::assertSame(['sshKeys'], array_keys($e->errors));
            self::assertStringContainsString('container_ssh', $e->errors['sshKeys']);
        }
    }

    public function testExtraSshKeysAreCheckedOnDisk(): void
    {
        $this->blog->changeContainerSsh(true);
        $this->projects->save($this->blog);

        foreach ([['missing', null], ['loose', 0o644], ['deploy', 0o600], ['push', 0o600], ['Bad_Name', null]] as [$name, $mode]) {
            if (null !== $mode) {
                $this->key($name, $mode);
            }
            $errors = $this->service->validate(new TemplateInput('full', sshKeys: [$name]), true);
            self::assertArrayHasKey('sshKeys', $errors, $name);
        }
        $this->key('staging');
        self::assertSame([], $this->service->validate(new TemplateInput('full', sshKeys: ['staging']), true));
        self::assertArrayHasKey('sshKeys', $this->service->validate(new TemplateInput('full', sshKeys: ['staging']), false));
    }

    public function testMaxFiftyTemplates(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->service->create('blog', new TemplateInput('t'.$i));
        }

        try {
            $this->service->create('blog', new TemplateInput('t51'));
            self::fail('A 51st template was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name'], array_keys($e->errors));
            self::assertStringContainsString('50', $e->errors['name']);
        }
        self::assertCount(50, $this->service->list('blog'));

        $this->service->create('shop', new TemplateInput('t1'));
    }

    public function testListIsOrderedAndFreshAfterWrites(): void
    {
        // The project is loaded (its template collection is empty) before the templates are saved.
        self::assertSame([], $this->blog->templates());
        foreach (['zeta', 'alpha'] as $name) {
            $this->service->create('blog', new TemplateInput($name));
        }

        self::assertSame(['alpha', 'zeta'], array_map(static fn (Template $t): string => $t->name(), $this->service->list('blog')));
    }

    public function testUpdateRewritesCommands(): void
    {
        $this->service->create('blog', new TemplateInput('full', 'dev', ['a', 'b'], ['c'], ['d']));

        $this->service->update('blog', 'full', new TemplateInput('full', null, ['b', 'a'], [], ['e', 'f']));
        $this->em->clear();

        $found = $this->service->get('blog', 'full');
        self::assertNull($found->drupalEnv());
        self::assertSame(['b', 'a'], $found->postDeploy());
        self::assertSame([], $found->tty1());
        self::assertSame(['e', 'f'], $found->tty2());
    }

    public function testUpdateRejectsARenameAndBadValues(): void
    {
        $this->service->create('blog', new TemplateInput('full'));

        try {
            $this->service->update('blog', 'full', new TemplateInput('other', 'a b'));
            self::fail('Accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'The name cannot be changed.', 'drupalEnv' => $e->errors['drupalEnv']], $e->errors);
        }
        self::assertCount(1, $this->service->list('blog'));
    }

    public function testDeletingTheDefaultTemplateIsRefused(): void
    {
        $this->service->create('blog', new TemplateInput('full'));
        $this->service->create('blog', new TemplateInput('light'));
        $this->blog->changeDefaults('develop', 'full');
        $this->projects->save($this->blog);

        try {
            $this->service->delete('blog', 'full');
            self::fail('The default template was deleted.');
        } catch (Refused $e) {
            self::assertStringContainsString('default template', $e->getMessage());
        }
        self::assertCount(2, $this->service->list('blog'));
        self::assertSame('full', ($this->projects->findByName('blog') ?? self::fail())->defaultTemplate());
    }

    public function testDeleteOtherTemplate(): void
    {
        $this->service->create('blog', new TemplateInput('full'));
        $this->service->create('blog', new TemplateInput('light'));
        $this->blog->changeDefaults(null, 'full');
        $this->projects->save($this->blog);

        $this->service->delete('blog', 'light');

        self::assertSame(['full'], array_map(static fn (Template $t): string => $t->name(), $this->service->list('blog')));
    }

    public function testUnknownProjectOrTemplateIsNotFound(): void
    {
        $this->service->create('blog', new TemplateInput('full'));

        foreach ([
            ['Project "nope" does not exist.', fn () => $this->service->list('nope')],
            ['Project "nope" does not exist.', fn () => $this->service->create('nope', new TemplateInput('full'))],
            ['Project "nope" does not exist.', fn () => $this->service->get('nope', 'full')],
            ['Template "nope" does not exist in project "blog".', fn () => $this->service->get('blog', 'nope')],
            ['Template "nope" does not exist in project "blog".', fn () => $this->service->update('blog', 'nope', new TemplateInput('nope'))],
            ['Template "nope" does not exist in project "blog".', fn () => $this->service->delete('blog', 'nope')],
            ['Template "full" does not exist in project "shop".', fn () => $this->service->delete('shop', 'full')],
        ] as [$message, $call]) {
            try {
                $call();
                self::fail('Found: '.$message);
            } catch (NotFound $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testFromTemplateRoundTrip(): void
    {
        $template = $this->service->create('blog', new TemplateInput('full', 'dev', ['a'], ['b'], ['c']));

        self::assertEquals(new TemplateInput('full', 'dev', ['a'], ['b'], ['c']), TemplateInput::fromTemplate($template));
        self::assertSame(['x'], new TemplateInput('full')->with(['tty1' => ['x']])->tty1);
        $this->expectException(\InvalidArgumentException::class);
        new TemplateInput('full')->with(['nope' => 1]);
    }

    public function testPushKeyWarningOnlyForContainerSshAndAnUnregisteredKey(): void
    {
        mkdir($this->home.'/ssh', 0o700, true);
        touch($this->home.'/ssh/push_ed25519');
        $runner = new \App\Fleet\Shell\RecordingCommandRunner();
        $runner->expect(['ssh'], new \App\Fleet\Shell\CommandResult(255, '', 'Permission denied (publickey).'));
        $service = new TemplateService($this->projects, $this->templates, new \App\Fleet\Ssh\ExtraSshKey(new \App\Fleet\FleetPaths($this->home, $this->home.'/caddy')), null, new \App\Fleet\Ssh\PushKeyCheck(new \App\Fleet\FleetPaths($this->home, $this->home.'/caddy'), $runner));

        self::assertNull($service->pushKeyWarning('blog'));
        self::assertSame([], $runner->calls());

        $this->blog->changeContainerSsh(true);
        $this->projects->save($this->blog);
        self::assertStringContainsString('not registered on the git host git.example.com', (string) $service->pushKeyWarning('blog'));
    }
}
