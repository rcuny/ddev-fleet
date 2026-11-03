<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Tests\Support\FleetDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    use FleetDatabase;

    private UserRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new UserRepository($this->setUpFleetDatabase());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param list<string> $groups
     */
    private static function user(string $username, array $groups = [], bool $enabled = true): User
    {
        $user = new User($username, ucfirst($username), $username.'@example.com', '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$ZGlnZXN0ZGlnZXN0ZGlnZXN0', $groups);
        $user->setEnabled($enabled);

        return $user;
    }

    public function testUsernameIsUnique(): void
    {
        $this->repository->save(self::user('alice'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->save(self::user('alice'));
    }

    public function testGroupsRoundTripAndIsAdmin(): void
    {
        $this->repository->save(self::user('alice', ['fleet-admins', 'dev']));
        $this->repository->save(self::user('bob', ['dev']));
        $this->entityManager()->clear();

        $alice = $this->repository->findByUsername('alice');
        $bob = $this->repository->findByUsername('bob');

        self::assertNotNull($alice);
        self::assertNotNull($bob);
        self::assertSame(['fleet-admins', 'dev'], $alice->groups());
        self::assertTrue($alice->isInGroup('fleet-admins'));
        self::assertSame(['dev'], $bob->groups());
        self::assertFalse($bob->isInGroup('fleet-admins'));
        self::assertNull($this->repository->findByUsername('nobody'));
    }

    public function testAllIsOrderedByUsername(): void
    {
        foreach (['carol', 'alice', 'bob'] as $name) {
            $this->repository->save(self::user($name));
        }
        $this->entityManager()->clear();

        self::assertSame(['alice', 'bob', 'carol'], array_map(
            static fn (User $user): string => $user->username(),
            $this->repository->all(),
        ));
    }

    public function testCountInGroupIgnoresDisabled(): void
    {
        $this->repository->save(self::user('alice', ['fleet-admins']));
        $this->repository->save(self::user('bob', ['fleet-admins'], false));
        $this->repository->save(self::user('carol', ['dev']));

        self::assertSame(1, $this->repository->countInGroup('fleet-admins'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Alice'];
        yield 'leading dot' => ['.alice'];
        yield 'space' => ['al ice'];
        yield 'newline' => ["alice\n"];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidUsernames')]
    public function testInvalidUsernamesAreRejected(string $username): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::user($username);
    }

    public function testGroupsMustBeNonEmptyStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::user('alice', ['']);
    }
}
