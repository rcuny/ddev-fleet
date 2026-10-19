<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\SshPublicKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshPublicKeysTest extends TestCase
{
    public function testValidKeyReturnsItsBlob(): void
    {
        $parts = SshPublicKeys::parseLine(new Ed25519KeyGenerator()->generate('c')->publicLine);

        self::assertSame($parts['key'], base64_encode(SshPublicKeys::blob($parts['type'], $parts['key'])));
        self::assertSame('c', $parts['comment']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidKeys(): iterable
    {
        $key = SshPublicKeys::parseLine(new Ed25519KeyGenerator()->generate('c')->publicLine)['key'];

        yield 'type not allowed' => ['ssh-dss', $key];
        yield 'type option-like' => ['-oProxyCommand=x', $key];
        yield 'blob type mismatch' => ['ssh-rsa', $key];
        yield 'newline' => ['ssh-ed25519', $key."\nssh-rsa AAAA"];
        yield 'space' => ['ssh-ed25519', $key.' extra'];
        yield 'not base64' => ['ssh-ed25519', '!!!!'];
        yield 'empty' => ['ssh-ed25519', ''];
        yield 'non canonical padding' => ['ssh-ed25519', rtrim($key, '=').'A=='];
        yield 'too long' => ['ssh-ed25519', str_repeat('A', 9000)];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejected(string $type, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SshPublicKeys::blob($type, $key);
    }

    public function testParseLineRejectsControlCharactersInTheComment(): void
    {
        $line = new Ed25519KeyGenerator()->generate('c')->publicLine;

        $this->expectException(\InvalidArgumentException::class);

        SshPublicKeys::parseLine(substr($line, 0, -1)."\x07");
    }
}
