<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceGuard;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Secret\SecretRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Secrets\PlaceholderScanner;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretStore;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\RevealThrottles;
use App\Tests\Support\SecondWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;

final class ProjectServiceTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use SecondWriter;

    private const string GIT = 'https://git.example.com/acme/blog.git';

    private EntityManagerInterface $em;
    private ProjectRepository $projects;
    private NamedPortRepository $ports;
    private TemplateRepository $templates;
    private FakeInstanceGuard $guard;
    private ProjectService $service;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->projects = new ProjectRepository($this->em);
        $this->ports = new NamedPortRepository($this->em);
        $this->templates = new TemplateRepository($this->em);
        $this->guard = new FakeInstanceGuard();
        $this->service = new ProjectService($this->projects, $this->ports, $this->guard, null, new SecretService(
            new SecretStore(new SecretRepository($this->em), new SecretBox(new SecretKeyFile(self::getContainer()->get(FleetPaths::class))), new NativeClock()),
            $this->projects,
            new AuditLog(new AuditEntryRepository($this->em), new NativeClock()),
            new PlaceholderScanner(self::getContainer()->get(FleetPaths::class)),
            RevealThrottles::make(),
        ));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    // --- typesense flag <-> `typesense` port (FLE-92)

    /**
     * @return list<string>
     */
    private function portNamesOf(string $project): array
    {
        $this->em->clear();
        $names = array_map(static fn (NamedPort $p): string => $p->name(), ($this->projects->findByName($project) ?? self::fail())->ports());
        sort($names);

        return $names;
    }

    public function testTheFlagAssignsTheTypesensePortAndClearingItUnassigns(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->ports->save(new NamedPort('mail', 8025, 18025));
        $this->service->create($this->valid()->with(['ports' => ['mail']]));

        $this->service->update('blog', $this->valid()->with(['ports' => ['mail'], 'typesense' => true]));
        self::assertSame(['mail', 'typesense'], $this->portNamesOf('blog'));
        self::assertTrue(($this->projects->findByName('blog') ?? self::fail())->typesense());

        $this->service->update('blog', $this->valid()->with(['ports' => ['mail', 'typesense'], 'typesense' => false]));
        self::assertSame(['mail'], $this->portNamesOf('blog'));
        self::assertFalse(($this->projects->findByName('blog') ?? self::fail())->typesense());
    }

    public function testAssigningOrUnassigningThePortFlipsTheFlag(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->service->create($this->valid());

        $this->service->update('blog', $this->valid()->with(['ports' => ['typesense']]));
        self::assertTrue(($this->projects->findByName('blog') ?? self::fail())->typesense());
        self::assertSame(['typesense'], $this->portNamesOf('blog'));

        $this->service->update('blog', $this->valid()->with(['ports' => [], 'typesense' => true]));
        self::assertFalse(($this->projects->findByName('blog') ?? self::fail())->typesense());
        self::assertSame([], $this->portNamesOf('blog'));
    }

    public function testCreateWithEitherSideSetsBoth(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));

        $this->service->create($this->valid()->with(['typesense' => true]));
        self::assertSame(['typesense'], $this->portNamesOf('blog'));

        $this->service->create($this->valid()->with(['name' => 'shop', 'ports' => ['typesense']]));
        self::assertTrue(($this->projects->findByName('shop') ?? self::fail())->typesense());
    }

    public function testTheFlagWithoutACataloguePortIsAFieldError(): void
    {
        try {
            $this->service->create($this->valid()->with(['typesense' => true]));
            self::fail('expected a validation error');
        } catch (ValidationFailed $failed) {
            self::assertSame(['typesense'], array_keys($failed->errors));
        }
        self::assertNull($this->projects->findByName('blog'));

        $this->service->create($this->valid());
        try {
            $this->service->update('blog', $this->valid()->with(['typesense' => true]));
            self::fail('expected a validation error');
        } catch (ValidationFailed $failed) {
            self::assertSame(['typesense'], array_keys($failed->errors));
        }
    }

    public function testALegacyFlagWithoutTheCatalogueEntryDoesNotBlockAnUnrelatedSave(): void
    {
        $project = new Project('blog', self::GIT);
        $project->changeTypesense(true);
        $this->projects->save($project);

        $this->service->update('blog', ProjectInput::fromProject($project)->with(['defaultBranch' => 'develop']));

        $found = $this->projects->findByName('blog') ?? self::fail();
        self::assertSame('develop', $found->defaultBranch());
        self::assertTrue($found->typesense());
    }

    public function testALegacyFlagIsHealedWhenTheCatalogueHasThePort(): void
    {
        $project = new Project('blog', self::GIT);
        $project->changeTypesense(true);
        $this->projects->save($project);
        $this->ports->save(new NamedPort('typesense', 9108, 8108));

        $this->service->update('blog', ProjectInput::fromProject($project));

        self::assertSame(['typesense'], $this->portNamesOf('blog'));
    }

    public function testCreatePersistsNormalisedValues(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));

        $created = $this->service->create(new ProjectInput(
            name: ' blog ',
            git: "  https://git.example.com/acme/blog.git\n",
            defaultBranch: '   ',
            additionalHostnames: [' www ', '', "\t"],
            ports: [' typesense ', 'typesense', ''],
            issueIdRegexp: '',
            displaySubmoduleBranch: ' web/modules/custom ',
            typesense: true,
        ));
        $this->em->clear();

        $found = $this->projects->findByName('blog') ?? self::fail('Not stored.');
        self::assertSame($created->name(), $found->name());
        self::assertSame(self::GIT, $found->git());
        self::assertNull($found->defaultBranch());
        self::assertNull($found->defaultTemplate());
        self::assertSame(['www'], $found->additionalHostnames());
        self::assertSame(['typesense'], array_map(static fn (NamedPort $p): string => $p->name(), $found->ports()));
        self::assertNull($found->issueIdRegexp());
        self::assertSame('web/modules/custom', $found->displaySubmoduleBranch());
        self::assertTrue($found->typesense());
        self::assertSame(GitBotMode::Inherit, $found->gitBotMode());
        self::assertEquals($found->createdAt(), $found->updatedAt());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badFields(): iterable
    {
        yield 'git over http' => [['git' => 'http://x.example.com/y'], 'git'];
        yield 'name with capitals' => [['name' => 'Bad'], 'name'];
        yield 'reserved name' => [['name' => 'new'], 'name'];
        yield 'branch starting with a dash' => [['defaultBranch' => '-x'], 'defaultBranch'];
        yield 'hostname with a dot' => [['additionalHostnames' => ['a.b']], 'additionalHostnames'];
        yield 'fifty-one hostnames' => [['additionalHostnames' => array_map(static fn (int $i): string => 'h'.$i, range(1, 51))], 'additionalHostnames'];
        yield 'duplicate hostnames' => [['additionalHostnames' => ['www', 'www']], 'additionalHostnames'];
        yield 'unknown port' => [['ports' => ['nope']], 'ports'];
        yield 'too many ports' => [['ports' => array_map(static fn (int $i): string => 'port'.$i, range(1, 51))], 'ports'];
        yield 'nested quantifier' => [['issueIdRegexp' => '(a+)+$'], 'issueIdRegexp'];
        yield 'path traversal' => [['displaySubmoduleBranch' => '../x'], 'displaySubmoduleBranch'];
        yield 'override without name or email' => [['gitBotMode' => GitBotMode::Override, 'gitBotName' => ' ', 'gitBotEmail' => ''], 'gitBotName'];
        yield 'override with a bad name' => [['gitBotMode' => GitBotMode::Override, 'gitBotName' => 'a<b>'], 'gitBotName'];
        yield 'override with a bad email' => [['gitBotMode' => GitBotMode::Override, 'gitBotName' => 'Bot', 'gitBotEmail' => 'not-an-email'], 'gitBotEmail'];
        yield 'default template on create' => [['defaultTemplate' => 'full'], 'defaultTemplate'];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('badFields')]
    public function testEveryRuleSurfacesAsAFieldError(array $changes, string $field): void
    {
        try {
            $this->service->create($this->valid()->with($changes));
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame([$field], array_keys($e->errors));
            self::assertNotSame('', $e->errors[$field]);
        }
        self::assertSame([], $this->projects->all());
    }

    public function testSeveralErrorsAreReportedTogether(): void
    {
        try {
            $this->service->create($this->valid()->with([
                'name' => 'Bad',
                'git' => 'ext::sh -c x',
                'defaultBranch' => '-x',
                'issueIdRegexp' => '(a+)+$',
            ]));
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'git', 'defaultBranch', 'issueIdRegexp'], array_keys($e->errors));
        }
    }

    public function testMessagesNeverEchoSubmittedValues(): void
    {
        try {
            $this->service->create(new ProjectInput(
                name: 'zzqq Bad',
                git: 'zzqq://x',
                defaultBranch: '-zzqq',
                additionalHostnames: ['zz.qq'],
                ports: ['ZZQQ'],
                gitBotMode: GitBotMode::Override,
                gitBotName: 'zzqq<>',
                gitBotEmail: 'zzqq',
                issueIdRegexp: '(zzqq+)+$',
                displaySubmoduleBranch: '../zzqq',
            ));
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertCount(9, $e->errors);
            foreach ($e->errors as $field => $message) {
                self::assertStringNotContainsStringIgnoringCase('zzqq', $message, $field);
            }
            self::assertStringNotContainsStringIgnoringCase('zzqq', $e->getMessage());
        }
    }

    public function testDuplicateNameIsAFieldError(): void
    {
        $this->service->create($this->valid());

        try {
            $this->service->create($this->valid());
            self::fail('A duplicate was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'A project with this name already exists.'], $e->errors);
        }
        self::assertCount(1, $this->projects->all());
    }

    public function testSimultaneousCreateBecomesAFieldError(): void
    {
        $second = $this->secondEntityManager($this->em);
        $this->beforeNextPersist($this->em, static function () use ($second): void {
            new ProjectRepository($second)->save(new Project('blog', 'https://git.example.com/other/blog.git'));
        });

        try {
            $this->service->create($this->valid());
            self::fail('The racing create was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'A project with this name already exists.'], $e->errors);
        }

        $rows = $second->getConnection()->fetchAllAssociative('SELECT name, git FROM project');
        self::assertSame([['name' => 'blog', 'git' => 'https://git.example.com/other/blog.git']], $rows);
    }

    public function testGitBotNamesAreDroppedUnlessOverride(): void
    {
        foreach ([GitBotMode::Inherit, GitBotMode::Disabled] as $i => $mode) {
            $project = $this->service->create($this->valid()->with([
                'name' => 'p'.$i,
                'gitBotMode' => $mode,
                'gitBotName' => 'Bot',
                'gitBotEmail' => 'not even an email',
            ]));
            self::assertSame($mode, $project->gitBotMode());
            self::assertNull($project->gitBotName());
            self::assertNull($project->gitBotEmail());
        }

        $override = $this->service->create($this->valid()->with([
            'name' => 'custom',
            'gitBotMode' => GitBotMode::Override,
            'gitBotName' => 'Fleet Bot',
            'gitBotEmail' => '',
        ]));
        self::assertSame(GitBotMode::Override, $override->gitBotMode());
        self::assertSame('Fleet Bot', $override->gitBotName());
        self::assertNull($override->gitBotEmail());
    }

    public function testUpdateRejectsARename(): void
    {
        $this->service->create($this->valid());

        try {
            $this->service->update('blog', $this->valid()->with(['name' => 'shop']));
            self::fail('A rename was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['name' => 'The name cannot be changed.'], $e->errors);
        }
        self::assertNull($this->projects->findByName('shop'));
    }

    public function testUpdateDefaultTemplateMustExist(): void
    {
        $project = $this->service->create($this->valid());
        $this->templates->save(new Template($project, 'full'));

        try {
            $this->service->update('blog', $this->valid()->with(['defaultTemplate' => 'missing']));
            self::fail('A missing default template was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['defaultTemplate'], array_keys($e->errors));
        }

        $updated = $this->service->update('blog', $this->valid()->with(['defaultTemplate' => 'full']));
        self::assertSame('full', $updated->defaultTemplate());

        $cleared = $this->service->update('blog', $this->valid()->with(['defaultTemplate' => '']));
        self::assertNull($cleared->defaultTemplate());
    }

    public function testUpdateReplacesPortsAndHostnames(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->ports->save(new NamedPort('mail', 8025, 18025));
        $this->service->create($this->valid()->with(['additionalHostnames' => ['www', 'app'], 'ports' => ['typesense']]));
        $this->em->clear();

        $this->service->update('blog', $this->valid()->with(['additionalHostnames' => ['admin'], 'ports' => ['mail'], 'typesense' => true]));
        $this->em->clear();

        $found = $this->projects->findByName('blog') ?? self::fail();
        self::assertSame(['admin'], $found->additionalHostnames());
        // The port list dropped typesense (the flag did not move), so the flag follows: flag and port are one fact.
        self::assertSame(['mail'], array_map(static fn (NamedPort $p): string => $p->name(), $found->ports()));
        self::assertFalse($found->typesense());
        self::assertGreaterThanOrEqual($found->createdAt(), $found->updatedAt());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function acceptedHostnameCounts(): iterable
    {
        yield 'a real-world list' => [23];
        yield 'the limit' => [50];
    }

    #[DataProvider('acceptedHostnameCounts')]
    public function testManyHostnamesAreStoredInOrder(int $count): void
    {
        $hostnames = array_map(static fn (int $i): string => 'h'.$i, range(1, $count));
        $this->service->create($this->valid()->with(['additionalHostnames' => $hostnames]));
        $this->em->clear();

        self::assertSame($hostnames, ($this->projects->findByName('blog') ?? self::fail())->additionalHostnames());
    }

    public function testSavingGeneralKeepsPortAssignments(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->service->create($this->valid()->with(['ports' => ['typesense'], 'additionalHostnames' => ['www']]));
        $this->em->clear();

        $project = $this->service->get('blog');
        $this->service->update('blog', ProjectInput::fromProject($project)->with(['git' => 'git@git.example.com:acme/blog.git']));
        $this->em->clear();

        $found = $this->projects->findByName('blog') ?? self::fail();
        self::assertSame('git@git.example.com:acme/blog.git', $found->git());
        self::assertSame(['typesense'], array_map(static fn (NamedPort $p): string => $p->name(), $found->ports()));
        self::assertSame(['www'], $found->additionalHostnames());
    }

    public function testFromProjectAndWithRoundTrip(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $project = $this->service->create($this->valid()->with(['defaultBranch' => 'develop', 'typesense' => true]));

        $input = ProjectInput::fromProject($project);
        self::assertEquals($this->valid()->with(['defaultBranch' => 'develop', 'typesense' => true, 'ports' => ['typesense']]), $input);
        self::assertSame('x', $input->with(['git' => 'x'])->git);

        $this->expectException(\InvalidArgumentException::class);
        $input->with(['nope' => 1]);
    }

    public function testDeleteCascadesTemplates(): void
    {
        $project = $this->service->create($this->valid());
        $this->templates->save(new Template($project, 'full'));
        $this->templates->save(new Template($project, 'light'));
        $this->service->create($this->valid()->with(['name' => 'shop']));

        $this->service->delete('blog', 'tester');
        $this->em->clear();

        self::assertNull($this->projects->findByName('blog'));
        self::assertNotNull($this->projects->findByName('shop'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM template'));
    }

    public function testDeleteIsRefusedWhileInstancesExist(): void
    {
        $this->service->create($this->valid());
        $this->guard->set('blog', 2);

        try {
            $this->service->delete('blog', 'tester');
            self::fail('A project with instances was deleted.');
        } catch (Refused $e) {
            self::assertStringContainsString('2 instance(s) exist: destroy them first', $e->getMessage());
            self::assertStringContainsString('blog--i1', $e->getMessage());
            self::assertStringContainsString('blog--i2', $e->getMessage());
        }
        self::assertNotNull($this->projects->findByName('blog'));

        $this->guard->set('blog', 0);
        $this->service->delete('blog', 'tester');
        self::assertNull($this->projects->findByName('blog'));
    }

    public function testDeleteIsRefusedWhileRealInstancesExist(): void
    {
        $service = new ProjectService($this->projects, $this->ports, new InstanceGuard(new InstanceRepository($this->em)));
        $project = $service->create($this->valid());
        $this->instancesOf($project, [InstanceState::Running, InstanceState::Failed]);

        try {
            $service->delete('blog', 'tester');
            self::fail('A project with instances was deleted.');
        } catch (Refused $e) {
            self::assertStringContainsString('2 instance(s) exist: destroy them first', $e->getMessage());
            self::assertStringContainsString('blog--i1', $e->getMessage());
        }
        self::assertNotNull($this->projects->findByName('blog'));

        $this->em->createQuery(\sprintf('DELETE FROM %s i', Instance::class))->execute();
        $service->delete('blog', 'tester');
        self::assertNull($this->projects->findByName('blog'));
    }

    public function testRefusalListsAtMostTenInstances(): void
    {
        $this->service->create($this->valid());
        $this->guard->set('blog', 13);

        $this->expectException(Refused::class);
        $this->expectExceptionMessageMatches('/^13 instance\(s\) exist: destroy them first \(blog--i1, .*blog--i10 and 3 more\)\.$/');
        $this->service->delete('blog', 'tester');
    }

    public function testUnknownProjectIsNotFound(): void
    {
        foreach ([
            fn () => $this->service->get('nope'),
            fn () => $this->service->update('nope', $this->valid()->with(['name' => 'nope'])),
            fn () => $this->service->delete('nope', 'tester'),
        ] as $call) {
            try {
                $call();
                self::fail('An unknown project was found.');
            } catch (NotFound $e) {
                self::assertSame('Project "nope" does not exist.', $e->getMessage());
            }
        }
    }

    public function testNotFoundMessageCutsAndCleansTheName(): void
    {
        try {
            $this->service->get("a\e[31m".str_repeat('b', 100));
            self::fail('Found.');
        } catch (NotFound $e) {
            self::assertStringNotContainsString("\e", $e->getMessage());
            self::assertLessThan(120, \strlen($e->getMessage()));
        }
    }

    public function testListIsOrderedByName(): void
    {
        foreach (['zeta', 'alpha'] as $name) {
            $this->service->create($this->valid()->with(['name' => $name]));
        }

        self::assertSame(['alpha', 'zeta'], array_map(static fn (Project $p): string => $p->name(), $this->service->list()));
    }

    public function testAHugeInputIsRefusedNotStored(): void
    {
        try {
            $this->service->create($this->valid()->with(['git' => 'https://git.example.com/'.str_repeat('a', 1_000_000)]));
            self::fail('A 1 MB URL was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['git'], array_keys($e->errors));
        }

        try {
            $this->service->create($this->valid()->with(['additionalHostnames' => [str_repeat('a', 1_000_000)]]));
            self::fail('A 1 MB hostname was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame(['additionalHostnames'], array_keys($e->errors));
        }
        self::assertSame([], $this->projects->all());
    }

    private function valid(): ProjectInput
    {
        return new ProjectInput('blog', self::GIT);
    }
}
