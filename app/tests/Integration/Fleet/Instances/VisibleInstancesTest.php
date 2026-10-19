<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\VisibleInstances;
use App\Security\FleetUser;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class VisibleInstancesTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $this->instancesOf($this->projectNamed('blog'), [InstanceState::Running, InstanceState::Stopped]);
        $this->instancesOf($this->projectNamed('shop'), [InstanceState::Running]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param list<string> $groups
     */
    private function visibleTo(array $groups): VisibleInstances
    {
        $user = new FleetUser('alice', 'Alice', null, $groups, 'fleet-admins');
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $auth = self::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $auth);

        return new VisibleInstances(new InstanceRepository($this->em), $auth);
    }

    /**
     * @param list<\App\Domain\Instance\Instance> $instances
     *
     * @return list<string>
     */
    private static function ids(array $instances): array
    {
        return array_map(static fn ($i): string => $i->id(), $instances);
    }

    public function testAdminSeesAll(): void
    {
        $visible = $this->visibleTo(['fleet-admins']);

        self::assertSame(['blog--i1', 'blog--i2', 'shop--i1'], self::ids($visible->all()));
        self::assertSame('shop--i1', $visible->find('shop--i1')?->id());
        self::assertNull($visible->find('shop--nope'));
    }

    public function testMemberSeesOwnProjectsOnly(): void
    {
        // In the test environment a stand-in membership makes a group named like the project a membership.
        $visible = $this->visibleTo(['blog']);

        self::assertSame(['blog--i1', 'blog--i2'], self::ids($visible->all()));
        self::assertSame('blog--i2', $visible->find('blog--i2')?->id());
    }

    public function testAMemberOfNothingSeesNothing(): void
    {
        $visible = $this->visibleTo(['unrelated']);

        self::assertSame([], $visible->all());
        self::assertNull($visible->find('blog--i1'));
    }

    public function testFindOfAForeignInstanceIsNull(): void
    {
        $visible = $this->visibleTo(['blog']);

        self::assertNull($visible->find('shop--i1'), 'exists, but is not theirs: indistinguishable from a missing one');
        self::assertNull($visible->find('shop--missing'));
    }
}
