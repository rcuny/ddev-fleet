<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Projects\ProjectSnapshot;
use App\Tests\Support\FleetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectSnapshotTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testShapeAndKeys(): void
    {
        $utc = new \DateTimeZone('UTC');
        $created = new \DateTimeImmutable('2026-10-01 08:00:00', $utc);
        $changed = new \DateTimeImmutable('2026-10-02 09:30:15', $utc);
        $ports = new NamedPortRepository($this->em);
        $typesense = new NamedPort('typesense', 9108, 8108);
        $mail = new NamedPort('mail', 8025, 18025);
        $ports->save($typesense);
        $ports->save($mail);

        $project = new Project('blog', 'https://git.example.com/acme/blog.git', $created);
        $project->changeDefaults('develop', 'full', $changed);
        $project->changeHostnames(['www', 'app'], $changed);
        $project->changePorts([$typesense, $mail], $changed);
        $project->changeGitBot(GitBotMode::Override, 'Fleet Bot', 'bot@example.com', $changed);
        $project->changeIssueIdRegexp('(?:ABC|XYZ)-\d{1,6}', $changed);
        $project->changeDisplaySubmoduleBranch('web/modules/custom', $changed);
        $project->changeTypesense(true, $changed);
        $projects = new ProjectRepository($this->em);
        $projects->save($project);

        $templates = new TemplateRepository($this->em);
        $light = new Template($project, 'light', $created);
        $templates->save($light);
        $full = new Template($project, 'full', $created);
        $full->changeDrupalEnv('dev', $created);
        $full->changeCommands(['composer install', 'drush deploy'], ['npm run watch'], ['tail -f log', 'ls'], $created);
        $templates->save($full);
        $this->em->clear();

        $snapshot = ProjectSnapshot::fromProject($projects->findByName('blog') ?? self::fail());

        self::assertSame([
            'name' => 'blog',
            'git' => 'https://git.example.com/acme/blog.git',
            'default_branch' => 'develop',
            'default_template' => 'full',
            'additional_hostnames' => ['www', 'app'],
            'ports' => ['mail', 'typesense'],
            'git_bot' => ['mode' => 'override', 'name' => 'Fleet Bot', 'email' => 'bot@example.com'],
            'issue_id_regexp' => '(?:ABC|XYZ)-\d{1,6}',
            'display_submodule_branch' => 'web/modules/custom',
            'typesense' => true,
            'templates' => [
                [
                    'name' => 'full',
                    'drupal_env' => 'dev',
                    'post_deploy' => [
                        'exec' => ['composer install', 'drush deploy'],
                        'tty1' => ['npm run watch'],
                        'tty2' => ['tail -f log', 'ls'],
                    ],
                ],
                [
                    'name' => 'light',
                    'drupal_env' => null,
                    'post_deploy' => ['exec' => [], 'tty1' => [], 'tty2' => []],
                ],
            ],
            'created_at' => '2026-10-01T08:00:00Z',
            'updated_at' => '2026-10-02T09:30:15Z',
        ], $snapshot->toArray());
    }

    public function testADefaultProjectHasNeutralValuesAndTheSameKeys(): void
    {
        $projects = new ProjectRepository($this->em);
        $projects->save(new Project('blog', 'https://git.example.com/acme/blog.git', new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('UTC'))));
        $this->em->clear();

        $data = ProjectSnapshot::fromProject($projects->findByName('blog') ?? self::fail())->toArray();

        self::assertSame(
            ['name', 'git', 'default_branch', 'default_template', 'additional_hostnames', 'ports', 'git_bot', 'issue_id_regexp', 'display_submodule_branch', 'typesense', 'templates', 'created_at', 'updated_at'],
            array_keys($data),
        );
        self::assertNull($data['default_branch']);
        self::assertSame([], $data['ports']);
        self::assertSame([], $data['templates']);
        self::assertFalse($data['typesense']);
        self::assertSame(['mode' => 'inherit', 'name' => null, 'email' => null], $data['git_bot']);
    }

    public function testTimesAreUtcWhateverTheEntityZone(): void
    {
        $projects = new ProjectRepository($this->em);
        $projects->save(new Project('blog', 'https://git.example.com/acme/blog.git', new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Brussels'))));
        $this->em->clear();

        $data = ProjectSnapshot::fromProject($projects->findByName('blog') ?? self::fail())->toArray();
        self::assertSame('2026-10-01T08:00:00Z', $data['created_at']);
    }
}
