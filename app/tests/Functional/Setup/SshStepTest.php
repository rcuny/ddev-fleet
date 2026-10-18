<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Ssh\KnownHostEntry;
use App\Tests\Support\WizardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SshStepTest extends WizardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function keyscan(string $host, string $key): void
    {
        $this->runner()->expect(['ssh-keyscan'], new CommandResult(0, $host.' ssh-ed25519 '.$key."\n"));
    }

    private function knownHostsContents(): string
    {
        return (string) @file_get_contents($this->paths()->knownHostsFile());
    }

    public function testGetGeneratesBothKeysAndShowsPublicLinesWithCopyButtons(): void
    {
        $crawler = $this->open('/setup/ssh');

        self::assertResponseStatusCodeSame(200);
        foreach (SshKeyRole::cases() as $role) {
            $field = $crawler->filter('#key-'.$role->value);
            self::assertCount(1, $field, $role->value);
            self::assertStringStartsWith('ssh-ed25519 ', (string) $field->attr('value'));
            self::assertNotNull($field->attr('readonly'));
            self::assertSame('clipboard#copy', $crawler->filter('[data-testid=copy-'.$role->value.']')->attr('data-action'));
            self::assertSame('clipboard', $crawler->filter('[data-testid=setup-key-'.$role->value.']')->attr('data-controller'));
            self::assertFileExists($this->paths()->sshPrivateKey($role));
            self::assertSame($this->keys()->findByRole($role)?->publicKey(), $field->attr('value'));
        }
        self::assertStringContainsString('SHA256:', $this->body());
        self::assertStringNotContainsString('PRIVATE KEY', $this->body());
    }

    public function testTheSeededProvidersAreListed(): void
    {
        $crawler = $this->open('/setup/ssh');

        $hosts = $crawler->filter('[data-testid=setup-known-hosts] tbody td:first-child')->extract(['_text']);
        self::assertContains('github.com', array_map('trim', $hosts));
        self::assertContains('bitbucket.org', array_map('trim', $hosts));
    }

    public function testScanListsFingerprintsAndAddsNothing(): void
    {
        $key = self::randomEd25519();
        $this->keyscan('git.example.com', $key);
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => 'Git.Example.com']);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(new KnownHostEntry('git.example.com', 'ssh-ed25519', $key)->fingerprint(), $this->body());
        self::assertStringContainsString('Nothing is added until you do', $this->body());
        self::assertSame($before, $this->knownHostsContents());
        self::assertSame(1, \count($this->runner()->callsMatching(['ssh-keyscan'])));
        self::assertStringNotContainsString($key, $this->body(), 'only the fingerprint is shown');
    }

    public function testTrustAddsTheScannedLineOnly(): void
    {
        $key = self::randomEd25519();
        $fingerprint = new KnownHostEntry('git.example.com', 'ssh-ed25519', $key)->fingerprint();
        $this->keyscan('git.example.com', $key);
        $this->keyscan('git.example.com', $key);
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => 'git.example.com']);
        // Whatever else the browser sends is ignored: the line comes from the server's own second scan.
        $this->post('/setup/ssh', [
            'action' => 'trust',
            'host' => 'git.example.com',
            'fingerprints' => [$fingerprint],
            'line' => 'git.example.com ssh-ed25519 '.self::randomEd25519(),
            'key' => self::randomEd25519(),
        ]);

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame($before."git.example.com ssh-ed25519 {$key}\n", $this->knownHostsContents());
        self::assertSame(2, \count($this->runner()->callsMatching(['ssh-keyscan'])), 'trust scans again');
    }

    public function testTrustRefusesKeysThatChangedSinceTheScan(): void
    {
        $this->keyscan('git.example.com', self::randomEd25519());
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->post('/setup/ssh', [
            'action' => 'trust',
            'host' => 'git.example.com',
            'fingerprints' => ['SHA256:'.str_repeat('A', 43)],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not the ones you confirmed', $this->body());
        self::assertSame($before, $this->knownHostsContents());
    }

    public function testTrustWithoutAnyFingerprintAddsNothing(): void
    {
        $this->keyscan('git.example.com', self::randomEd25519());
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->post('/setup/ssh', ['action' => 'trust', 'host' => 'git.example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->knownHostsContents());
    }

    public function testASeededProviderNeverGetsAnotherKey(): void
    {
        $key = self::randomEd25519();
        $this->keyscan('github.com', $key);
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => 'github.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already trusted', $this->body());
        self::assertSame($before, $this->knownHostsContents());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback range' => ['127.8.8.8'];
        yield 'link-local metadata' => ['169.254.169.254'];
        yield 'this host' => ['0.0.0.0'];
        yield 'decimal loopback' => ['2130706433'];
        yield 'short loopback' => ['127.1'];
        yield 'hex loopback' => ['0x7f.0.0.1'];
        yield 'octal loopback' => ['0177.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'sub of localhost' => ['git.localhost'];
        yield 'bracketed loopback with port' => ['[127.0.0.1]:2222'];
        yield 'not a host' => ['-oProxyCommand=evil'];
        yield 'with a space' => ['a b'];
    }

    #[DataProvider('refusedTargets')]
    public function testLiteralLoopbackAndLinkLocalTargetsAreRefusedForScanAndTrust(string $host): void
    {
        $this->open('/setup/ssh');

        foreach (['scan', 'trust'] as $action) {
            $this->post('/setup/ssh', ['action' => $action, 'host' => $host, 'fingerprints' => ['SHA256:x']]);
            self::assertResponseStatusCodeSame(422, $action);
        }
        self::assertSame([], $this->runner()->callsMatching(['ssh-keyscan']), 'nothing was scanned');
    }

    public function testAPublicIpAddressMayBeScanned(): void
    {
        $this->keyscan('203.0.113.7', self::randomEd25519());
        $this->open('/setup/ssh');

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => '203.0.113.7']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, \count($this->runner()->callsMatching(['ssh-keyscan'])));
    }

    public function testAMissingScannerIsAMessageNotAnException(): void
    {
        $this->runner()->expect(['ssh-keyscan'], new CommandResult(127, '', 'ssh-keyscan: not found'));
        $this->open('/setup/ssh');

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => 'git.example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ssh-keyscan is not available', $this->body());
    }

    public function testAHostThatOffersNoKeyIsAMessage(): void
    {
        $this->runner()->expect(['ssh-keyscan'], new CommandResult(0, ''));
        $this->open('/setup/ssh');

        $this->post('/setup/ssh', ['action' => 'scan', 'host' => 'git.example.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('No SSH host key could be read', $this->body());
    }

    public function testRegenerateNeedsConfirm(): void
    {
        $this->open('/setup/ssh');
        $before = $this->keys()->findByRole(SshKeyRole::Deploy)?->fingerprint();

        $this->post('/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Tick the box to confirm', $this->body());
        self::assertSame($before, $this->keys()->findByRole(SshKeyRole::Deploy)?->fingerprint());

        $this->post('/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy', 'confirm' => '1']);
        self::assertResponseRedirects('/setup/ssh');
        self::assertNotSame($before, $this->keys()->findByRole(SshKeyRole::Deploy)?->fingerprint());
    }

    public function testRegenerateReplacesOnlyTheChosenKey(): void
    {
        $this->open('/setup/ssh');
        $push = $this->keys()->findByRole(SshKeyRole::Push)?->fingerprint();

        $this->post('/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy', 'confirm' => '1']);

        self::assertSame($push, $this->keys()->findByRole(SshKeyRole::Push)?->fingerprint());
    }

    public function testImportRejectsGarbage(): void
    {
        $this->open('/setup/ssh');
        $before = $this->keys()->findByRole(SshKeyRole::Push)?->fingerprint();
        $path = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($path, "this is not a key\nsecret-looking-text");

        $this->post('/setup/ssh', ['action' => 'import', 'role' => 'push', 'confirm' => '1'], ['private_key' => new UploadedFile($path, 'id_ed25519', null, null, true)]);
        @unlink($path);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not an unencrypted SSH private key', $this->body());
        self::assertStringNotContainsString('secret-looking-text', $this->body());
        self::assertSame($before, $this->keys()->findByRole(SshKeyRole::Push)?->fingerprint());
    }

    public function testImportNeedsAFileAndConfirm(): void
    {
        $this->open('/setup/ssh');

        $this->post('/setup/ssh', ['action' => 'import', 'role' => 'push']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Tick the box to confirm', $this->body());

        $this->post('/setup/ssh', ['action' => 'import', 'role' => 'push', 'confirm' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose the private key file', $this->body());
    }

    public function testEveryActionNeedsTheFormToken(): void
    {
        $this->open('/setup/ssh');
        $before = $this->knownHostsContents();

        $this->client->request('POST', self::BASE.'/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy', 'confirm' => '1'], server: $this->browserServer());
        self::assertResponseStatusCodeSame(403, 'no token field');

        $this->post('/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy', 'confirm' => '1'], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403, 'another site');

        $this->post('/setup/ssh', ['action' => 'regenerate', 'role' => 'deploy', 'confirm' => '1'], fetchSite: 'same-site');
        self::assertResponseStatusCodeSame(403, 'a sibling host');
        self::assertSame([], $this->runner()->callsMatching(['ssh-keyscan']));
        self::assertSame($before, $this->knownHostsContents());
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->open('/setup/ssh');
        $this->post('/setup/ssh', ['action' => 'delete-everything']);

        self::assertResponseStatusCodeSame(422);
    }
}
