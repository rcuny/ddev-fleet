<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NamedPortRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private EntityManagerInterface $em;
    private NamedPortRepository $ports;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->ports = new NamedPortRepository($this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testRoundTrip(): void
    {
        $created = new \DateTimeImmutable('2026-10-01 08:00:00');
        $this->ports->save(new NamedPort('typesense', 9108, 8108, $created));
        $this->em->clear();

        $found = $this->ports->findByName('typesense') ?? self::fail();
        self::assertNotNull($found->id());
        self::assertSame(9108, $found->publicPort());
        self::assertSame(8108, $found->routerPort());
        self::assertEquals($created, $found->createdAt());
        self::assertNull($this->ports->findByName('nope'));
    }

    /**
     * @return iterable<string, array{NamedPort}>
     */
    public static function collidingPorts(): iterable
    {
        yield 'same name' => [new NamedPort('typesense', 9999, 8999)];
        yield 'same public port' => [new NamedPort('other', 9108, 8999)];
        yield 'same router port' => [new NamedPort('other', 9999, 8108)];
    }

    #[DataProvider('collidingPorts')]
    public function testPublicAndRouterPortsAreUnique(NamedPort $second): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->ports->save($second);
    }

    public function testFindManyByNamesIgnoresUnknownNames(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->ports->save(new NamedPort('mail', 8025, 18025));
        $this->ports->save(new NamedPort('solr', 8983, 18983));
        $this->em->clear();

        self::assertSame(['mail', 'typesense'], array_map(static fn (NamedPort $p): string => $p->name(), $this->ports->findManyByNames(['typesense', 'nope', 'mail'])));
        self::assertSame([], $this->ports->findManyByNames([]));
        self::assertSame([], $this->ports->findManyByNames(['nope']));
    }

    public function testAllIsOrderedByName(): void
    {
        $this->ports->save(new NamedPort('zeta', 9003, 8003));
        $this->ports->save(new NamedPort('alpha', 9001, 8001));
        $this->em->clear();

        self::assertSame(['alpha', 'zeta'], array_map(static fn (NamedPort $p): string => $p->name(), $this->ports->all()));
    }

    public function testRemoveDeletesAnUnusedPort(): void
    {
        $this->ports->save(new NamedPort('typesense', 9108, 8108));
        $this->ports->remove($this->ports->findByName('typesense') ?? self::fail());

        self::assertNull($this->ports->findByName('typesense'));
    }
}
