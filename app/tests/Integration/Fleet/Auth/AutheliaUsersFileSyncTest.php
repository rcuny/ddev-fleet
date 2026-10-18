<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\User\UserRepository;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class AutheliaUsersFileSyncTest extends KernelTestCase
{
    use FleetDatabase;

    private UserRepository $repository;
    private UserService $service;
    private AutheliaUsersFile $file;
    private FleetPaths $paths;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->repository = new UserRepository($em);
        $this->service = new UserService($this->repository, new PasswordPolicy());
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->file = new AutheliaUsersFile($paths, new AutheliaUsersRenderer());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testSyncRendersTheDatabaseAndIsIdempotent(): void
    {
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');

        self::assertTrue($this->file->sync($this->repository));
        self::assertFalse($this->file->sync($this->repository));
        self::assertSame(['alice'], array_keys(Yaml::parseFile($this->paths->usersFile())['users']));
    }

    public function testSyncSeesRowsChangedByAnotherProcess(): void
    {
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
        $this->file->sync($this->repository);

        // Another process commits behind this entity manager's back.
        $this->entityManager()->getConnection()->executeStatement("UPDATE fleet_user SET display_name = 'Changed Elsewhere' WHERE username = 'alice'");

        self::assertTrue($this->file->sync($this->repository));
        self::assertSame('Changed Elsewhere', Yaml::parseFile($this->paths->usersFile())['users']['alice']['displayname']);
    }

    public function testUsersAreReadOnlyWhileTheLockIsHeld(): void
    {
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
        $this->paths->ensureLayout();

        // A second process holds the lock: sync() must wait for it BEFORE reading, so it cannot render a stale
        // snapshot. The holder changes the database while holding the lock and then releases it.
        $lockFile = $this->paths->locksDir().'/authelia-users.lock';
        $db = $this->paths->fleetDb();
        $script = \sprintf(
            '$l=fopen(%s,"c");flock($l,LOCK_EX);echo "locked\n";flush();usleep(700000);'
            .'$p=new PDO("sqlite:"."%s");$p->exec("UPDATE fleet_user SET display_name=\'Written Under Lock\' WHERE username=\'alice\'");flock($l,LOCK_UN);',
            var_export($lockFile, true),
            $db,
        );
        $process = proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]));

        $this->file->sync($this->repository);
        proc_close($process);

        self::assertSame('Written Under Lock', Yaml::parseFile($this->paths->usersFile())['users']['alice']['displayname']);
    }
}
