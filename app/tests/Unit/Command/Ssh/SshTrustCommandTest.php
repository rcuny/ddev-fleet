<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Ssh;

use App\Command\Ssh\SshTrustCommand;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\KnownHostScanner;
use App\Fleet\Ssh\KnownHostsFile;
use App\Fleet\Ssh\SshPublicKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SshTrustCommandTest extends TestCase
{
    private string $home;
    private FleetPaths $paths;
    private KnownHostsFile $file;
    private string $key;
    private string $fingerprint;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle190-trust-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->file = new KnownHostsFile($this->paths, \dirname(__DIR__, 4).'/resources/ssh/known_hosts.seed');
        $this->key = SshPublicKeys::parseLine(new Ed25519KeyGenerator()->generate('c')->publicLine)['key'];
        $this->fingerprint = SshPublicKeys::fingerprint('ssh-ed25519', $this->key);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $answers
     */
    private function trust(array $input, bool $interactive = false, array $answers = []): CommandTester
    {
        $runner = new RecordingCommandRunner()->expect(['ssh-keyscan'], new CommandResult(0, "ci.example.com ssh-ed25519 {$this->key}\n"));
        $tester = new CommandTester(new SshTrustCommand(new KnownHostScanner($runner), $this->file));
        $tester->setInputs($answers);
        $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => $interactive]);

        return $tester;
    }

    public function testAFingerprintYouGaveTrustsThatKeyAndNothingElse(): void
    {
        $tester = $this->trust(['host' => 'ci.example.com', '--fingerprint' => [$this->fingerprint], '--json' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertTrue($this->file->has('ci.example.com'));
        $document = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([['type' => 'ssh-ed25519', 'fingerprint' => $this->fingerprint]], $document['trusted']);
    }

    public function testAWrongFingerprintTrustsNothing(): void
    {
        $tester = $this->trust(['host' => 'ci.example.com', '--fingerprint' => ['SHA256:nope']]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertFalse($this->file->has('ci.example.com'));
    }

    public function testWithoutAFingerprintAScriptIsRefusedAndTheOfferedOnesAreListed(): void
    {
        $tester = $this->trust(['host' => 'ci.example.com']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString($this->fingerprint, $tester->getErrorOutput());
        self::assertFalse($this->file->has('ci.example.com'));
    }

    public function testAPersonConfirmsAtThePrompt(): void
    {
        $no = $this->trust(['host' => 'ci.example.com'], true, ['no']);
        self::assertSame(2, $no->getStatusCode());
        self::assertFalse($this->file->has('ci.example.com'));

        $yes = $this->trust(['host' => 'ci.example.com'], true, ['yes']);
        self::assertSame(0, $yes->getStatusCode(), $yes->getErrorOutput());
        self::assertStringContainsString($this->fingerprint, $yes->getDisplay());
        self::assertTrue($this->file->has('ci.example.com'));
    }

    public function testABadHostIsAFieldError(): void
    {
        $tester = $this->trust(['host' => '-oProxyCommand=x', '--fingerprint' => [$this->fingerprint]]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('<host>:', $tester->getErrorOutput());
    }
}
