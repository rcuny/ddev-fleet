<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Instance\LabelTaken;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\SecondWriter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceRepositoryTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use SecondWriter;

    private EntityManagerInterface $em;
    private InstanceRepository $instances;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->instances = new InstanceRepository($this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testRoundTripsEveryField(): void
    {
        $created = new \DateTimeImmutable('2026-10-01 08:00:00');
        $deployed = new \DateTimeImmutable('2026-10-01 08:05:00');
        $observed = new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('Europe/Brussels'));
        $instance = new Instance($this->projectNamed('blog'), 'pwd8-1781', 'full', 'feature/x', 'cli:alice', $created);
        self::assertSame('blog--pwd8-1781', $instance->id());
        self::assertSame(InstanceState::Deploying, $instance->state());
        $instance->markDeployed($deployed);
        $instance->observe(InstanceState::Running, 412, $observed);
        $instance->recordHead('0123456789abcdef0123456789abcdef01234567');
        $this->instances->save($instance);
        $this->em->clear();

        $found = $this->instances->find('blog--pwd8-1781') ?? self::fail();
        self::assertSame('blog', $found->project()->name());
        self::assertSame('pwd8-1781', $found->label());
        self::assertSame('full', $found->templateName());
        self::assertSame('feature/x', $found->branch());
        self::assertSame(InstanceState::Running, $found->state());
        self::assertSame('0123456789abcdef0123456789abcdef01234567', $found->headSha());
        self::assertSame(412, $found->ramMib());
        self::assertSame('cli:alice', $found->requestedBy());
        self::assertEquals($created, $found->createdAt());
        self::assertEquals($deployed, $found->deployedAt());
        self::assertEquals($observed, $found->lastObservedAt());
        self::assertNull($this->instances->find('blog--nope'));
    }

    public function testIdIsUnique(): void
    {
        $blog = $this->projectNamed('blog');
        $this->beforeNextPersist($this->em, function () use ($blog): void {
            $second = $this->secondEntityManager($this->em);
            $second->persist(new Instance($second->find(Project::class, $blog->id()) ?? self::fail(), 'main', 'full', 'develop', 'admin'));
            $second->flush();
        });

        try {
            $this->instances->createMany([new Instance($blog, 'main', 'full', 'develop', 'admin')]);
            self::fail('A second writer with the same id did not conflict.');
        } catch (LabelTaken $e) {
            self::assertSame('blog--main', $e->id);
        }
        self::assertSame(['blog--main'], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM instance'));
    }

    public function testCreateManyIsAtomic(): void
    {
        $blog = $this->projectNamed('blog');
        $this->instances->save(new Instance($blog, 'b', 'full', 'develop', 'admin'));
        $this->em->clear();
        $blog = $this->projectNamed('blog');

        try {
            $this->instances->createMany([
                new Instance($blog, 'a', 'full', 'develop', 'admin'),
                new Instance($blog, 'b', 'full', 'develop', 'admin'),
            ]);
            self::fail('A duplicate id in the batch was accepted.');
        } catch (LabelTaken $e) {
            self::assertSame('blog--b', $e->id);
        }

        self::assertSame(['blog--b'], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM instance ORDER BY id'));
    }

    public function testCreateManyRefusesAnIdThisManagerAlreadyHolds(): void
    {
        $blog = $this->projectNamed('blog');
        $this->instances->save(new Instance($blog, 'b', 'full', 'develop', 'admin'));

        try {
            $this->instances->createMany([
                new Instance($blog, 'a', 'full', 'develop', 'admin'),
                new Instance($blog, 'b', 'full', 'develop', 'admin'),
            ]);
            self::fail('A duplicate id was accepted.');
        } catch (LabelTaken $e) {
            self::assertSame('blog--b', $e->id);
        }

        $this->instances->save(new Instance($blog, 'c', 'full', 'develop', 'admin'));
        self::assertSame(['blog--b', 'blog--c'], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM instance ORDER BY id'));
    }

    public function testAProjectWithInstancesCannotBeDeleted(): void
    {
        $blog = $this->projectNamed('blog');
        $this->instancesOf($blog, [InstanceState::Running]);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        new ProjectRepository($this->em)->remove($blog);
    }

    public function testCountByState(): void
    {
        $this->instancesOf($this->projectNamed('blog'), [InstanceState::Running, InstanceState::Running, InstanceState::Failed]);
        $this->instancesOf($this->projectNamed('shop'), [InstanceState::Stopped]);

        self::assertSame(['failed' => 1, 'running' => 2, 'stopped' => 1], $this->sorted($this->instances->countByState()));
        self::assertSame(['blog--i1', 'blog--i2', 'blog--i3', 'shop--i1'], $this->instances->ids());
        self::assertCount(3, $this->instances->byProject($this->projectNamed('blog')));
        self::assertCount(4, $this->instances->all());
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
