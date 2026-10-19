<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\User\PasswordHash;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\InvalidUserInput;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SecondWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserServiceTest extends KernelTestCase
{
    use FleetDatabase;
    use SecondWriter;

    private const string PASSWORD = 'correct horse battery staple';

    private UserRepository $repository;
    private UserService $service;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->repository = new UserRepository($em);
        $this->service = new UserService($this->repository, new PasswordPolicy(), new AdminGroup('fleet-admins'));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testUpsertAdminCreatesArgon2idHashedAdmin(): void
    {
        $user = $this->service->upsertAdmin('alice', 'Alice A', 'alice@example.com', self::PASSWORD);
        $this->entityManager()->clear();

        $stored = $this->repository->findByUsername('alice');
        self::assertNotNull($stored);
        self::assertSame(['fleet-admins'], $stored->groups());
        self::assertTrue($stored->isInGroup('fleet-admins'));
        self::assertTrue($stored->isEnabled());
        self::assertSame('Alice A', $stored->displayName());
        self::assertSame($user->passwordHash(), $stored->passwordHash());
        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $stored->passwordHash());
        self::assertTrue(PasswordHash::isArgon2id($stored->passwordHash()));
        self::assertTrue(password_verify(self::PASSWORD, $stored->passwordHash()));
        self::assertStringNotContainsString(self::PASSWORD, $stored->passwordHash());
    }

    public function testCreateKeepsOtherWritersOutBetweenTheCheckAndTheSave(): void
    {
        $other = new \PDO('sqlite:'.self::getContainer()->get(FleetPaths::class)->fleetDb());
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        // A parallel create that passed its own check would insert here; it must wait (here: fail at once) instead.
        $blocked = false;
        $this->beforeNextPersist($this->entityManager(), static function () use ($other, &$blocked): void {
            try {
                $other->exec("INSERT INTO fleet_user (username, display_name, email, password_hash, user_groups, enabled, created_at, updated_at) VALUES ('ada', 'Ada', 'a@example.com', 'x', '[]', 1, '2026-10-19 00:00:00', '2026-10-19 00:00:00')");
            } catch (\PDOException) {
                $blocked = true;
            }
        });

        $this->service->create('ada', 'Ada', 'ada@example.com', self::PASSWORD);

        self::assertTrue($blocked, 'the lock is held from before the existence check');
    }

    public function testUpsertAdminKeepsOtherWritersOutBetweenTheLookupAndTheSave(): void
    {
        $other = new \PDO('sqlite:'.self::getContainer()->get(FleetPaths::class)->fleetDb());
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        // A parallel upsert that looked the name up before this one saved would insert here; it must be kept out.
        $blocked = false;
        $this->beforeNextPersist($this->entityManager(), static function () use ($other, &$blocked): void {
            try {
                $other->exec("INSERT INTO fleet_user (username, display_name, email, password_hash, user_groups, enabled, created_at, updated_at) VALUES ('ada', 'Ada', 'a@example.com', 'x', '[]', 1, '2026-10-19 00:00:00', '2026-10-19 00:00:00')");
            } catch (\PDOException) {
                $blocked = true;
            }
        });

        $this->service->upsertAdmin('ada', 'Ada', 'ada@example.com', self::PASSWORD);

        self::assertTrue($blocked, 'the lock is held from before the lookup');
    }

    public function testALosingParallelCreateIsAnInvalidUserInput(): void
    {
        $second = $this->secondEntityManager($this->entityManager());
        new UserRepository($second)->save(new User('ada', 'Ada', 'ada@example.com', '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Zm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm8', []));

        try {
            $this->service->create('ada', 'Ada', 'ada@example.com', self::PASSWORD);
            self::fail('The second create was accepted.');
        } catch (InvalidUserInput $e) {
            self::assertSame('username', $e->field);
        }
    }

    public function testUpsertTwiceUpdatesInsteadOfDuplicating(): void
    {
        $first = $this->service->upsertAdmin('alice', 'Alice A', 'alice@example.com', self::PASSWORD);
        $first->changeGroups(['dev', 'fleet-admins']);
        $first->setEnabled(false);
        $this->repository->save($first);
        $firstHash = $first->passwordHash();

        $this->service->upsertAdmin('alice', 'Alice B', 'alice@example.org', 'another passphrase entirely');
        $this->entityManager()->clear();

        $all = $this->repository->all();
        self::assertCount(1, $all);
        self::assertSame('Alice B', $all[0]->displayName());
        self::assertSame('alice@example.org', $all[0]->email());
        self::assertNotSame($firstHash, $all[0]->passwordHash());
        self::assertTrue(password_verify('another passphrase entirely', $all[0]->passwordHash()));
        self::assertSame(['dev', 'fleet-admins'], $all[0]->groups(), 'other groups are kept');
        self::assertTrue($all[0]->isEnabled(), 'the wizard admin must be able to sign in');
    }

    public function testWeakPasswordIsRefusedAndNothingStored(): void
    {
        try {
            $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', 'short');
            self::fail('a weak password must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least 12', $e->getMessage());
            self::assertStringNotContainsString('short', str_replace('at least 12 characters long', '', $e->getMessage()));
        }
        $this->entityManager()->clear();
        self::assertSame([], $this->repository->all());

        // An existing user is not half-updated by a refused upsert.
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $this->entityManager()->clear();
        $hash = $this->repository->findByUsername('alice')?->passwordHash();
        try {
            $this->service->upsertAdmin('alice', 'Renamed', 'renamed@example.com', 'alice');
            self::fail('a weak password must be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->clear();
        $alice = $this->repository->findByUsername('alice');
        self::assertNotNull($alice);
        self::assertSame($hash, $alice->passwordHash());
        self::assertSame('Alice', $alice->displayName());
    }

    public function testSetPasswordChangesHash(): void
    {
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $this->entityManager()->clear();
        $before = $this->repository->findByUsername('alice')?->passwordHash();

        $this->service->setPassword('alice', 'a brand new passphrase');
        $this->entityManager()->clear();

        $alice = $this->repository->findByUsername('alice');
        self::assertNotNull($alice);
        self::assertNotSame($before, $alice->passwordHash());
        self::assertTrue(password_verify('a brand new passphrase', $alice->passwordHash()));
        self::assertFalse(password_verify(self::PASSWORD, $alice->passwordHash()));
        self::assertSame(['fleet-admins'], $alice->groups());
    }

    public function testSetPasswordRefusesUnknownUserAndWeakPassword(): void
    {
        try {
            $this->service->setPassword('ghost', self::PASSWORD);
            self::fail('unknown user');
        } catch (UnknownUser) {
        }

        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $this->entityManager()->clear();
        $before = $this->repository->findByUsername('alice')?->passwordHash();
        try {
            $this->service->setPassword('alice', 'too short');
            self::fail('weak password');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->clear();
        self::assertSame($before, $this->repository->findByUsername('alice')?->passwordHash());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidProfiles(): iterable
    {
        yield 'uppercase username' => ['Alice', 'Alice', 'alice@example.com'];
        yield 'username with slash' => ['a/b', 'Alice', 'alice@example.com'];
        yield 'empty display name' => ['alice', '  ', 'alice@example.com'];
        yield 'newline in display name' => ['alice', "Alice\nevil", 'alice@example.com'];
        yield 'control char in display name' => ['alice', "Al\x07ice", 'alice@example.com'];
        yield 'long display name' => ['alice', str_repeat('a', 256), 'alice@example.com'];
        yield 'bad email' => ['alice', 'Alice', 'not-an-email'];
        yield 'email with newline' => ['alice', 'Alice', "alice@example.com\n"];
        yield 'email with space' => ['alice', 'Alice', 'al ice@example.com'];
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidIdentityIsRefused(string $username, string $displayName, string $email): void
    {
        try {
            $this->service->upsertAdmin($username, $displayName, $email, self::PASSWORD);
            self::fail('invalid identity must be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->clear();
        self::assertSame([], $this->repository->all());
    }

    public function testUserRejectsAHashThatIsNotArgon2id(): void
    {
        foreach (['plaintext-password', password_hash('x', \PASSWORD_BCRYPT), '$argon2i$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$ZGlnZXN0ZGlnZXN0ZGlnZXN0', "\$argon2id\$v=19\$m=65536,t=4,p=1\$c2FsdHNhbHRzYWx0\$ZGlnZXN0ZGlnZXN0ZGlnZXN0\nextra: 1", ''] as $bad) {
            try {
                new User('alice', 'Alice', 'a@example.com', $bad);
                self::fail('constructor accepted a non-argon2id hash');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('plaintext-password', $e->getMessage());
            }
        }

        $user = new User('alice', 'Alice', 'a@example.com', password_hash('x', \PASSWORD_ARGON2ID));
        $this->expectException(\InvalidArgumentException::class);
        $user->changePasswordHash('plaintext-password');
    }
}
