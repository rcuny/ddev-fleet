<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\LastAdminGuard;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserChangeRefused;
use App\Fleet\Auth\UserService;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UserService's create / updateProfile / setEnabled / setAdmin / delete and the LastAdminGuard in front of them.
 */
final class UserManagementTest extends KernelTestCase
{
    use FleetDatabase;

    private const string PASSWORD = 'correct horse battery staple';

    private UserRepository $repository;
    private UserService $service;
    private LastAdminGuard $guard;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->repository = new UserRepository($em);
        $admins = new AdminGroup('fleet-admins');
        $this->service = new UserService($this->repository, new PasswordPolicy(), $admins);
        $this->guard = new LastAdminGuard($this->repository, $admins);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateMakesAMemberOrAnAdministrator(): void
    {
        $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);
        $this->service->create('carol', 'Carol', 'carol@example.com', self::PASSWORD, true);
        $this->entityManager()->clear();

        self::assertSame([], $this->repository->findByUsername('bob')?->groups());
        self::assertSame(['fleet-admins'], $this->repository->findByUsername('carol')?->groups());
        self::assertTrue(password_verify(self::PASSWORD, (string) $this->repository->findByUsername('bob')?->passwordHash()));
    }

    public function testCreateNeverOverwritesAnExistingUser(): void
    {
        $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);
        $hash = $this->repository->findByUsername('bob')?->passwordHash();

        try {
            $this->service->create('bob', 'Other', 'other@example.com', 'another passphrase entirely');
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        $this->entityManager()->clear();
        $bob = $this->repository->findByUsername('bob');
        self::assertSame('Bob', $bob?->displayName());
        self::assertSame($hash, $bob->passwordHash());
    }

    public function testCreateRefusesBadValuesAndStoresNothing(): void
    {
        foreach ([
            ['Bad Name', 'Bob', 'bob@example.com', self::PASSWORD],
            ['bob', '', 'bob@example.com', self::PASSWORD],
            ['bob', 'Bob', 'nope', self::PASSWORD],
            ['bob', 'Bob', 'bob@example.com', 'short'],
        ] as [$username, $name, $email, $password]) {
            try {
                $this->service->create($username, $name, $email, $password);
                self::fail('Expected a refusal for '.$username.'/'.$email);
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->repository->all());
    }

    public function testUpdateProfileKeepsWhatIsNull(): void
    {
        $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);

        $this->service->updateProfile('bob', 'Robert', null);
        $this->service->updateProfile('bob', null, 'robert@example.com');
        $this->entityManager()->clear();

        $bob = $this->repository->findByUsername('bob');
        self::assertSame('Robert', $bob?->displayName());
        self::assertSame('robert@example.com', $bob->email());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateProfile('bob', null, 'nope');
    }

    public function testEnableAndAdminFlagKeepOtherGroups(): void
    {
        $user = $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);
        $user->changeGroups(['dev']);
        $this->repository->save($user);

        $this->service->setAdmin('bob', true);
        $this->service->setAdmin('bob', true);
        self::assertSame(['dev', 'fleet-admins'], $this->bob()->groups());

        $this->service->setAdmin('bob', false);
        self::assertSame(['dev'], $this->bob()->groups());

        $this->service->setEnabled('bob', false);
        self::assertFalse($this->bob()->isEnabled());
        $this->service->setEnabled('bob', true);
        self::assertTrue($this->bob()->isEnabled());
    }

    private function bob(): User
    {
        $this->entityManager()->clear();
        $bob = $this->repository->findByUsername('bob');
        self::assertNotNull($bob);

        return $bob;
    }

    public function testDeleteAndUnknownUsers(): void
    {
        $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);

        $this->service->delete('bob');
        self::assertNull($this->repository->findByUsername('bob'));

        foreach ([
            fn () => $this->service->delete('bob'),
            fn () => $this->service->setEnabled('bob', true),
            fn () => $this->service->setAdmin('bob', true),
            fn () => $this->service->updateProfile('bob', 'x', null),
        ] as $call) {
            try {
                $call();
                self::fail('Expected UnknownUser.');
            } catch (UnknownUser) {
            }
        }
    }

    public function testTheGuardProtectsTheLastEnabledAdministrator(): void
    {
        $alice = $this->service->create('alice', 'Alice', 'alice@example.com', self::PASSWORD, true);
        $bob = $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD);

        foreach (['assertMayDelete', 'assertMayDisable', 'assertMayDemote'] as $check) {
            try {
                $this->guard->{$check}($alice);
                self::fail($check.' should refuse the last administrator.');
            } catch (UserChangeRefused $e) {
                self::assertStringContainsString('last enabled administrator', $e->getMessage());
            }
            $this->guard->{$check}($bob);
        }

        $carol = $this->service->create('carol', 'Carol', 'carol@example.com', self::PASSWORD, true);
        $this->guard->assertMayDelete($alice);
        $this->guard->assertMayDisable($carol);

        // A disabled administrator does not count: carol is the last enabled one now.
        $this->service->setEnabled('alice', false);
        $this->expectException(UserChangeRefused::class);
        $this->guard->assertMayDelete($carol);
    }

    public function testTheGuardRefusesTheActingUserOnThemselves(): void
    {
        $this->service->create('alice', 'Alice', 'alice@example.com', self::PASSWORD, true);
        $carol = $this->service->create('carol', 'Carol', 'carol@example.com', self::PASSWORD, true);

        $this->guard->assertMayDelete($carol, 'alice');
        try {
            $this->guard->assertMayDelete($carol, 'carol');
            self::fail('Expected a refusal.');
        } catch (UserChangeRefused $e) {
            self::assertStringContainsString('Your own account cannot be deleted', $e->getMessage());
        }
        $this->expectException(UserChangeRefused::class);
        $this->guard->assertMayDisable($carol, 'carol');
    }

    public function testADisabledAdministratorMayBeDeleted(): void
    {
        $this->service->create('alice', 'Alice', 'alice@example.com', self::PASSWORD, true);
        $bob = $this->service->create('bob', 'Bob', 'bob@example.com', self::PASSWORD, true);
        $this->service->setEnabled('bob', false);

        $this->guard->assertMayDelete($bob);
        $this->addToAssertionCount(1);
    }
}
