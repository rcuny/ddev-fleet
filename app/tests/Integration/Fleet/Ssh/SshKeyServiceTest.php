<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Ssh;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\SshKeyService;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SshKeyServiceTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private SshKeyRepository $repository;
    private RecordingCommandRunner $runner;
    private SshKeyService $service;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->repository = new SshKeyRepository($em);
        $settings = new HostSettingsRepository($em);
        $settings->getOrCreate('fleet.example.com');
        $this->runner = new RecordingCommandRunner();
        $this->service = $this->serviceWith($this->runner);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testEnsureKeysIsIdempotent(): void
    {
        $this->service->ensureKeys();
        $first = $this->snapshot();

        $this->entityManager()->clear();
        $this->service->ensureKeys();
        $this->service->ensureKeys();

        self::assertSame($first, $this->snapshot(), 'a second run must not change keys, files or rows');
        self::assertSame(2, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ssh_key'));
        self::assertNotSame($first['deploy']['fingerprint'], $first['push']['fingerprint']);
        self::assertSame([], $this->runner->calls(), 'generation needs no process');
        self::assertSame($first['deploy']['fingerprint'], $this->service->fingerprint(SshKeyRole::Deploy));
        self::assertStringEndsWith(' fleet-deploy@fleet.example.com', $this->service->publicKey(SshKeyRole::Deploy));
        self::assertStringEndsWith(' fleet-push@fleet.example.com', $this->service->publicKey(SshKeyRole::Push));
    }

    public function testFilesHaveExpectedModes(): void
    {
        $this->service->ensureKeys();

        self::assertSame(0o700, fileperms($this->paths->sshDir()) & 0o777);
        foreach (SshKeyRole::cases() as $role) {
            self::assertSame(0o600, fileperms($this->paths->sshPrivateKey($role)) & 0o777);
            self::assertSame(0o644, fileperms($this->paths->sshPublicKey($role)) & 0o777);
        }
        $names = array_values(array_diff((array) scandir($this->paths->sshDir()), ['.', '..']));
        sort($names);
        self::assertSame(['.keys.lock', 'deploy_ed25519', 'deploy_ed25519.pub', 'push_ed25519', 'push_ed25519.pub'], $names, 'no temp files');
    }

    public function testEnsureKeysRepairsLooseModesAndMissingRows(): void
    {
        $this->service->ensureKeys();
        $fingerprint = $this->service->fingerprint(SshKeyRole::Deploy);
        chmod($this->paths->sshPrivateKey(SshKeyRole::Deploy), 0o644);
        chmod($this->paths->sshPublicKey(SshKeyRole::Deploy), 0o600);
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM ssh_key');
        $this->entityManager()->clear();

        $this->service->ensureKeys();

        self::assertSame(0o600, fileperms($this->paths->sshPrivateKey(SshKeyRole::Deploy)) & 0o777);
        self::assertSame(0o644, fileperms($this->paths->sshPublicKey(SshKeyRole::Deploy)) & 0o777);
        self::assertSame($fingerprint, $this->service->fingerprint(SshKeyRole::Deploy), 'the key on disk wins');
    }

    public function testEnsureKeysRebuildsAMissingPublicFileFromThePrivateKeyWithoutAProcess(): void
    {
        $this->service->ensureKeys();
        $private = (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Push));
        $line = $this->service->publicKey(SshKeyRole::Push);
        unlink($this->paths->sshPublicKey(SshKeyRole::Push));

        $this->service->ensureKeys();

        self::assertSame($private, (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Push)));
        self::assertSame($line."\n", (string) file_get_contents($this->paths->sshPublicKey(SshKeyRole::Push)));
        self::assertSame([], $this->runner->calls(), 'the openssh-key-v1 container is read in PHP');
    }

    public function testEnsureKeysRepairsAPublicFileThatDoesNotBelongToThePrivateKey(): void
    {
        $this->service->ensureKeys();
        $pushFingerprint = $this->service->fingerprint(SshKeyRole::Push);
        $pushLine = $this->service->publicKey(SshKeyRole::Push);
        // A crash between the two renames of a regenerate leaves exactly this: a stale .pub next to a new private key.
        copy($this->paths->sshPublicKey(SshKeyRole::Deploy), $this->paths->sshPublicKey(SshKeyRole::Push));
        $this->entityManager()->getConnection()->executeStatement("UPDATE ssh_key SET fingerprint = 'SHA256:stale' WHERE role = 'push'");
        $this->entityManager()->clear();

        $this->service->ensureKeys();

        self::assertSame($pushLine."\n", (string) file_get_contents($this->paths->sshPublicKey(SshKeyRole::Push)));
        self::assertSame($pushFingerprint, $this->service->fingerprint(SshKeyRole::Push));
        self::assertSame($pushLine, $this->service->publicKey(SshKeyRole::Push));
        self::assertSame(0o644, fileperms($this->paths->sshPublicKey(SshKeyRole::Push)) & 0o777);
        self::assertSame([], $this->runner->calls());
    }

    public function testEnsureKeysFallsBackToSshKeygenForOtherPrivateKeyFormats(): void
    {
        $this->service->ensureKeys();
        $other = new Ed25519KeyGenerator()->generate('o');
        file_put_contents($this->paths->sshPrivateKey(SshKeyRole::Push), "-----BEGIN RSA PRIVATE KEY-----\nQUJD\n-----END RSA PRIVATE KEY-----\n");
        $this->runner->expect(['ssh-keygen', '-y'], new CommandResult(0, explode(' ', $other->publicLine)[0].' '.explode(' ', $other->publicLine)[1]."\n"));

        $this->service->ensureKeys();

        self::assertSame(
            explode(' ', $other->publicLine)[1],
            explode(' ', (string) file_get_contents($this->paths->sshPublicKey(SshKeyRole::Push)))[1],
        );
        self::assertSame($other->fingerprint, $this->service->fingerprint(SshKeyRole::Push));
        self::assertCount(1, $this->runner->calls());
    }

    public function testEnsureKeysRefusesAnUnusablePrivateKeyRatherThanReplacingIt(): void
    {
        $this->service->ensureKeys();
        $garbage = "-----BEGIN RSA PRIVATE KEY-----\nQUJD\n-----END RSA PRIVATE KEY-----\n";
        file_put_contents($this->paths->sshPrivateKey(SshKeyRole::Push), $garbage);
        $this->runner->expect(['ssh-keygen', '-y'], new CommandResult(1, '', 'incorrect passphrase supplied'));

        try {
            $this->service->ensureKeys();
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
        self::assertSame($garbage, (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Push)));
    }

    public function testTheKeyLockIsHeldWhileKeysAreChangedAndReleasedAfterwards(): void
    {
        $lockPath = $this->paths->sshDir().'/.keys.lock';
        $heldDuring = [];
        $probe = new class($lockPath) implements CommandRunner {
            /** @var list<bool> */
            public array $lockWasBusy = [];

            public function __construct(private readonly string $lockPath)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                // A second open file description: flock() conflicts across descriptions even in one process.
                $other = fopen($this->lockPath, 'c');
                \assert(false !== $other);
                $acquired = flock($other, \LOCK_EX | \LOCK_NB);
                $this->lockWasBusy[] = !$acquired;
                $acquired && flock($other, \LOCK_UN);
                fclose($other);

                return new CommandResult(1);
            }
        };
        $service = $this->serviceWith($probe);

        try {
            $service->importPrivate(SshKeyRole::Deploy, "-----BEGIN OPENSSH PRIVATE KEY-----\nQUJD\n-----END OPENSSH PRIVATE KEY-----", 'admin');
        } catch (\InvalidArgumentException) {
        }
        $heldDuring = $probe->lockWasBusy;

        self::assertSame([true], $heldDuring, 'ssh-keygen runs while the lock is held');
        $other = fopen($lockPath, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, \LOCK_EX | \LOCK_NB), 'the lock is released after the call, also on failure');
        fclose($other);

        $service->regenerate(SshKeyRole::Push, 'admin');
        $service->ensureKeys();
        $other = fopen($lockPath, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, \LOCK_EX | \LOCK_NB));
        fclose($other);
    }

    public function testRegenerateChangesFingerprint(): void
    {
        $this->service->ensureKeys();
        $deploy = $this->service->fingerprint(SshKeyRole::Deploy);
        $push = $this->service->fingerprint(SshKeyRole::Push);
        $oldPrivate = (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Deploy));

        $this->service->regenerate(SshKeyRole::Deploy, 'admin');
        $this->entityManager()->clear();

        self::assertNotSame($deploy, $this->service->fingerprint(SshKeyRole::Deploy));
        self::assertSame($push, $this->service->fingerprint(SshKeyRole::Push), 'the other role is untouched');
        self::assertNotSame($oldPrivate, (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Deploy)));
        self::assertSame($this->service->publicKey(SshKeyRole::Deploy)."\n", (string) file_get_contents($this->paths->sshPublicKey(SshKeyRole::Deploy)));
        self::assertSame(0o600, fileperms($this->paths->sshPrivateKey(SshKeyRole::Deploy)) & 0o777);
        self::assertSame(2, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ssh_key'));
    }

    public function testImportRejectsPassphraseProtectedKey(): void
    {
        $this->service->ensureKeys();
        $before = $this->snapshot();
        $this->runner->expect(['ssh-keygen', '-y'], new CommandResult(1, '', 'Load key: incorrect passphrase supplied to decrypt private key'));

        try {
            $this->service->importPrivate(SshKeyRole::Deploy, "-----BEGIN OPENSSH PRIVATE KEY-----\nQUJD\n-----END OPENSSH PRIVATE KEY-----\n", 'admin');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('QUJD', $e->getMessage());
        }

        self::assertSame($before, $this->snapshot(), 'the existing key must survive a rejected import');
        self::assertSame(['-P', ''], \array_slice($this->runner->calls()[0]->argv, 2, 2), 'an empty passphrase, never a prompt');
        $this->assertNoTempFiles();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPrivateKeys(): iterable
    {
        yield 'public key' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA fleet'];
        yield 'empty' => [''];
        yield 'nul byte' => ["-----BEGIN OPENSSH PRIVATE KEY-----\n\0\n"];
        yield 'huge' => ["-----BEGIN OPENSSH PRIVATE KEY-----\n".str_repeat('A', 20000)."\n"];
    }

    #[DataProvider('notPrivateKeys')]
    public function testImportRejectsInputThatIsNotAPrivateKeyWithoutRunningAnything(string $pem): void
    {
        try {
            $this->service->importPrivate(SshKeyRole::Deploy, $pem, 'admin');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->runner->calls());
            self::assertFileDoesNotExist($this->paths->sshPrivateKey(SshKeyRole::Deploy));
        }
    }

    public function testImportStoresPublicKeyDerivedBySshKeygen(): void
    {
        $generated = new Ed25519KeyGenerator()->generate('their-comment');
        [, $blob] = explode(' ', $generated->publicLine);
        $this->runner->expect(['ssh-keygen', '-y'], new CommandResult(0, "ssh-ed25519 $blob their-comment\n"));

        $this->service->importPrivate(SshKeyRole::Push, str_replace("\n", "\r\n", $generated->privatePem()), 'admin');

        $argv = $this->runner->calls()[0]->argv;
        self::assertSame(['ssh-keygen', '-y', '-P', '', '-f'], \array_slice($argv, 0, 5));
        self::assertStringStartsWith($this->paths->sshDir().'/', $argv[5]);
        self::assertNotSame($this->paths->sshPrivateKey(SshKeyRole::Push), $argv[5], 'derived from a temp copy, not the final file');
        self::assertSame($generated->privatePem(), (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Push)));
        self::assertSame(0o600, fileperms($this->paths->sshPrivateKey(SshKeyRole::Push)) & 0o777);
        self::assertSame("ssh-ed25519 $blob fleet-push@fleet.example.com", $this->service->publicKey(SshKeyRole::Push));
        self::assertSame($generated->fingerprint, $this->service->fingerprint(SshKeyRole::Push));
        self::assertSame($this->service->publicKey(SshKeyRole::Push)."\n", (string) file_get_contents($this->paths->sshPublicKey(SshKeyRole::Push)));
        $this->assertNoTempFiles();
    }

    public function testTheTempPrivateKeyIsPrivateWhileSshKeygenRunsAndGoneAfterwardsEvenOnFailure(): void
    {
        $probe = new class implements CommandRunner {
            /** @var list<array{string, int, bool}> */
            public array $seen = [];

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $path = $argv[5];
                $this->seen[] = [$path, fileperms($path) & 0o777, str_contains((string) file_get_contents($path), 'PRIVATE KEY')];

                return new CommandResult(1);
            }
        };
        $service = $this->serviceWith($probe);

        try {
            $service->importPrivate(SshKeyRole::Deploy, "-----BEGIN OPENSSH PRIVATE KEY-----\nQUJD\n-----END OPENSSH PRIVATE KEY-----", 'admin');
        } catch (\InvalidArgumentException) {
        }

        $seen = $probe->seen;
        self::assertCount(1, $seen);
        self::assertSame(0o600, $seen[0][1]);
        self::assertTrue($seen[0][2]);
        self::assertFileDoesNotExist($seen[0][0]);
        $this->assertNoTempFiles();
    }

    public function testImportWithTheRealSshKeygenRoundTripsAGeneratedKey(): void
    {
        $real = new ProcessCommandRunner();
        if (127 === $real->run(['ssh-keygen', '-?'])->exitCode) {
            self::markTestSkipped('ssh-keygen is not installed.');
        }
        $service = $this->serviceWith($real);
        $generated = new Ed25519KeyGenerator()->generate('x');

        $service->importPrivate(SshKeyRole::Deploy, $generated->privatePem(), 'admin');

        self::assertSame($generated->fingerprint, $service->fingerprint(SshKeyRole::Deploy));
        self::assertSame(explode(' ', $generated->publicLine)[1], explode(' ', $service->publicKey(SshKeyRole::Deploy))[1]);
        $this->assertNoTempFiles();

        // ... and a key ensureKeys() generated is accepted by it too.
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM ssh_key');
        $this->entityManager()->clear();
        unlink($this->paths->sshPublicKey(SshKeyRole::Deploy));
        $service->ensureKeys();
        self::assertSame($generated->fingerprint, $service->fingerprint(SshKeyRole::Deploy));
    }

    public function testNoPrivateKeyMaterialInTheDatabaseOrThePublicAccessors(): void
    {
        $this->service->ensureKeys();
        $private = (string) file_get_contents($this->paths->sshPrivateKey(SshKeyRole::Deploy));
        $body = trim(explode("\n", $private)[1]);

        $rows = $this->entityManager()->getConnection()->fetchAllAssociative('SELECT * FROM ssh_key');
        self::assertStringNotContainsString($body, json_encode($rows, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE KEY', $this->service->publicKey(SshKeyRole::Deploy).$this->service->fingerprint(SshKeyRole::Deploy));
    }

    public function testAccessorsBeforeEnsureKeysFailClearly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->publicKey(SshKeyRole::Deploy);
    }

    public function testRequesterIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->regenerate(SshKeyRole::Deploy, '');
    }

    private function serviceWith(CommandRunner $runner): SshKeyService
    {
        return new SshKeyService(
            $this->paths,
            $this->repository,
            new HostSettingsRepository($this->entityManager()),
            new Ed25519KeyGenerator(),
            $runner,
        );
    }

    /**
     * @return array<string, array{fingerprint: string, publicKey: string, private: string, public: string}>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (SshKeyRole::cases() as $role) {
            $snapshot[$role->value] = [
                'fingerprint' => $this->service->fingerprint($role),
                'publicKey' => $this->service->publicKey($role),
                'private' => hash('sha256', (string) file_get_contents($this->paths->sshPrivateKey($role))),
                'public' => hash('sha256', (string) file_get_contents($this->paths->sshPublicKey($role))),
            ];
        }

        return $snapshot;
    }

    private function assertNoTempFiles(): void
    {
        $leftovers = array_filter((array) scandir($this->paths->sshDir()), static fn (string|false $name): bool => \is_string($name) && str_ends_with($name, '.tmp'));
        self::assertSame([], array_values($leftovers));
    }
}
