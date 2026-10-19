<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain\User;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FLE-44: UserRepository::transactional() takes the SQLite write lock when it starts. With a deferred transaction a
 * change that reads first fails with "database is locked" as soon as another connection commits in between (parallel
 * Playwright workers hit it on POST /admin/users/new).
 */
final class UserRepositoryTransactionTest extends KernelTestCase
{
    use FleetDatabase;

    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Zm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm8';

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

    public function testAnotherConnectionCannotWriteWhileATransactionIsOpenEvenBeforeItsFirstWrite(): void
    {
        $other = new \PDO('sqlite:'.self::getContainer()->get(FleetPaths::class)->fleetDb());
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        $blocked = null;
        $this->repository->transactional(function () use ($other, &$blocked): void {
            // Only a read so far: a deferred transaction holds no write lock yet and the other writer would get in.
            $this->repository->all();
            try {
                $other->exec('BEGIN IMMEDIATE');
                $other->exec('ROLLBACK');
            } catch (\PDOException $e) {
                $blocked = $e->getMessage();
            }
        });

        self::assertNotNull($blocked, 'the write lock is held from the start of the transaction');
        self::assertStringContainsString('locked', $blocked);
    }

    public function testTheLockIsReleasedAfterTheCommitAndAfterARollback(): void
    {
        $other = new \PDO('sqlite:'.self::getContainer()->get(FleetPaths::class)->fleetDb());
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        $this->repository->transactional(static fn (): null => null);
        try {
            $this->repository->transactional(static function (): void {
                throw new \RuntimeException('refused');
            });
        } catch (\RuntimeException) {
        }

        $other->exec('BEGIN IMMEDIATE');
        $other->exec('ROLLBACK');
        $this->addToAssertionCount(1);
    }

    public function testSaveAndRemoveTakeTheWriteLockThemselves(): void
    {
        $other = new \PDO('sqlite:'.self::getContainer()->get(FleetPaths::class)->fleetDb());
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);

        $user = new User('ada', 'Ada', 'ada@example.com', self::HASH, []);
        $this->repository->save($user);
        $this->repository->remove($user);

        // Both committed and released the lock: another writer gets in at once.
        $other->exec('BEGIN IMMEDIATE');
        $other->exec('ROLLBACK');
        self::assertNull($this->repository->findByUsername('ada'));
    }

    public function testSaveInsideATransactionJoinsIt(): void
    {
        try {
            $this->repository->transactional(function (): void {
                $this->repository->save(new User('bob', 'Bob', 'bob@example.com', self::HASH, []));
                throw new \RuntimeException('refused');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($this->repository->findByUsername('bob'), 'rolled back with the surrounding transaction');
    }
}
