<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private ProjectRepository $projects;
    private NamedPortRepository $ports;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->projects = new ProjectRepository($this->em);
        $this->ports = new NamedPortRepository($this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testRoundTripsEveryField(): void
    {
        $created = new \DateTimeImmutable('2026-10-01 08:00:00');
        $changed = new \DateTimeImmutable('2026-10-02 09:30:00');
        $typesense = new NamedPort('typesense', 9108, 8108);
        $mail = new NamedPort('mail', 8025, 18025);
        $this->ports->save($typesense);
        $this->ports->save($mail);

        $project = new Project('blog', 'https://git.example.com/acme/blog.git', $created);
        self::assertEquals($created, $project->updatedAt());
        $project->changeGit('git@git.example.com:acme/blog.git', $changed);
        $project->changeDefaults('develop', 'full', $changed);
        $project->changeHostnames(['www', 'app', 'admin'], $changed);
        $project->changePorts([$typesense, $mail], $changed);
        $project->changeGitBot(GitBotMode::Override, 'Fleet Bot', 'bot@example.com', $changed);
        $project->changeIssueIdRegexp('(?:ABC|XYZ)-\d{1,6}', $changed);
        $project->changeDisplaySubmoduleBranch('web/modules/custom/blog', $changed);
        $project->changeTypesense(true, $changed);
        $this->projects->save($project);
        $this->em->clear();

        $found = $this->projects->findByName('blog');
        self::assertNotNull($found);
        self::assertNotNull($found->id());
        self::assertSame('blog', $found->name());
        self::assertSame('git@git.example.com:acme/blog.git', $found->git());
        self::assertSame('develop', $found->defaultBranch());
        self::assertSame('full', $found->defaultTemplate());
        self::assertSame(['www', 'app', 'admin'], $found->additionalHostnames());
        self::assertSame(GitBotMode::Override, $found->gitBotMode());
        self::assertSame('Fleet Bot', $found->gitBotName());
        self::assertSame('bot@example.com', $found->gitBotEmail());
        self::assertSame('(?:ABC|XYZ)-\d{1,6}', $found->issueIdRegexp());
        self::assertSame('web/modules/custom/blog', $found->displaySubmoduleBranch());
        self::assertTrue($found->typesense());
        self::assertSame(['mail', 'typesense'], array_map(static fn (NamedPort $p): string => $p->name(), $found->ports()));
        self::assertEquals($created, $found->createdAt());
        self::assertEquals($changed, $found->updatedAt());
    }

    public function testNewProjectHasNeutralDefaults(): void
    {
        $this->projects->save(new Project('blog', 'https://git.example.com/acme/blog.git'));
        $this->em->clear();

        $found = $this->projects->findByName('blog') ?? self::fail('Not stored.');
        self::assertNull($found->defaultBranch());
        self::assertNull($found->defaultTemplate());
        self::assertSame([], $found->additionalHostnames());
        self::assertSame(GitBotMode::Inherit, $found->gitBotMode());
        self::assertNull($found->gitBotName());
        self::assertNull($found->gitBotEmail());
        self::assertNull($found->issueIdRegexp());
        self::assertNull($found->displaySubmoduleBranch());
        self::assertFalse($found->typesense());
        self::assertSame([], $found->ports());
        self::assertSame([], $found->templates());
        self::assertNull($this->projects->findByName('nope'));
    }

    public function testChangingPortsReplacesTheAssignment(): void
    {
        $a = new NamedPort('alpha', 9001, 8001);
        $b = new NamedPort('beta', 9002, 8002);
        $this->ports->save($a);
        $this->ports->save($b);
        $project = new Project('blog', 'https://git.example.com/acme/blog.git');
        $project->changePorts([$a, $b, $a]);
        $this->projects->save($project);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM project_port'));

        $project->changePorts([$b]);
        $this->projects->save($project);
        $this->em->clear();

        self::assertSame(
            ['beta'],
            array_map(static fn (NamedPort $p): string => $p->name(), ($this->projects->findByName('blog') ?? self::fail())->ports()),
        );
    }

    public function testNameIsUnique(): void
    {
        $this->projects->save(new Project('blog', 'https://git.example.com/acme/blog.git'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->projects->save(new Project('blog', 'https://git.example.com/acme/other.git'));
    }

    public function testDeletingAProjectCascadesToItsTemplatesAndPortLinks(): void
    {
        $port = new NamedPort('typesense', 9108, 8108);
        $this->ports->save($port);
        $project = new Project('blog', 'https://git.example.com/acme/blog.git');
        $project->changePorts([$port]);
        $this->projects->save($project);
        $templates = new TemplateRepository($this->em);
        $templates->save(new Template($project, 'full'));
        $templates->save(new Template($project, 'light'));
        $this->em->clear();

        $connection = $this->em->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM template'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM project_port'));

        $this->projects->remove($this->projects->findByName('blog') ?? self::fail());

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM project'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM template'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM project_port'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM named_port'));
    }

    public function testAPortInUseCannotBeRemoved(): void
    {
        $port = new NamedPort('typesense', 9108, 8108);
        $unused = new NamedPort('mail', 8025, 18025);
        $this->ports->save($port);
        $this->ports->save($unused);
        $project = new Project('blog', 'https://git.example.com/acme/blog.git');
        $project->changePorts([$port]);
        $this->projects->save($project);

        self::assertSame(['blog'], $this->ports->projectNamesUsing($port));
        self::assertSame([], $this->ports->projectNamesUsing($unused));

        try {
            $this->ports->remove($port);
            self::fail('A port in use was removed.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM named_port'));
        }
    }

    public function testAllIsOrderedByName(): void
    {
        foreach (['zeta', 'alpha', 'mid'] as $name) {
            $this->projects->save(new Project($name, 'https://git.example.com/acme/'.$name.'.git'));
        }
        $this->em->clear();

        self::assertSame(['alpha', 'mid', 'zeta'], array_map(static fn (Project $p): string => $p->name(), $this->projects->all()));
    }

    public function testTemplatesAreListedByName(): void
    {
        $project = new Project('blog', 'https://git.example.com/acme/blog.git');
        $this->projects->save($project);
        $templates = new TemplateRepository($this->em);
        foreach (['zeta', 'alpha'] as $name) {
            $templates->save(new Template($project, $name));
        }
        $this->em->clear();

        $found = $this->projects->findByName('blog') ?? self::fail();
        self::assertSame(['alpha', 'zeta'], array_map(static fn (Template $t): string => $t->name(), $found->templates()));
    }
}
