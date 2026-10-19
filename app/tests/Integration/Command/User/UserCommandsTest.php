<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\User;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * `fleet user:list|create|update|delete|grant|revoke` against a real migrated database: success, JSON, exit codes
 * (0 done, 1 refused value, 2 not allowed now, 3 not found), passwords only from a hidden prompt or stdin, the last
 * administrator, and the users file / Authelia install that follow every change.
 */
final class UserCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string PASSWORD = 'correct horse battery staple';
    private const string INSTALL = 'authelia';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $accounts = self::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        $accounts->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        foreach (['blog', 'demo'] as $name) {
            $projects->create(new ProjectInput(name: $name, git: 'https://git.example.com/acme/'.$name.'.git'));
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $more
     */
    private function create(string $username = 'bob', array $more = []): CommandTester
    {
        return $this->cli('fleet:user:create', ['username' => $username, '--email' => $username.'@example.com', '--password-stdin' => true, '--json' => true] + $more, [self::PASSWORD]);
    }

    private function stored(string $username): ?User
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(UserRepository::class)->findByUsername($username);
    }

    /**
     * @return list<string>
     */
    private function projectsOf(string $username): array
    {
        $access = self::getContainer()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $access);

        return $access->projectsOf($username);
    }

    /**
     * The JSON failure document as one string: the error, then `key: message` for every refused field.
     */
    private function errorText(CommandTester $tester): string
    {
        $document = $this->json($tester);
        $lines = [(string) $document['error']];
        foreach ((array) $document['fields'] as $key => $message) {
            $lines[] = $key.': '.$message;
        }

        return implode("\n", $lines);
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    private function completeSetup(): void
    {
        $host = new HostSettings('fleet.example.com');
        $host->markSetupComplete();
        $repository = self::getContainer()->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $repository);
        $repository->save($host);
    }

    private function installs(): int
    {
        return \count($this->runner()->callsMatching(['sudo', '-n', '--', PrivilegedApply::HELPER, self::INSTALL]));
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function usersFile(): array
    {
        $parsed = Yaml::parseFile(self::getContainer()->get(FleetPaths::class)->usersFile());
        self::assertIsArray($parsed);

        return $parsed['users'];
    }

    public function testNoCommandTakesAPasswordAsAnArgumentOrOption(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        foreach (['list', 'create', 'update', 'delete', 'grant', 'revoke'] as $verb) {
            $definition = $application->find('fleet:user:'.$verb)->getDefinition();
            foreach ([...array_keys($definition->getArguments()), ...array_keys($definition->getOptions())] as $name) {
                self::assertThat($name, self::logicalNot(self::equalTo('password')), "fleet user:$verb must not take --password");
            }
        }
        self::assertTrue($application->find('fleet:user:create')->getDefinition()->hasOption('password-stdin'));
    }

    public function testCreateFromStdinWritesTheUserAndTheUsersFile(): void
    {
        $created = $this->create('bob', ['--display-name' => 'Bob B.']);

        self::assertSame(0, $created->getStatusCode(), $created->getDisplay());
        self::assertSame('', $created->getErrorOutput());
        self::assertSame(
            ['username' => 'bob', 'display_name' => 'Bob B.', 'email' => 'bob@example.com', 'admin' => false, 'enabled' => true, 'projects' => []],
            $this->json($created),
        );
        $user = $this->stored('bob');
        self::assertNotNull($user);
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash()));
        self::assertSame([], $user->groups());
        self::assertSame($user->passwordHash(), $this->usersFile()['bob']['password']);
        self::assertStringNotContainsString(self::PASSWORD, $created->getDisplay());
        self::assertStringNotContainsString('$argon2id', $created->getDisplay());
    }

    public function testCreateAnAdministratorWithProjects(): void
    {
        $created = $this->create('carol', ['--admin' => true, '--project' => ['demo', 'blog', 'demo']]);

        self::assertSame(0, $created->getStatusCode(), $created->getDisplay());
        $document = $this->json($created);
        self::assertTrue($document['admin']);
        self::assertSame(['blog', 'demo'], $document['projects']);
        self::assertSame(['fleet-admins'], $this->stored('carol')?->groups());
        self::assertSame(['blog', 'demo'], $this->projectsOf('carol'));
        self::assertContains('fleet-admins', $this->usersFile()['carol']['groups']);
    }

    public function testCreateDefaultsTheDisplayNameToTheUsernameAndPrintsATextResult(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--password-stdin' => true], [self::PASSWORD]);

        self::assertSame(0, $created->getStatusCode());
        self::assertStringContainsString('User "bob" created.', $created->getDisplay());
        self::assertSame('bob', $this->stored('bob')?->displayName());
    }

    public function testCreateAsksTheHiddenPromptTwiceOnATerminal(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--json' => true], [self::PASSWORD, self::PASSWORD], true);

        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        self::assertTrue(password_verify(self::PASSWORD, (string) $this->stored('bob')?->passwordHash()));
        self::assertStringNotContainsString(self::PASSWORD, $created->getDisplay().$created->getErrorOutput());
    }

    public function testStdinPasswordKeepsSpacesAtEitherEnd(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--password-stdin' => true, '--json' => true], ['  spaced passphrase  ']);

        self::assertSame(0, $created->getStatusCode(), $created->getDisplay());
        self::assertTrue(password_verify('  spaced passphrase  ', (string) $this->stored('bob')?->passwordHash()));
    }

    public function testMismatchedPromptsCreateNothing(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--json' => true], [self::PASSWORD, self::PASSWORD.'!'], true);

        self::assertSame(1, $created->getStatusCode());
        // The tester has no separate error stream for prompts; on a terminal they go to stderr, not into the JSON.
        $document = json_decode((string) strstr($created->getDisplay(), '{'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('differ', $document['error']);
        self::assertNull($this->stored('bob'));
    }

    public function testWithoutATerminalOrStdinThePasswordIsRefused(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--json' => true]);

        self::assertSame(1, $created->getStatusCode());
        self::assertStringContainsString('--password-stdin', $this->json($created)['error']);
        self::assertNull($this->stored('bob'));
    }

    public function testAnEmptyStdinIsRefused(): void
    {
        $created = $this->cli('fleet:user:create', ['username' => 'bob', '--email' => 'bob@example.com', '--password-stdin' => true, '--json' => true], []);

        self::assertSame(1, $created->getStatusCode());
        self::assertStringContainsString('No password', $this->json($created)['error']);
        self::assertNull($this->stored('bob'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function badCreates(): iterable
    {
        yield 'weak password' => [[], 'short', 'at least 12'];
        yield 'bad username' => [['username' => 'Bob Smith'], self::PASSWORD, '<username>: The username must be'];
        yield 'bad email' => [['--email' => 'not-an-email'], self::PASSWORD, '--email: The email address is not valid'];
        yield 'bad display name' => [['--display-name' => "tab\there"], self::PASSWORD, '--display-name: The display name'];
        yield 'missing email' => [['--email' => ''], self::PASSWORD, '--email: An email address is required'];
        yield 'taken username' => [['username' => 'alice'], self::PASSWORD, '<username>: A user with this username already exists'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badCreates')]
    public function testRefusedValuesExitWithOneAndStoreNothing(array $override, string $password, string $message): void
    {
        $input = $override + ['username' => 'bob', '--email' => 'bob@example.com'];
        $before = $this->stored('alice')?->passwordHash();

        $created = $this->cli('fleet:user:create', $input + ['--password-stdin' => true, '--json' => true], [$password]);

        self::assertSame(1, $created->getStatusCode(), $created->getDisplay());
        self::assertStringContainsString($message, $this->errorText($created));
        self::assertNull($this->stored('bob'));
        self::assertSame($before, $this->stored('alice')?->passwordHash(), 'an existing user is never overwritten');
    }

    public function testAnUnknownProjectExitsWithThreeAndUndoesTheUser(): void
    {
        $created = $this->create('bob', ['--project' => ['blog', 'ghost']]);

        self::assertSame(3, $created->getStatusCode());
        self::assertStringContainsString('ghost', $this->json($created)['error']);
        self::assertNull($this->stored('bob'));
        self::assertFileDoesNotExist(self::getContainer()->get(FleetPaths::class)->usersFile(), 'nothing was rendered');
    }

    public function testListJsonAndTable(): void
    {
        $this->create('bob', ['--project' => ['blog']]);
        $this->cli('fleet:user:update', ['username' => 'bob', '--disable' => true]);

        $json = $this->json($this->cli('fleet:user:list', ['--json' => true]));
        self::assertSame(['alice', 'bob'], array_column($json, 'username'));
        self::assertTrue($json[0]['admin']);
        self::assertFalse($json[1]['enabled']);
        self::assertSame(['blog'], $json[1]['projects']);
        self::assertArrayNotHasKey('password_hash', $json[1]);

        $table = $this->cli('fleet:user:list');
        self::assertSame(0, $table->getStatusCode());
        $text = $this->squash($table->getDisplay());
        self::assertStringContainsString('USERNAME', $text);
        self::assertMatchesRegularExpression('#\balice Alice alice@example.com admin enabled all\b#', $text);
        self::assertMatchesRegularExpression('#\bbob bob bob@example.com member disabled blog\b#', $text);
        self::assertStringNotContainsString('$argon2id', $text);
    }

    public function testAnInvalidFormatIsRefusedByTheConsole(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->cli('fleet:user:list', ['--format' => 'xml']);
    }

    public function testUpdateChangesOnlyWhatIsGiven(): void
    {
        $this->create('bob');

        $updated = $this->cli('fleet:user:update', ['username' => 'bob', '--display-name' => 'Robert', '--email' => 'robert@example.com', '--json' => true]);

        self::assertSame(0, $updated->getStatusCode(), $updated->getDisplay());
        self::assertSame('Robert', $this->json($updated)['display_name']);
        $user = $this->stored('bob');
        self::assertSame('robert@example.com', $user?->email());
        self::assertTrue($user->isEnabled());
        self::assertSame('robert@example.com', $this->usersFile()['bob']['email']);
    }

    public function testUpdateDisableEnableAndAdminFlag(): void
    {
        $this->create('bob');

        self::assertSame(0, $this->cli('fleet:user:update', ['username' => 'bob', '--disable' => true])->getStatusCode());
        self::assertFalse($this->stored('bob')?->isEnabled());
        self::assertTrue($this->usersFile()['bob']['disabled'], 'a disabled user cannot sign in');

        self::assertSame(0, $this->cli('fleet:user:update', ['username' => 'bob', '--enable' => true])->getStatusCode());
        self::assertTrue($this->stored('bob')?->isEnabled());

        $promoted = $this->cli('fleet:user:update', ['username' => 'bob', '--admin' => true, '--json' => true]);
        self::assertTrue($this->json($promoted)['admin']);
        self::assertSame(['fleet-admins'], $this->stored('bob')?->groups());

        $demoted = $this->cli('fleet:user:update', ['username' => 'bob', '--no-admin' => true, '--json' => true]);
        self::assertSame(0, $demoted->getStatusCode(), $demoted->getDisplay());
        self::assertFalse($this->json($demoted)['admin']);
        self::assertSame([], $this->stored('bob')?->groups());
    }

    public function testTheLastEnabledAdministratorCannotBeDisabledOrDemoted(): void
    {
        $disabled = $this->cli('fleet:user:update', ['username' => 'alice', '--disable' => true, '--json' => true]);
        $demoted = $this->cli('fleet:user:update', ['username' => 'alice', '--no-admin' => true, '--json' => true]);

        self::assertSame(2, $disabled->getStatusCode());
        self::assertSame(2, $demoted->getStatusCode());
        self::assertStringContainsString('last enabled administrator', $this->json($demoted)['error']);
        $alice = $this->stored('alice');
        self::assertTrue($alice?->isEnabled());
        self::assertSame(['fleet-admins'], $alice->groups());
    }

    public function testASecondAdministratorMakesTheFirstDisposable(): void
    {
        $this->create('carol', ['--admin' => true]);

        self::assertSame(0, $this->cli('fleet:user:update', ['username' => 'alice', '--disable' => true])->getStatusCode());
        // alice is disabled, so carol is now the last enabled administrator.
        self::assertSame(2, $this->cli('fleet:user:update', ['username' => 'carol', '--no-admin' => true])->getStatusCode());
        self::assertSame(2, $this->cli('fleet:user:delete', ['username' => 'carol', '--yes' => true])->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function badUpdates(): iterable
    {
        yield 'nothing to change' => [['username' => 'bob'], 1, 'Nothing to change'];
        yield 'enable and disable' => [['username' => 'bob', '--enable' => true, '--disable' => true], 1, 'cannot be used together'];
        yield 'bad email' => [['username' => 'bob', '--email' => 'nope'], 1, '--email: The email address is not valid'];
        yield 'unknown user' => [['username' => 'ghost', '--email' => 'a@example.com'], 3, 'ghost'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('badUpdates')]
    public function testRefusedUpdates(array $input, int $code, string $message): void
    {
        $this->create('bob');

        $updated = $this->cli('fleet:user:update', $input + ['--json' => true]);

        self::assertSame($code, $updated->getStatusCode(), $updated->getDisplay());
        self::assertStringContainsString($message, $this->errorText($updated));
        self::assertSame('bob@example.com', $this->stored('bob')?->email());
    }

    public function testDeleteNeedsYesAndRemovesTheMemberships(): void
    {
        $this->create('bob', ['--project' => ['blog']]);

        $refused = $this->cli('fleet:user:delete', ['username' => 'bob', '--json' => true]);
        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('--yes', $this->json($refused)['error']);
        self::assertNotNull($this->stored('bob'));

        $deleted = $this->cli('fleet:user:delete', ['username' => 'bob', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode(), $deleted->getDisplay());
        self::assertSame(['deleted' => 'bob'], $this->json($deleted));
        self::assertNull($this->stored('bob'));
        self::assertArrayNotHasKey('bob', $this->usersFile());
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM project_access'));
    }

    public function testDeleteAsksOnATerminal(): void
    {
        $this->create('bob');

        $no = $this->cli('fleet:user:delete', ['username' => 'bob'], ['no'], true);
        self::assertSame(2, $no->getStatusCode());
        self::assertNotNull($this->stored('bob'));

        $yes = $this->cli('fleet:user:delete', ['username' => 'bob'], ['yes'], true);
        self::assertSame(0, $yes->getStatusCode());
        self::assertNull($this->stored('bob'));
    }

    public function testTheLastEnabledAdministratorCannotBeDeleted(): void
    {
        $deleted = $this->cli('fleet:user:delete', ['username' => 'alice', '--yes' => true, '--json' => true]);

        self::assertSame(2, $deleted->getStatusCode());
        self::assertStringContainsString('last enabled administrator', $this->json($deleted)['error']);
        self::assertNotNull($this->stored('alice'));
    }

    public function testDeletingAnUnknownUserExitsWithThree(): void
    {
        $deleted = $this->cli('fleet:user:delete', ['username' => 'ghost', '--yes' => true]);

        self::assertSame(3, $deleted->getStatusCode());
        self::assertStringContainsString('There is no user "ghost"', $deleted->getErrorOutput());
        self::assertSame('', $deleted->getDisplay());
    }

    public function testGrantAndRevokeAreIdempotent(): void
    {
        $this->create('bob');

        $first = $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'blog', '--json' => true]);
        $again = $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'blog', '--json' => true]);
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());
        self::assertSame(['username' => 'bob', 'project' => 'blog', 'granted' => true], $this->json($first));
        self::assertSame(0, $again->getStatusCode());
        self::assertFalse($this->json($again)['granted']);
        self::assertSame(['blog'], $this->projectsOf('bob'));

        $text = $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'demo']);
        self::assertStringContainsString('"bob" is now a member of "demo".', $text->getDisplay());

        $revoked = $this->cli('fleet:user:revoke', ['username' => 'bob', 'project' => 'blog', '--json' => true]);
        self::assertSame(['username' => 'bob', 'project' => 'blog', 'revoked' => true], $this->json($revoked));
        $again = $this->cli('fleet:user:revoke', ['username' => 'bob', 'project' => 'blog', '--json' => true]);
        self::assertSame(0, $again->getStatusCode());
        self::assertFalse($this->json($again)['revoked']);
        self::assertSame(['demo'], $this->projectsOf('bob'));
        self::assertStringContainsString('nothing changed', $this->cli('fleet:user:revoke', ['username' => 'bob', 'project' => 'blog'])->getDisplay());
    }

    public function testGrantAndRevokeNameWhatDoesNotExist(): void
    {
        $this->create('bob');

        foreach (['grant', 'revoke'] as $verb) {
            $project = $this->cli('fleet:user:'.$verb, ['username' => 'bob', 'project' => 'ghost', '--json' => true]);
            self::assertSame(3, $project->getStatusCode(), $verb);
            self::assertStringContainsString('Project "ghost" does not exist', $this->json($project)['error']);

            $user = $this->cli('fleet:user:'.$verb, ['username' => 'ghost', 'project' => 'blog']);
            self::assertSame(3, $user->getStatusCode(), $verb);
            self::assertStringContainsString('There is no user "ghost"', $user->getErrorOutput());
        }
        self::assertSame([], $this->projectsOf('bob'));
    }

    public function testAMembershipChangeInstallsTheAccessRulesOnceSetupIsComplete(): void
    {
        $this->create('bob');
        self::assertSame(0, $this->installs(), 'nothing is installed before setup completes');
        $this->completeSetup();

        $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'blog']);
        self::assertSame(1, $this->installs());

        $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'blog']);
        self::assertSame(1, $this->installs(), 'nothing changed: Authelia is not restarted');

        $this->cli('fleet:user:revoke', ['username' => 'bob', 'project' => 'blog']);
        self::assertSame(2, $this->installs());
    }

    public function testAFailedInstallIsAWarningNotARollback(): void
    {
        $this->create('bob');
        $this->completeSetup();
        $this->runner()->expect(['sudo'], new CommandResult(1, '', "apply: boom\n"));

        $granted = $this->cli('fleet:user:grant', ['username' => 'bob', 'project' => 'blog', '--json' => true]);

        self::assertSame(0, $granted->getStatusCode(), $granted->getDisplay());
        self::assertSame('', $granted->getErrorOutput());
        $document = $this->json($granted);
        self::assertTrue($document['granted']);
        self::assertStringContainsString('the sign-in service was not updated', $document['warning']);
        self::assertSame(['blog'], $this->projectsOf('bob'), 'the membership stands');

        $this->runner()->expect(['sudo'], new CommandResult(1, '', "apply: boom\n"));
        $text = $this->cli('fleet:user:revoke', ['username' => 'bob', 'project' => 'blog']);
        self::assertSame(0, $text->getStatusCode());
        self::assertStringContainsString('Warning: The change was saved', $text->getErrorOutput());
        self::assertSame([], $this->projectsOf('bob'));
    }

    public function testAFailedUsersFileWriteRollsTheChangeBack(): void
    {
        // A hand-edited row that cannot be rendered makes every change refuse; nothing is committed.
        $this->entityManager()->getConnection()->executeStatement("UPDATE fleet_user SET password_hash = 'not-a-hash' WHERE username = 'alice'");

        $created = $this->create('bob');

        self::assertSame(1, $created->getStatusCode(), $created->getDisplay());
        self::assertNull($this->stored('bob'));
    }
}
