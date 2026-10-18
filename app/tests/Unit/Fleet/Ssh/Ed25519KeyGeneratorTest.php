<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\SshPublicKeys;
use PHPUnit\Framework\TestCase;

final class Ed25519KeyGeneratorTest extends TestCase
{
    public function testPrivateKeyIsAcceptedBySshKeygen(): void
    {
        $runner = new ProcessCommandRunner();
        if (127 === $runner->run(['ssh-keygen', '-?'])->exitCode) {
            self::markTestSkipped('ssh-keygen is not installed.');
        }

        $key = new Ed25519KeyGenerator()->generate('fleet-deploy@fleet.example.com');
        $dir = sys_get_temp_dir().'/fleet-keygen-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $file = $dir.'/key';
        try {
            file_put_contents($file, $key->privatePem);
            chmod($file, 0o600);

            $public = $runner->run(['ssh-keygen', '-y', '-P', '', '-f', $file]);
            self::assertSame(0, $public->exitCode, $public->stderr);
            // ssh-keygen -y prints "<type> <base64>" (newer versions add the comment): compare the key itself.
            self::assertSame(
                explode(' ', $key->publicLine)[1],
                explode(' ', trim($public->stdout))[1],
            );

            file_put_contents($file.'.pub', $key->publicLine."\n");
            $fingerprint = $runner->run(['ssh-keygen', '-l', '-f', $file.'.pub']);
            self::assertStringContainsString($key->fingerprint, $fingerprint->stdout);
        } finally {
            @unlink($file);
            @unlink($file.'.pub');
            @rmdir($dir);
        }
    }

    /**
     * Pure-PHP check of the openssh-key-v1 container, so the format is verified where ssh-keygen is not installed.
     */
    public function testPrivateKeyIsAWellFormedOpensshContainer(): void
    {
        $key = new Ed25519KeyGenerator()->generate('c');

        self::assertStringStartsWith("-----BEGIN OPENSSH PRIVATE KEY-----\n", $key->privatePem);
        self::assertStringEndsWith("-----END OPENSSH PRIVATE KEY-----\n", $key->privatePem);
        $body = trim(str_replace(['-----BEGIN OPENSSH PRIVATE KEY-----', '-----END OPENSSH PRIVATE KEY-----'], '', $key->privatePem));
        foreach (explode("\n", $body) as $line) {
            self::assertLessThanOrEqual(70, \strlen($line));
        }
        $raw = base64_decode(str_replace("\n", '', $body), true);
        self::assertIsString($raw);

        $offset = 0;
        self::assertSame("openssh-key-v1\0", substr($raw, 0, 15));
        $offset = 15;
        $read = static function () use ($raw, &$offset): string {
            $length = unpack('N', substr($raw, $offset, 4));
            self::assertIsArray($length);
            $value = substr($raw, $offset + 4, $length[1]);
            self::assertSame($length[1], \strlen($value), 'truncated field');
            $offset += 4 + $length[1];

            return $value;
        };
        self::assertSame('none', $read());
        self::assertSame('none', $read());
        self::assertSame('', $read());
        $count = unpack('N', substr($raw, $offset, 4));
        self::assertIsArray($count);
        self::assertSame(1, $count[1]);
        $offset += 4;
        $publicBlob = $read();
        $private = $read();
        self::assertSame(\strlen($raw), $offset, 'no trailing bytes');

        self::assertSame(base64_encode($publicBlob), explode(' ', $key->publicLine)[1]);
        self::assertSame(0, \strlen($private) % 8);
        self::assertSame(substr($private, 0, 4), substr($private, 4, 4), 'check integers must match');

        $offset = 8;
        $inner = static function () use ($private, &$offset): string {
            $length = unpack('N', substr($private, $offset, 4));
            self::assertIsArray($length);
            $value = substr($private, $offset + 4, $length[1]);
            $offset += 4 + $length[1];

            return $value;
        };
        self::assertSame('ssh-ed25519', $inner());
        $public = $inner();
        $secret = $inner();
        self::assertSame('c', $inner());
        self::assertSame(32, \strlen($public));
        self::assertSame(64, \strlen($secret));
        self::assertSame($public, substr($secret, 32), 'the 64-byte secret is seed || public key');
        $padding = substr($private, $offset);
        self::assertLessThan(8, \strlen($padding));
        self::assertSame('' === $padding ? [] : range(1, \strlen($padding)), '' === $padding ? [] : array_map('ord', str_split($padding)));
    }

    public function testPublicLineAndFingerprintFormat(): void
    {
        $key = new Ed25519KeyGenerator()->generate('fleet-push@fleet.example.com');

        self::assertMatchesRegularExpression('/^ssh-ed25519 [A-Za-z0-9+\/]{68} fleet-push@fleet\.example\.com$/D', $key->publicLine);
        self::assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $key->fingerprint);
        $parts = SshPublicKeys::parseLine($key->publicLine);
        self::assertSame($key->fingerprint, SshPublicKeys::fingerprint($parts['type'], $parts['key']));
    }

    public function testTwoKeysDiffer(): void
    {
        $generator = new Ed25519KeyGenerator();
        $a = $generator->generate('x');
        $b = $generator->generate('x');

        self::assertNotSame($a->fingerprint, $b->fingerprint);
        self::assertNotSame($a->privatePem, $b->privatePem);
    }

    public function testCommentWithNewlineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ed25519KeyGenerator()->generate("evil\nssh-rsa AAAA");
    }

    public function testThePrivateKeyDoesNotLeakThroughDumpsOrSerialisation(): void
    {
        $key = new Ed25519KeyGenerator()->generate('c');

        ob_start();
        var_dump($key);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('PRIVATE KEY', $dump);
        self::assertStringNotContainsString(substr($key->privatePem, 40, 30), $dump);

        $this->expectException(\LogicException::class);
        serialize($key);
    }
}
