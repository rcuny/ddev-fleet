<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\KnownHostScanner;
use App\Fleet\Ssh\SshPublicKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KnownHostScannerTest extends TestCase
{
    public function testScanUsesArgvWithoutShell(): void
    {
        $runner = new RecordingCommandRunner();

        new KnownHostScanner($runner)->scan('git.example.com');

        $calls = $runner->calls();
        self::assertCount(1, $calls);
        self::assertSame(['ssh-keyscan', '-T', '5', '-t', 'ed25519,ecdsa,rsa', '--', 'git.example.com'], $calls[0]->argv);
    }

    public function testScanUsesThePortOfABracketedHost(): void
    {
        $runner = new RecordingCommandRunner();

        new KnownHostScanner($runner)->scan('[git.example.com]:2222');

        self::assertSame(['ssh-keyscan', '-T', '5', '-t', 'ed25519,ecdsa,rsa', '-p', '2222', '--', 'git.example.com'], $runner->calls()[0]->argv);
    }

    public function testScanParsesAndSkipsComments(): void
    {
        $key = SshPublicKeys::parseLine(new Ed25519KeyGenerator()->generate('c')->publicLine)['key'];
        $runner = new RecordingCommandRunner()->expect(['ssh-keyscan'], new CommandResult(0, <<<OUT
            # git.example.com:22 SSH-2.0-OpenSSH_9.2
            git.example.com ssh-ed25519 $key

            # git.example.com:22 SSH-2.0-OpenSSH_9.2
            other.example.com ssh-ed25519 $key
            git.example.com ssh-dss $key
            git.example.com ssh-ed25519 not-base64!
            OUT));

        $entries = new KnownHostScanner($runner)->scan('Git.Example.com');

        self::assertCount(1, $entries, 'comments, other hosts, bad types and bad keys are dropped');
        self::assertSame('git.example.com ssh-ed25519 '.$key, $entries[0]->line());
        self::assertSame(SshPublicKeys::fingerprint('ssh-ed25519', $key), $entries[0]->fingerprint());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHosts(): iterable
    {
        yield 'dash' => ['-oProxyCommand=touch /tmp/x'];
        yield 'dash host' => ['-evil.example.com'];
        yield 'space' => ['a b'];
        yield 'newline' => ["a.example.com\nb"];
        yield 'empty' => [''];
        yield 'semicolon' => ['a.example.com;id'];
        yield 'bad port' => ['[a.example.com]:99999'];
    }

    #[DataProvider('badHosts')]
    public function testScanRejectsHostStartingWithDash(string $host): void
    {
        $runner = new RecordingCommandRunner();

        try {
            new KnownHostScanner($runner)->scan($host);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $runner->calls(), 'nothing may run for an invalid host');
        }
    }

    public function testScanFailureReturnsEmptyList(): void
    {
        $runner = new RecordingCommandRunner()->expect(['ssh-keyscan'], new CommandResult(1, '', 'boom'));
        self::assertSame([], new KnownHostScanner($runner)->scan('git.example.com'));

        $timeout = new class implements \App\Fleet\Shell\CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandTimedOut('timed out');
            }
        };
        self::assertSame([], new KnownHostScanner($timeout)->scan('git.example.com'));
    }
}
