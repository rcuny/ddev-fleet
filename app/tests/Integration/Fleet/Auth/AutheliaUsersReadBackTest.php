<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\System\AutheliaNotifier;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * D5: Authelia's self-service password reset rewrites a hash in users.yml; the next render must adopt it (database
 * and file keep it) instead of undoing it. The tests play Authelia by rewriting the file the way its file provider
 * does (its own YAML formatting, in place).
 */
final class AutheliaUsersReadBackTest extends KernelTestCase
{
    use FleetDatabase;

    private const string PASSWORD = 'correct horse battery staple';
    private const string RESET = 'a passphrase chosen on the reset page';

    private UserRepository $repository;
    private UserService $service;
    private AutheliaUsersFile $file;
    private FleetPaths $paths;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->repository = new UserRepository($em);
        $this->service = new UserService($this->repository, new PasswordPolicy(), new AdminGroup('fleet-admins'));
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->file = new AutheliaUsersFile($paths, new AutheliaUsersRenderer());
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $this->service->upsertAdmin('bob', 'Bob', 'bob@example.com', self::PASSWORD);
        $this->file->sync($this->repository);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * Authelia resets a password: new hash, its own formatting (no forced quotes, different indentation), in place.
     */
    private function autheliaResets(string $username, string $plain = self::RESET): string
    {
        $parsed = Yaml::parseFile($this->paths->usersFile());
        self::assertIsArray($parsed);
        $hash = password_hash($plain, \PASSWORD_ARGON2ID);
        $parsed['users'][$username]['password'] = $hash;
        file_put_contents($this->paths->usersFile(), Yaml::dump($parsed, 4, 4));

        return $hash;
    }

    private function storedHash(string $username): string
    {
        $this->entityManager()->clear();

        return (string) $this->repository->findByUsername($username)?->passwordHash();
    }

    private function fileHash(string $username): string
    {
        $parsed = Yaml::parseFile($this->paths->usersFile());
        self::assertIsArray($parsed);

        return $parsed['users'][$username]['password'];
    }

    public function testAdoptsAHashAutheliaChanged(): void
    {
        $theirs = $this->autheliaResets('alice');
        $bobBefore = $this->storedHash('bob');

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame(['alice'], $result->adopted);
        self::assertTrue($result->changed, 'the file is rewritten in Fleet\'s own format');
        self::assertSame($theirs, $this->storedHash('alice'));
        self::assertTrue(password_verify(self::RESET, $this->storedHash('alice')));
        self::assertSame($theirs, $this->fileHash('alice'));
        self::assertSame($bobBefore, $this->storedHash('bob'), 'only the changed user is touched');
    }

    public function testTheAdoptedHashSurvivesTheNextUserChange(): void
    {
        $theirs = $this->autheliaResets('alice');

        // An unrelated change: rename bob, render again (the way every users page save does).
        $this->repository->findByUsername('bob')?->updateProfile('Robert', 'bob@example.com');
        $this->repository->flush();
        $this->file->sync($this->repository);
        $this->file->sync($this->repository);

        self::assertSame($theirs, $this->fileHash('alice'));
        self::assertSame($theirs, $this->storedHash('alice'));
        self::assertSame('Robert', Yaml::parseFile($this->paths->usersFile())['users']['bob']['displayname']);
    }

    public function testNothingIsAdoptedWhenAutheliaChangedNothing(): void
    {
        $before = $this->storedHash('alice');
        $updated = $this->repository->findByUsername('alice')?->updatedAt();

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertFalse($result->changed);
        self::assertSame($before, $this->storedHash('alice'));
        self::assertEquals($updated, $this->repository->findByUsername('alice')?->updatedAt(), 'no write for a user nobody changed');
    }

    public function testAUserRemovedFromTheDatabaseIsNotBroughtBack(): void
    {
        $this->autheliaResets('bob');
        $bob = $this->repository->findByUsername('bob');
        self::assertNotNull($bob);
        $this->repository->remove($bob);

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertSame(['alice'], array_keys(Yaml::parseFile($this->paths->usersFile())['users']));
        self::assertNull($this->repository->findByUsername('bob'));
    }

