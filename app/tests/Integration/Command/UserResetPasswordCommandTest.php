<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\UserResetPasswordCommand;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\System\ApplyTarget;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RecordingApplier;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class UserResetPasswordCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private const string OLD = 'the original passphrase';
    private const string NEW = 'a brand new passphrase';

    private UserRepository $repository;
    private UserService $service;
    private FleetPaths $paths;
    private RecordingApplier $applier;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->repository = new UserRepository($em);
        $this->service = new UserService($this->repository, new PasswordPolicy());
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->applier = new RecordingApplier();
        $this->service->upsertAdmin('alice', 'Alice', 'alice@example.com', self::OLD);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param list<string>         $inputs
     * @param array<string, mixed> $options
     */
    private function reset(array $inputs, string $username = 'alice', array $options = []): CommandTester
    {
        $applier = $this->applier;
        $command = new UserResetPasswordCommand(
            $this->repository,
            $this->service,
            new PasswordPolicy(),
            new AutheliaUsersFile($this->paths, new AutheliaUsersRenderer()),
            $applier,
        );
        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute(['username' => $username] + $options, ['interactive' => true]);

        return $tester;
    }

    private function storedHash(): ?string
    {
        $this->entityManager()->clear();

        return $this->repository->findByUsername('alice')?->passwordHash();
    }

    public function testResetsRendersAndApplies(): void
    {
        $tester = $this->reset([self::NEW, self::NEW]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue(password_verify(self::NEW, (string) $this->storedHash()), 'the line break is not part of the password');
        self::assertSame([ApplyTarget::Authelia], $this->applier->applied);

        $file = $this->paths->usersFile();
        self::assertSame(0o640, fileperms($file) & 0o777);
        $parsed = Yaml::parseFile($file);
        self::assertIsArray($parsed);
        self::assertSame($this->storedHash(), $parsed['users']['alice']['password']);
        self::assertStringNotContainsString(self::NEW, $tester->getDisplay());
        self::assertStringNotContainsString(self::NEW, (string) file_get_contents($file));
        self::assertStringNotContainsString('$argon2id', $tester->getDisplay());
    }

    public function testSpacesAtEitherEndArePartOfThePassword(): void
    {
        $tester = $this->reset(['  spaced passphrase  ', '  spaced passphrase  ']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertTrue(password_verify('  spaced passphrase  ', (string) $this->storedHash()));
    }

    public function testUnknownUserFails(): void
    {
        $tester = $this->reset([self::NEW, self::NEW], 'ghost');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('There is no user "ghost"', $tester->getDisplay());
        self::assertSame([], $this->applier->applied);
        self::assertFileDoesNotExist($this->paths->usersFile());
    }

    public function testWeakPasswordFails(): void
    {
        $before = $this->storedHash();

        $tester = $this->reset(['short', 'short']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('at least 12', $tester->getDisplay());
        self::assertSame($before, $this->storedHash());
        self::assertSame([], $this->applier->applied);
        self::assertFileDoesNotExist($this->paths->usersFile());
    }

    public function testMismatchedConfirmationFails(): void
    {
        $before = $this->storedHash();

        $tester = $this->reset([self::NEW, self::NEW.'!']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('differ', $tester->getDisplay());
        self::assertSame($before, $this->storedHash());
        self::assertSame([], $this->applier->applied);
    }

    public function testNoApplySkipsSudo(): void
    {
        $tester = $this->reset([self::NEW, self::NEW], options: ['--no-apply' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->applier->applied);
        self::assertFileExists($this->paths->usersFile());
        self::assertTrue(password_verify(self::NEW, (string) $this->storedHash()));
    }

    public function testFailedApplyIsReportedWithoutSecrets(): void
    {
        $this->applier->fails = new \RuntimeException('apply authelia failed (exit 1)');

        $tester = $this->reset([self::NEW, self::NEW]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('apply authelia failed', $tester->getDisplay());
        self::assertStringNotContainsString(self::NEW, $tester->getDisplay());
        self::assertSame([ApplyTarget::Authelia], $this->applier->applied);
    }

    public function testPasswordQuestionIsHiddenWithoutEchoFallbackAndUntrimmed(): void
    {
        $question = UserResetPasswordCommand::passwordQuestion('New password');

        self::assertTrue($question->isHidden());
        self::assertFalse($question->isHiddenFallback(), 'a terminal that cannot hide typing must make the command fail, not echo');
        self::assertFalse($question->isTrimmable());
    }

    public function testHelpExplainsTheTerminalRequirement(): void
    {
        $command = new UserResetPasswordCommand(
            $this->repository,
            $this->service,
            new PasswordPolicy(),
            new AutheliaUsersFile($this->paths, new AutheliaUsersRenderer()),
            $this->applier,
        );

        self::assertStringContainsString('ssh -t', $command->getHelp());
    }

    public function testAFailingUsersFileLeavesPasswordAndFileUntouched(): void
    {
        // One corrupt row: a hash that is not argon2id (hand-edited database). Rendering must refuse, and the
        // password change made in the same flow must be rolled back with it.
        $this->service->upsertAdmin('bob', 'Bob', 'bob@example.com', self::OLD);
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE fleet_user SET password_hash = :h WHERE username = :u',
            ['h' => 'not-a-hash', 'u' => 'bob'],
        );
        $this->entityManager()->clear();
        $before = $this->storedHash();

        $tester = $this->reset([self::NEW, self::NEW]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertStringNotContainsString('not-a-hash', $tester->getDisplay());
        self::assertSame($before, $this->storedHash());
        self::assertFileDoesNotExist($this->paths->usersFile());
        self::assertSame([], $this->applier->applied);
    }

    public function testRefusesToRunWithoutATerminal(): void
    {
        $command = new UserResetPasswordCommand(
            $this->repository,
            $this->service,
            new PasswordPolicy(),
            new AutheliaUsersFile($this->paths, new AutheliaUsersRenderer()),
            new RecordingApplier(),
        );
        $tester = new CommandTester($command);
        $tester->execute(['username' => 'alice'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('interactively', $tester->getDisplay());
    }

    public function testIsRegisteredAndNotYetBackedByARealApplier(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $command = $application->find('fleet:user:reset-password');
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        self::assertInstanceOf(UserResetPasswordCommand::class, $command);

        // Until Task 6 the container wires UnavailableApplier: the password is stored, the apply fails loudly.
        $tester = new CommandTester($command);
        $tester->setInputs([self::NEW, self::NEW]);
        $tester->execute(['username' => 'alice'], ['interactive' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not available yet', $tester->getDisplay());
    }
}
