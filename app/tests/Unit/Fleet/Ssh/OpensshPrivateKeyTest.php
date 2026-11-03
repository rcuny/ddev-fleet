<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\OpensshPrivateKey;
use PHPUnit\Framework\TestCase;

final class OpensshPrivateKeyTest extends TestCase
{
    public function testReadsThePublicKeyOfAGeneratedKey(): void
    {
        $key = new Ed25519KeyGenerator()->generate('c');
        $parts = explode(' ', $key->publicLine);

        self::assertSame(['type' => 'ssh-ed25519', 'key' => $parts[1]], OpensshPrivateKey::publicKey($key->privatePem()));
        self::assertSame(['type' => 'ssh-ed25519', 'key' => $parts[1]], OpensshPrivateKey::publicKey(str_replace("\n", "\r\n", $key->privatePem())));
    }

    public function testOtherOrBrokenInputGivesNull(): void
    {
        $pem = new Ed25519KeyGenerator()->generate('c')->privatePem();
        $raw = (string) base64_decode(str_replace(['-----BEGIN OPENSSH PRIVATE KEY-----', '-----END OPENSSH PRIVATE KEY-----', "\n"], '', $pem), true);
        $armor = static fn (string $bytes): string => "-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($bytes), 70, "\n")."-----END OPENSSH PRIVATE KEY-----\n";

        $encrypted = str_replace(pack('N', 4).'none', pack('N', 6).'aes256', $raw);
        self::assertNull(OpensshPrivateKey::publicKey($armor(substr($encrypted, 0, 40))), 'cipher other than none');
        self::assertNull(OpensshPrivateKey::publicKey($armor(substr($raw, 0, 30))), 'truncated');
        self::assertNull(OpensshPrivateKey::publicKey($armor('not a key')));
        self::assertNull(OpensshPrivateKey::publicKey("-----BEGIN RSA PRIVATE KEY-----\nQUJD\n-----END RSA PRIVATE KEY-----\n"));
        self::assertNull(OpensshPrivateKey::publicKey(''));
    }
}