    public function testAUserMissingFromTheFileIsLeftAlone(): void
    {
        $parsed = Yaml::parseFile($this->paths->usersFile());
        unset($parsed['users']['bob']);
        file_put_contents($this->paths->usersFile(), Yaml::dump($parsed, 4, 4));
        $before = $this->storedHash('bob');

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertSame($before, $this->storedHash('bob'));
        self::assertSame($before, $this->fileHash('bob'), 'the render restores it from the database');
    }

    public function testAPasswordFleetChangedMeanwhileWins(): void
    {
        $this->autheliaResets('alice');
        $this->service->setPassword('alice', 'set by an administrator');
        $fleets = $this->storedHash('alice');

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertSame($fleets, $this->storedHash('alice'));
        self::assertSame($fleets, $this->fileHash('alice'));
        self::assertTrue(password_verify('set by an administrator', $this->fileHash('alice')));
    }

    public function testAHashThatIsNotArgon2idIsNotAdopted(): void
    {
        $before = $this->storedHash('alice');
        $parsed = Yaml::parseFile($this->paths->usersFile());
        $parsed['users']['alice']['password'] = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012';
        file_put_contents($this->paths->usersFile(), Yaml::dump($parsed, 4, 4));

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertSame($before, $this->storedHash('alice'));
        self::assertSame($before, $this->fileHash('alice'));
    }

    public function testWithoutALastWrittenCopyNothingIsAdopted(): void
    {
        $before = $this->storedHash('alice');
        $this->autheliaResets('alice');
        unlink($this->paths->usersRenderedFile());

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted, 'no baseline to compare against');
        self::assertSame($before, $this->storedHash('alice'));
        self::assertFileExists($this->paths->usersRenderedFile(), 'the baseline is created for the next time');
    }

    public function testAnUnreadableFileIsReplacedNotFatal(): void
    {
        file_put_contents($this->paths->usersFile(), "users: [unclosed\n  - {");
        $before = $this->storedHash('alice');

        $result = $this->file->syncWithReport($this->repository);

        self::assertSame([], $result->adopted);
        self::assertTrue($result->changed);
        self::assertSame($before, $this->fileHash('alice'));
    }

    public function testAdoptionIsRolledBackWithTheTransactionThatFailsAfterIt(): void
    {
        $before = $this->storedHash('alice');
        $this->autheliaResets('alice');

        $thrown = $this->thrownBy(function (): void {
            $this->repository->transactional(function (): void {
                $this->file->sync($this->repository);

                throw new \RuntimeException('a later step failed');
            });
        });
        self::assertSame('a later step failed', $thrown?->getMessage(), 'the transaction rethrows');

        self::assertSame($before, $this->storedHash('alice'), 'nothing is committed when the flow fails');
    }

    public function testTheLastWrittenCopyIsPrivateAndFollowsTheFile(): void
    {
        $copy = $this->paths->usersRenderedFile();

        self::assertStringStartsWith($this->paths->varDir().'/', $copy, 'not in the directory Authelia can read');
        self::assertSame(0o600, fileperms($copy) & 0o777);
        self::assertSame((string) file_get_contents($this->paths->usersFile()), (string) file_get_contents($copy));

        $this->autheliaResets('alice');
        $this->file->sync($this->repository);

        self::assertSame((string) file_get_contents($this->paths->usersFile()), (string) file_get_contents($copy));
    }

    public function testTheFileIsGroupWritableOnlyWhenTheSelfServiceResetIsEnabled(): void
    {
        self::assertSame(0o640, fileperms($this->paths->usersFile()) & 0o777);

        $withReset = new AutheliaUsersFile($this->paths, new AutheliaUsersRenderer(), new AutheliaNotifier('smtp://mail.example.com', 'fleet@example.com'));
        $withReset->sync($this->repository);
        clearstatcache();

        self::assertSame(0o660, fileperms($this->paths->usersFile()) & 0o777, 'Authelia writes the file in place');
    }

    private function thrownBy(callable $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
