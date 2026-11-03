<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Export;

use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\PassphraseSealer;
use PHPUnit\Framework\TestCase;

final class PassphraseSealerTest extends TestCase
{
    private function sealer(): PassphraseSealer
    {
        // The cheapest parameters the parser accepts: the cost is not what is under test.
        return new PassphraseSealer(PassphraseSealer::MIN_OPS, PassphraseSealer::MIN_MEM);
    }

    public function testASealedPayloadOpensWithTheSamePassphrase(): void
    {
        $payload = ['project' => 'blog', 'secrets' => ['TOKEN' => "line one\nline two ünïcode"]];
        $sealed = $this->sealer()->seal($payload, 'a long passphrase');

        self::assertSame(['kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext'], array_keys($sealed));
        self::assertSame('argon2id13', $sealed['kdf']);
        self::assertSame($payload, $this->sealer()->open($sealed, 'a long passphrase'));
    }

    public function testTheParametersTravelWithTheFile(): void
    {
        $sealed = $this->sealer()->seal(['a' => 'b'], 'a long passphrase');
        // A host with other defaults opens it: it reads the cost from the file.
        $other = new PassphraseSealer(PassphraseSealer::MAX_OPS, PassphraseSealer::MIN_MEM * 2);

        self::assertSame(['a' => 'b'], $other->open($sealed, 'a long passphrase'));
    }

    public function testEverySealingUsesAFreshSaltAndNonce(): void
    {
        $one = $this->sealer()->seal(['a' => 'b'], 'a long passphrase');
        $two = $this->sealer()->seal(['a' => 'b'], 'a long passphrase');

        self::assertNotSame($one['salt'], $two['salt']);
        self::assertNotSame($one['nonce'], $two['nonce']);
        self::assertNotSame($one['ciphertext'], $two['ciphertext']);
    }

    public function testAWrongPassphraseAndADamagedBlockGiveTheSameFixedMessage(): void
    {
        $sealed = $this->sealer()->seal(['a' => 'b'], 'a long passphrase');
        $damaged = $sealed;
        $bytes = base64_decode($damaged['ciphertext'], true);
        self::assertIsString($bytes);
        $bytes = substr_replace($bytes, \chr(\ord($bytes[0]) ^ 1), 0, 1);
        $damaged['ciphertext'] = base64_encode($bytes);

        $messages = [];
        foreach ([[$sealed, 'another passphrase'], [$damaged, 'a long passphrase']] as [$block, $passphrase]) {
            try {
                $this->sealer()->open($block, $passphrase);
                self::fail('Must not open.');
            } catch (InvalidExport $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
        self::assertStringNotContainsString('another passphrase', $messages[0]);
    }

    public function testAMalformedBlockIsRefusedWithoutDerivingAKey(): void
    {
        $sealed = $this->sealer()->seal(['a' => 'b'], 'a long passphrase');
        $sealed['salt'] = base64_encode('short');

        $this->expectException(InvalidExport::class);
        $this->sealer()->open($sealed, 'a long passphrase');
    }

    public function testThePassphraseHasLimits(): void
    {
        self::assertNotNull(PassphraseSealer::passphraseError('short'));
        self::assertNotNull(PassphraseSealer::passphraseError(str_repeat('x', 1025)));
        self::assertNull(PassphraseSealer::passphraseError('eight ok'));
        $this->expectException(InvalidExport::class);
        $this->sealer()->seal([], 'short');
    }
}
