<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SecondWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NamedPortServiceTest extends KernelTestCase
{
    use FleetDatabase;
    use SecondWriter;

    private EntityManagerInterface $em;
    private NamedPortRepository $ports;
    private ProjectRepository $projects;
    private NamedPortService $service;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->ports = new NamedPortRepository($this->em);
        $this->projects = new ProjectRepository($this->em);
        $this->service = new NamedPortService($this->ports);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateAcceptsStringsFromAFormOrACommandLine(): void
    {
        $port = $this->service->create(new NamedPortInput(' typesense ', ' 9108 ', '8108'));
        $this->em->clear();

        $found = $this->service->get('typesense');
        self::assertNotNull($port->id());
        self::assertSame(9108, $found->publicPort());
        self::assertSame(8108, $found->routerPort());
        self::assertSame(['typesense'], array_map(static fn (NamedPort $p): string => $p->name(), $this->service->list()));
    }

    public function testPortNamesAreNotReserved(): void
    {
        self::assertSame('new', $this->service->create(new NamedPortInput('new', 9001, 8001))->name());
    }

    /**
     * @return iterable<string, array{NamedPortInput, list<string>}>
     */
    public static function badPorts(): iterable
    {
        yield 'ssh as public port' => [new NamedPortInput('a', 22, 8001), ['public']];
        yield 'public 8765' => [new NamedPortInput('a', 8765, 8001), ['public']];
        yield 'router 8080' => [new NamedPortInput('a', 9001, 8080), ['router']];
        yield 'equal ports' => [new NamedPortInput('a', 9001, 9001), ['router']];
        yield 'text as public port' => [new NamedPortInput('a', 'abc', 8001), ['public']];
        yield 'text as router port' => [new NamedPortInput('a', 9001, '80 80'), ['router']];
        yield 'zero' => [new NamedPortInput('a', '0', 8001), ['public']];
        yield 'above 65535' => [new NamedPortInput('a', '65536', 8001), ['public']];
        yield 'six digits' => [new NamedPortInput('a', '009001', 8001), ['public']];
        yield 'negative' => [new NamedPortInput('a', '-1', 8001), ['public']];
        yield 'empty router' => [new NamedPortInput('a', 9001, ''), ['router']];
        yield 'bad name' => [new NamedPortInput('Bad Name', 9001, 8001), ['name']];
        yield 'empty name' => [new NamedPortInput('', 9001, 8001), ['name']];
        yield 'everything wrong' => [new NamedPortInput('--', 22, 22), ['name', 'public', 'router']];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('badPorts')]
    public function testReservedAndRouterPortsAreFieldErrors(NamedPortInput $input, array $fields): void
    {
        try {
            $this->service->create($input);
            self::fail('The input was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame($fields, array_keys($e->errors));
        }
        self::assertSame([], $this->service->list());
    }

    /**
     * @return iterable<string, array{NamedPortInput, string}>
     */
    public static function collisions(): iterable
    {
        yield 'same name' => [new NamedPortInput('typesense', 9999, 8999), 'name'];
        yield 'same public port' => [new NamedPortInput('other', 9108, 8999), 'public'];
        yield 'same router port' => [new NamedPortInput('other', 9999, 8108), 'router'];
        yield 'public equals the router port of another entry' => [new NamedPortInput('other', 8108, 8999), 'public'];
        yield 'router equals the public port of another entry' => [new NamedPortInput('other', 9999, 9108), 'router'];
    }

    #[DataProvider('collisions')]
    public function testDuplicatePublicOrRouterIsAFieldError(NamedPortInput $input, string $field): void
    {
        $this->service->create(new NamedPortInput('typesense', 9108, 8108));

        try {
            $this->service->create($input);
            self::fail('A colliding port was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
        self::assertCount(1, $this->service->list());
    }

    /**
     * @return iterable<string, array{array{string, int, int}, NamedPortInput, string}>
     */
    public static function races(): iterable
    {
        yield 'name' => [['typesense', 9501, 8501], new NamedPortInput('typesense', 9502, 8502), 'name'];
        yield 'public port' => [['other', 9108, 8503], new NamedPortInput('mine', 9108, 8504), 'public'];
        yield 'router port' => [['another', 9505, 8108], new NamedPortInput('mine', 9506, 8108), 'router'];
    }

    /**
     * @param array{string, int, int} $winner the entry a second writer saves after the service's checks
     */
    #[DataProvider('races')]
    public function testSimultaneousCreateBecomesAFieldError(array $winner, NamedPortInput $loser, string $field): void
    {
        $second = $this->secondEntityManager($this->em);
        $this->beforeNextPersist($this->em, static function () use ($second, $winner): void {
            new NamedPortRepository($second)->save(new NamedPort(...$winner));
        });

        try {
            $this->service->create($loser);
            self::fail('The racing create was accepted.');
        } catch (ValidationFailed $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
        self::assertSame(1, (int) $second->getConnection()->fetchOne('SELECT COUNT(*) FROM named_port'));
    }

    public function testDeleteIsRefusedWhileAProjectUsesIt(): void
    {
        $port = $this->service->create(new NamedPortInput('typesense', 9108, 8108));
        foreach (['shop', 'blog'] as $name) {
            $project = new Project($name, 'https://git.example.com/acme/'.$name.'.git');
            $project->changePorts([$port]);
            $this->projects->save($project);
        }

        try {
            $this->service->delete('typesense');
            self::fail('A port in use was deleted.');
        } catch (Refused $e) {
            self::assertSame('Used by project(s): blog, shop.', $e->getMessage());
        }
        // The entity manager is still open: the refusal came from the check, not from a database error.
        self::assertTrue($this->em->isOpen());
        self::assertNotNull($this->ports->findByName('typesense'));
    }

    public function testDeleteUnused(): void
    {
        $this->service->create(new NamedPortInput('typesense', 9108, 8108));
        $this->service->create(new NamedPortInput('mail', 8025, 18025));

        $this->service->delete('typesense');

        self::assertSame(['mail'], array_map(static fn (NamedPort $p): string => $p->name(), $this->service->list()));
    }

    public function testUnknownPortIsNotFound(): void
    {
        foreach ([fn () => $this->service->get('nope'), fn () => $this->service->delete('nope')] as $call) {
            try {
                $call();
                self::fail('Found.');
            } catch (NotFound $e) {
                self::assertSame('Named port "nope" does not exist.', $e->getMessage());
            }
        }
    }
}
