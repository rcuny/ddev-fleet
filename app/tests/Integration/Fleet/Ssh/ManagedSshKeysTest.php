<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Ssh;

use App\Domain\Ssh\ManagedSshKeySource;
use App\Domain\Template\SshIdentities;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\ManagedSshKeys;
use App\Fleet\Ssh\SshKeyCatalog;
use App\Fleet\Ssh\SshKeyService;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ManagedSshKeysTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private ManagedSshKeys $keys;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $keys = self::getContainer()->get(ManagedSshKeys::class);
        self::assertInstanceOf(ManagedSshKeys::class, $keys);
        $this->keys = $keys;
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testCreateWritesTheKeyPairAndKeepsOnlyThePublicHalfInTheDatabase(): void
    {
        $row = $this->keys->create('staging', 'tester');

        $file = $this->paths->sshExtraKey('staging');
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame(0o644, fileperms($file.'.pub') & 0o777);
        self::assertSame(ManagedSshKeySource::Generated, $row->source());
        self::assertStringStartsWith('ssh-ed25519 ', $row->publicKey());
        self::assertSame(trim((string) file_get_contents($file.'.pub')), $row->publicKey());
        $private = (string) file_get_contents($file);
        $dump = json_encode($this->entityManager()->getConnection()->fetchAllAssociative('SELECT * FROM ssh_extra_key'), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(substr($private, 40, 40), $dump, 'no private key material in the database');
        self::assertStringNotContainsString('PRIVATE KEY', $dump);
    }

    public function testCreateRefusesBadReservedAndTakenNames(): void
    {
        foreach (['deploy', 'push', 'Bad Name', '../x', ''] as $name) {
            try {
                $this->keys->create($name, 'tester');
                self::fail($name.' was created');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->keys->create('staging', 'tester');
        $this->expectException(Refused::class);
        $this->keys->create('staging', 'tester');
    }

    public function testCreateDoesNotOverwriteAHandPlacedFile(): void
    {
        @mkdir($this->paths->sshDir(), 0o700, true);
        file_put_contents($this->paths->sshExtraKey('legacy'), "mine\n");
        chmod($this->paths->sshExtraKey('legacy'), 0o600);

        try {
            $this->keys->create('legacy', 'tester');
            self::fail('overwrote a hand-placed key');
        } catch (Refused) {
            self::assertSame("mine\n", file_get_contents($this->paths->sshExtraKey('legacy')));
        }
    }

    public function testImportDerivesThePublicKeyAndRefusesGarbageAndEncryptedKeys(): void
    {
        if (!$this->hasSshKeygen()) {
            self::markTestSkipped('ssh-keygen is not available.');
        }
        $generated = new Ed25519KeyGenerator()->generate('imported-test');

        $row = $this->withRealKeygen()->import('vendor', $generated->privatePem(), 'tester');

        self::assertSame(ManagedSshKeySource::Imported, $row->source());
        self::assertSame($generated->fingerprint, $row->fingerprint());
        self::assertSame(0o600, fileperms($this->paths->sshExtraKey('vendor')) & 0o777);

        foreach (['not a key', "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n", "\0"] as $bad) {
            try {
                $this->withRealKeygen()->import('other', $bad, 'tester');
                self::fail('imported garbage');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('AAAA', $e->getMessage());
            }
        }
        self::assertFileDoesNotExist($this->paths->sshExtraKey('other'));
    }

    public function testImportNamesTheFileAfterTheRealKeyType(): void
    {
        if (!$this->hasSshKeygen()) {
            self::markTestSkipped('ssh-keygen is not available.');
        }
        $dir = sys_get_temp_dir().'/fle270-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['rsa' => ['rsa', '-b', '2048'], 'ecdsa' => ['ecdsa', '-b', '256']] as $suffix => $args) {
                exec(\sprintf('ssh-keygen -q -t %s -b %s -N "" -f %s 2>&1', $args[0], $args[2], escapeshellarg($dir.'/'.$suffix)), $out, $code);
                self::assertSame(0, $code);
                $pem = (string) file_get_contents($dir.'/'.$suffix);

                $this->withRealKeygen()->import('k'.$suffix, $pem, 'tester');

                self::assertFileExists($this->paths->sshExtraKey('k'.$suffix, $suffix));
                self::assertFileExists($this->paths->sshExtraKey('k'.$suffix, $suffix).'.pub');
                self::assertFileDoesNotExist($this->paths->sshExtraKey('k'.$suffix));
                self::assertSame($this->paths->sshExtraKey('k'.$suffix, $suffix), $this->paths->existingSshExtraKey('k'.$suffix));
                self::assertNull(new \App\Fleet\Ssh\ExtraSshKey($this->paths)->problem('k'.$suffix));
                // A second key of the same name is refused whatever its type.
                try {
                    $this->withRealKeygen()->create('k'.$suffix, 'tester');
                    self::fail('name reused');
                } catch (Refused) {
                }
                $this->withRealKeygen()->delete('k'.$suffix, 'tester');
                self::assertFileDoesNotExist($this->paths->sshExtraKey('k'.$suffix, $suffix));
                self::assertFileDoesNotExist($this->paths->sshExtraKey('k'.$suffix, $suffix).'.pub');
            }
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testDeleteRemovesFilesAndRowButNotWhileATemplateUsesIt(): void
    {
        $this->keys->create('staging', 'tester');
        $this->useInTemplate('staging');

        try {
            $this->keys->delete('staging', 'tester');
            self::fail('deleted a key in use');
        } catch (Refused $e) {
            self::assertStringContainsString('blog/web', $e->getMessage());
            self::assertFileExists($this->paths->sshExtraKey('staging'));
        }

        $templates = self::getContainer()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->update('blog', 'web', TemplateInput::fromTemplate($templates->get('blog', 'web'))->with(['sshKeys' => [], 'sshIdentities' => SshIdentities::PushAndExtras]));
        $this->keys->delete('staging', 'tester');

        self::assertFileDoesNotExist($this->paths->sshExtraKey('staging'));
        self::assertFileDoesNotExist($this->paths->sshExtraKey('staging').'.pub');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ssh_extra_key'));
    }

    public function testDeleteRefusesAKeyFleetDoesNotManage(): void
    {
        @mkdir($this->paths->sshDir(), 0o700, true);
        file_put_contents($this->paths->sshExtraKey('legacy'), 'x');

        $this->expectException(Refused::class);
        $this->keys->delete('legacy', 'tester');
    }

    public function testCatalogListsHostKeysManagedAndExternalKeysWithUsage(): void
    {
        $keyService = self::getContainer()->get(SshKeyService::class);
        self::assertInstanceOf(SshKeyService::class, $keyService);
        $this->entityManager()->getConnection()->executeStatement("INSERT INTO host_settings (id, domain, tls_mode, created_at, updated_at) SELECT 1, 'fleet.example.com', 'http01', datetime('now'), datetime('now') WHERE NOT EXISTS (SELECT 1 FROM host_settings)");
        $keyService->ensureKeys();
        $this->keys->create('staging', 'tester');
        $external = new Ed25519KeyGenerator()->generate('hand');
        file_put_contents($this->paths->sshExtraKey('legacy'), $external->privatePem());
        chmod($this->paths->sshExtraKey('legacy'), 0o600);
        $this->useInTemplate('staging');

        $catalog = self::getContainer()->get(SshKeyCatalog::class);
        self::assertInstanceOf(SshKeyCatalog::class, $catalog);
        $by = [];
        foreach ($catalog->entries() as $entry) {
            $by[$entry->id] = $entry;
        }

        self::assertSame(['deploy', 'push', 'legacy', 'staging'], array_keys($by));
        self::assertSame('external', $by['legacy']->origin);
        self::assertSame($external->fingerprint, $by['legacy']->fingerprint);
        self::assertFalse($by['legacy']->deletable());
        self::assertSame('generated', $by['staging']->origin);
        self::assertSame(['blog/web'], $by['staging']->usedBy);
        self::assertFalse($by['staging']->deletable(), 'in use');
        self::assertNotNull($by['deploy']->fingerprint);
        self::assertStringNotContainsString('PRIVATE', json_encode(array_map(static fn ($e): array => $e->toArray(), $catalog->entries()), \JSON_THROW_ON_ERROR));
    }

    public function testExtrasOnlyNeedsAtLeastOneExtraKey(): void
    {
        $this->makeProject();
        $templates = self::getContainer()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);

        try {
            $templates->create('blog', new TemplateInput('narrow', sshIdentities: SshIdentities::ExtrasOnly));
            self::fail('extras only without keys');
        } catch (ValidationFailed $failed) {
            self::assertArrayHasKey('sshIdentities', $failed->errors);
        }
    }

    /** The test kernel records commands; importing needs the real ssh-keygen. */
    private function withRealKeygen(): ManagedSshKeys
    {
        $c = self::getContainer();

        return new ManagedSshKeys(
            $this->paths,
            $c->get(\App\Domain\Ssh\ManagedSshKeyRepository::class),
            $c->get(\App\Domain\Host\HostSettingsRepository::class),
            new Ed25519KeyGenerator(),
            new \App\Fleet\Shell\ProcessCommandRunner(),
            $c->get(\App\Domain\Project\ProjectRepository::class),
            $c->get(\App\Domain\Template\TemplateRepository::class),
            $c->get(\App\Fleet\Audit\AuditLog::class),
            new \App\Fleet\Ssh\SshKeysLock($this->paths),
        );
    }

    private function makeProject(): void
    {
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        if (false === $this->entityManager()->getConnection()->fetchOne("SELECT 1 FROM project WHERE name = 'blog'")) {
            $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', containerSsh: true));
        }
    }

    private function useInTemplate(string $key): void
    {
        $this->makeProject();
        $templates = self::getContainer()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('web', sshKeys: [$key], sshIdentities: SshIdentities::ExtrasOnly));
    }

    private function hasSshKeygen(): bool
    {
        return '' !== trim((string) shell_exec('command -v ssh-keygen 2>/dev/null'));
    }
}
