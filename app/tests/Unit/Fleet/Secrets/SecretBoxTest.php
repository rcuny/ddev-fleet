<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Secrets;

use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SealedSecret;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretOpenFailed;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    /** @var list<string> */
    private array $homes = [];

    protected function tearDown(): void
    {
        foreach ($this->homes as $home) {
            foreach (glob($home.'/keys/{,.}*', \GLOB_BRACE) ?: [] as $file) {
                is_file($file) ? @unlink($file) : null;
            }
            @rmdir($home.'/keys');
            @rmdir($home);
        }
    }

    public function testRoundTrip(): void
    {
        $box = $this->box();

        $sealed = $box->seal('s3cret-value');

        self::assertSame('s3cret-value', $box->open($sealed));
        self::assertSame(1, $sealed->keyVersion);
        self::assertStringNotContainsString('s3cret-value', $sealed->ciphertext);
    }

    public function testRoundTripOfEmptyAndBinaryPlaintext(): void
    {
        $box = $this->box();
        $binary = random_bytes(300)."\0end";

        self::assertSame('', $box->open($box->seal('')));
        self::assertSame($binary, $box->open($box->seal($binary)));
    }

    public function testNonceIsFreshPerSeal(): void
    {
        $box = $this->box();

        $nonces = [];
        for ($i = 0; $i < 20; ++$i) {
            $sealed = $box->seal('same plaintext');
            self::assertSame(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, \strlen($sealed->nonce));
            $nonces[] = $sealed->nonce;
        }

        self::assertCount(20, array_unique($nonces));
        self::assertNotSame($box->seal('same plaintext')->ciphertext, $box->seal('same plaintext')->ciphertext);
    }

    public function testTamperedCiphertextFails(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value');
        $flipped = $sealed->ciphertext;
        $flipped = substr_replace($flipped, \chr(\ord($flipped[0]) ^ 1), 0, 1);

        try {
            $box->open(new SealedSecret($flipped, $sealed->nonce, $sealed->keyVersion));
            self::fail('Tampered ciphertext must not open.');
        } catch (SecretOpenFailed $e) {
            self::assertStringNotContainsString('s3cret-value', $e->getMessage());
        }
    }

    public function testTamperedNonceAndTruncatedInputsFail(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value');

        foreach ([
            new SealedSecret($sealed->ciphertext, random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), 1),
            new SealedSecret($sealed->ciphertext, substr($sealed->nonce, 1), 1),
            new SealedSecret($sealed->ciphertext, $sealed->nonce.'x', 1),
            new SealedSecret($sealed->ciphertext, '', 1),
            new SealedSecret(substr($sealed->ciphertext, 0, -1), $sealed->nonce, 1),
            new SealedSecret('short', $sealed->nonce, 1),
            new SealedSecret('', $sealed->nonce, 1),
        ] as $broken) {
            try {
                $box->open($broken);
                self::fail('A malformed or altered sealed secret must not open.');
            } catch (SecretOpenFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWrongKeyFails(): void
    {
        $sealed = $this->box()->seal('s3cret-value');

        $other = $this->box();
        $other->seal('force the second key into existence');

        $this->expectException(SecretOpenFailed::class);
        $other->open($sealed);
    }

    public function testUnknownKeyVersionFails(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value');

        foreach ([0, 2, -1] as $version) {
            try {
                $box->open(new SealedSecret($sealed->ciphertext, $sealed->nonce, $version));
                self::fail('Key version '.$version.' must be rejected.');
            } catch (SecretOpenFailed $e) {
                self::assertStringContainsString((string) $version, $e->getMessage());
            }
        }
    }

    public function testOpenWithoutAKeyFileFailsAndDoesNotCreateOne(): void
    {
        $sealed = $this->box()->seal('s3cret-value');
        $paths = $this->newPaths();
        $box = new SecretBox(new SecretKeyFile($paths));

        try {
            $box->open($sealed);
            self::fail('Opening without a key must fail.');
        } catch (SecretOpenFailed) {
            self::assertFileDoesNotExist($paths->secretKey());
        }
    }

    public function testSealCreatesTheKeyOnFirstUse(): void
    {
        $paths = $this->newPaths();
        $box = new SecretBox(new SecretKeyFile($paths));
        self::assertFileDoesNotExist($paths->secretKey());

        $box->seal('x');

        self::assertSame(0o600, fileperms($paths->secretKey()) & 0o777);
    }

    private function box(): SecretBox
    {
        return new SecretBox(new SecretKeyFile($this->newPaths()));
    }

    private function newPaths(): FleetPaths
    {
        $home = sys_get_temp_dir().'/fleet-secretbox-'.bin2hex(random_bytes(6));
        mkdir($home, 0o700);
        $this->homes[] = $home;

        return new FleetPaths($home, $home.'/caddy');
    }
}
