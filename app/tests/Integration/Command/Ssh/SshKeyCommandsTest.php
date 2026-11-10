<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Ssh\SshKeyService;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SshKeyCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->cli('fleet:host:init', ['--domain' => 'fleet.example.com']);
        $keys = self::getContainer()->get(SshKeyService::class);
        self::assertInstanceOf(SshKeyService::class, $keys);
        $keys->ensureKeys();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $output): array
    {
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testListShowsTheHostKeysWithPurposeFingerprintAndPublicKey(): void
    {
        $list = $this->cli('fleet:ssh:key:list', ['--json' => true]);

        self::assertSame(0, $list->getStatusCode(), $list->getErrorOutput());
        $keys = $this->decoded($list->getDisplay())['keys'];
        self::assertSame(['deploy', 'push'], array_column($keys, 'name'));
        foreach ($keys as $key) {
            self::assertSame('host', $key['origin']);
            self::assertStringStartsWith('SHA256:', $key['fingerprint']);
            self::assertStringStartsWith('ssh-ed25519 ', $key['public_key']);
            self::assertNotSame('', $key['purpose']);
            self::assertNull($key['problem']);
        }
        self::assertStringNotContainsString('PRIVATE KEY', $list->getDisplay());

        $table = $this->cli('fleet:ssh:key:list');
        self::assertStringContainsString('deploy', $table->getDisplay());
        self::assertStringContainsString('push public key: ssh-ed25519 ', $table->getDisplay());
    }

    public function testCreateListDeleteRoundTripWithTheUsageGuard(): void
    {
        $created = $this->cli('fleet:ssh:key:create', ['name' => 'staging', '--json' => true]);
        self::assertSame(0, $created->getStatusCode(), $created->getErrorOutput());
        $object = $this->decoded($created->getDisplay());
        self::assertSame('staging', $object['created']);
        self::assertStringStartsWith('ssh-ed25519 ', $object['public_key']);
        self::assertStringNotContainsString('PRIVATE', $created->getDisplay());

        $entry = array_values(array_filter($this->decoded($this->cli('fleet:ssh:key:list', ['--json' => true])->getDisplay())['keys'], static fn (array $k): bool => 'staging' === $k['name']))[0];
        self::assertSame('generated', $entry['origin']);
        self::assertSame($object['fingerprint'], $entry['fingerprint']);

        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', containerSsh: true));
        $template = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'web', '--ssh-key' => ['staging'], '--json' => true]);
        self::assertSame(0, $template->getStatusCode(), $template->getErrorOutput());

        $refused = $this->cli('fleet:ssh:key:delete', ['name' => 'staging', '--yes' => true, '--json' => true]);
        self::assertSame(2, $refused->getStatusCode());
        self::assertStringContainsString('blog/web', $refused->getDisplay().$refused->getErrorOutput());

        $this->cli('fleet:template:update', ['project' => 'blog', 'name' => 'web', '--unset' => ['ssh-keys']]);
        $deleted = $this->cli('fleet:ssh:key:delete', ['name' => 'staging', '--yes' => true, '--json' => true]);
        self::assertSame(0, $deleted->getStatusCode(), $deleted->getErrorOutput());
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::assertFileDoesNotExist($paths->sshExtraKey('staging'));
    }

    public function testCreateRefusesTheReservedNamesAndDeleteNeedsConfirmation(): void
    {
        $reserved = $this->cli('fleet:ssh:key:create', ['name' => 'push', '--json' => true]);
        self::assertSame(1, $reserved->getStatusCode());
        self::assertStringContainsString('name', $reserved->getDisplay().$reserved->getErrorOutput());

        $this->cli('fleet:ssh:key:create', ['name' => 'staging']);
        $unconfirmed = $this->cli('fleet:ssh:key:delete', ['name' => 'staging']);
        self::assertNotSame(0, $unconfirmed->getStatusCode(), 'no --yes and no terminal');
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::assertFileExists($paths->sshExtraKey('staging'));
    }

    public function testImportRefusesWhatIsNotAnUnencryptedPrivateKeyWithoutEchoingIt(): void
    {
        $tester = $this->cli('fleet:ssh:key:import', ['name' => 'vendor', '--json' => true], ["this is not a key SECRET-MARKER\n"]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('SECRET-MARKER', $tester->getDisplay().$tester->getErrorOutput());
    }

    public function testTemplateSshIdentitiesOptionAndTheSharedAgentWarning(): void
    {
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git', containerSsh: true));
        $this->cli('fleet:ssh:key:create', ['name' => 'staging']);

        $bad = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'narrow', '--ssh-identities' => 'extras-only', '--json' => true]);
        self::assertSame(1, $bad->getStatusCode());
        self::assertStringContainsString('ssh-identities', $bad->getDisplay().$bad->getErrorOutput());

        $wrong = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'narrow', '--ssh-identities' => 'nonsense', '--json' => true]);
        self::assertSame(1, $wrong->getStatusCode());

        $ok = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'narrow', '--ssh-key' => ['staging'], '--ssh-identities' => 'extras-only', '--post-deploy' => ['ddev auth ssh -f x'], '--json' => true]);
        self::assertSame(0, $ok->getStatusCode(), $ok->getErrorOutput());
        $object = $this->decoded($ok->getDisplay());
        self::assertSame('extras_only', $object['ssh_identities']);
        self::assertStringContainsString('shared ssh-agent', $object['warning']);

        $plain = $this->cli('fleet:template:create', ['project' => 'blog', 'name' => 'plain', '--json' => true]);
        self::assertSame('push_and_extras', $this->decoded($plain->getDisplay())['ssh_identities']);
        self::assertArrayNotHasKey('warning', $this->decoded($plain->getDisplay()));
    }
}
