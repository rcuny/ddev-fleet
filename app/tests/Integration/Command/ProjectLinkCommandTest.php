<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** FLE-299: fleet project:link:create|list|delete over a real migrated database. */
final class ProjectLinkCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $projects = new ProjectRepository($em);
        $projects->save(new Project('app', 'https://git.example.com/x/app.git'));
        $projects->save(new Project('cms', 'https://git.example.com/x/cms.git'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateListAndDeleteARoundTripWithAnAuditTrail(): void
    {
        $created = $this->cli('fleet:project:link:create', ['source' => 'app', 'target' => 'cms', '--service' => 'typesense', '--port' => '8108', '--note' => 'search', '--format' => 'json']);
        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        $doc = $this->json($created);
        self::assertSame(['source' => 'app', 'target' => 'cms', 'service' => 'typesense', 'port' => 8108, 'label' => 'same', 'note' => 'search'], array_intersect_key($doc, array_flip(['source', 'target', 'service', 'port', 'label', 'note'])));
        self::assertSame('cli', $doc['createdBy']);

        foreach (['app', 'cms', null] as $project) {
            $list = $this->cli('fleet:project:link:list', null === $project ? ['--format' => 'json'] : ['project' => $project, '--format' => 'json']);
            self::assertCount(1, $this->json($list), (string) $project);
        }

        $deleted = $this->cli('fleet:project:link:delete', ['source' => 'app', 'target' => 'cms', '--service' => 'typesense', '--port' => '8108']);
        self::assertSame(0, $deleted->getStatusCode());
        self::assertCount(0, $this->json($this->cli('fleet:project:link:list', ['--format' => 'json'])));

        $actions = array_map(static fn ($e): string => $e->action(), new AuditEntryRepository($this->entityManager())->latest(5));
        self::assertContains('project.link-create', $actions);
        self::assertContains('project.link-delete', $actions);
    }

    public function testBadInputIsRefusedWithTheFieldNamed(): void
    {
        $tester = $this->cli('fleet:project:link:create', ['source' => 'app', 'target' => 'app', '--service' => 'Bad_Name', '--port' => '99999', '--label' => 'UP', '--format' => 'json']);

        self::assertSame(1, $tester->getStatusCode());
        $fields = (array) ($this->json($tester)['fields'] ?? []);
        self::assertArrayHasKey('<target>', $fields);
        self::assertArrayHasKey('--service', $fields);
        self::assertArrayHasKey('--port', $fields);
        self::assertArrayHasKey('--label', $fields);
    }

    public function testUnknownProjectsAndDuplicatesAreRefused(): void
    {
        self::assertSame(1, $this->cli('fleet:project:link:create', ['source' => 'nope', 'target' => 'cms', '--port' => '80'])->getStatusCode());
        $args = ['source' => 'app', 'target' => 'cms', '--port' => '80'];
        self::assertSame(0, $this->cli('fleet:project:link:create', $args)->getStatusCode());
        self::assertSame(1, $this->cli('fleet:project:link:create', $args)->getStatusCode());
        self::assertSame(2, $this->cli('fleet:project:link:delete', ['source' => 'app', 'target' => 'cms', '--port' => '81'])->getStatusCode());
        self::assertSame(3, $this->cli('fleet:project:link:list', ['project' => 'nope'])->getStatusCode());
    }

    public function testDeletingAProjectDeletesItsLinks(): void
    {
        $this->cli('fleet:project:link:create', ['source' => 'app', 'target' => 'cms', '--port' => '80']);
        self::assertSame(0, $this->cli('fleet:project:delete', ['name' => 'cms', '--yes' => true])->getStatusCode());

        self::assertCount(0, $this->json($this->cli('fleet:project:link:list', ['--format' => 'json'])));
    }
}
