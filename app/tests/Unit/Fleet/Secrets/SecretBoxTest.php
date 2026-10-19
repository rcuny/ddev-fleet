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
    private const string CONTEXT = 'ctx-a';

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

        $sealed = $box->seal('s3cret-value', self::CONTEXT);

        self::assertSame('s3cret-value', $box->open($sealed, self::CONTEXT));
        self::assertSame(2, $sealed->keyVersion);
        self::assertStringNotContainsString('s3cret-value', $sealed->ciphertext);
    }

    public function testRoundTripOfEmptyAndBinaryPlaintext(): void
    {
        $box = $this->box();
        $binary = random_bytes(300)."\0end";

        self::assertSame('', $box->open($box->seal('', self::CONTEXT), self::CONTEXT));
        self::assertSame($binary, $box->open($box->seal($binary, self::CONTEXT), self::CONTEXT));
    }

    public function testNonceIsFreshPerSeal(): void
    {
        $box = $this->box();

        $nonces = [];
        for ($i = 0; $i < 20; ++$i) {
            $sealed = $box->seal('same plaintext', self::CONTEXT);
            self::assertSame(SecretBox::NONCE_BYTES, \strlen($sealed->nonce));
            $nonces[] = $sealed->nonce;
        }

        self::assertCount(20, array_unique($nonces));
        self::assertNotSame($box->seal('same plaintext', self::CONTEXT)->ciphertext, $box->seal('same plaintext', self::CONTEXT)->ciphertext);
    }

    public function testTamperedCiphertextFails(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value', self::CONTEXT);
        $flipped = $sealed->ciphertext;
        $flipped = substr_replace($flipped, \chr(\ord($flipped[0]) ^ 1), 0, 1);

        try {
            $box->open(new SealedSecret($flipped, $sealed->nonce, $sealed->keyVersion), self::CONTEXT);
            self::fail('Tampered ciphertext must not open.');
        } catch (SecretOpenFailed $e) {
            self::assertStringNotContainsString('s3cret-value', $e->getMessage());
        }
    }

    public function testTamperedNonceAndTruncatedInputsFail(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value', self::CONTEXT);

        foreach ([
            new SealedSecret($sealed->ciphertext, random_bytes(SecretBox::NONCE_BYTES), 1),
            new SealedSecret($sealed->ciphertext, substr($sealed->nonce, 1), 1),
            new SealedSecret($sealed->ciphertext, $sealed->nonce.'x', 1),
            new SealedSecret($sealed->ciphertext, '', 1),
            new SealedSecret(substr($sealed->ciphertext, 0, -1), $sealed->nonce, 1),
            new SealedSecret('short', $sealed->nonce, 1),
            new SealedSecret('', $sealed->nonce, 1),
        ] as $broken) {
            try {
                $box->open($broken, self::CONTEXT);
                self::fail('A malformed or altered sealed secret must not open.');
            } catch (SecretOpenFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWrongKeyFails(): void
    {
        $sealed = $this->box()->seal('s3cret-value', self::CONTEXT);

        $other = $this->box();
        $other->seal('force the second key into existence', self::CONTEXT);

        $this->expectException(SecretOpenFailed::class);
        $other->open($sealed, self::CONTEXT);
    }

    public function testOpenFailsUnderAnotherContext(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value', 'ctx-a');

        foreach (['ctx-b', 'ctx-', 'ctx-a ', 'CTX-A'] as $other) {
            try {
                $box->open($sealed, $other);
                self::fail('Context "'.$other.'" must not open a value sealed for ctx-a.');
            } catch (SecretOpenFailed $e) {
                self::assertStringNotContainsString('s3cret-value', $e->getMessage());
            }
        }
        self::assertSame('s3cret-value', $box->open($sealed, 'ctx-a'));
    }

    public function testEmptyContextIsRejected(): void
    {
        $box = $this->box();

        try {
            $box->seal('x', '');
            self::fail('seal() needs a context.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $box->open($box->seal('x', 'ctx-a'), '');
    }

    public function testSealedValuesCarryTheAeadKeyVersion(): void
    {
        self::assertSame(2, SecretBox::KEY_VERSION);
        self::assertSame(2, $this->box()->seal('x', self::CONTEXT)->keyVersion);
    }

    public function testPlaintextParametersAreMarkedSensitive(): void
    {
        $parameter = new \ReflectionMethod(SecretBox::class, 'seal')->getParameters()[0];

        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testUnknownKeyVersionFails(): void
    {
        $box = $this->box();
        $sealed = $box->seal('s3cret-value', self::CONTEXT);

        foreach ([0, 1, 3, -1] as $version) {
            try {
                $box->open(new SealedSecret($sealed->ciphertext, $sealed->nonce, $version), self::CONTEXT);
                self::fail('Key version '.$version.' must be rejected.');
            } catch (SecretOpenFailed $e) {
                self::assertStringContainsString((string) $version, $e->getMessage());
            }
        }
    }

    public function testOpenWithoutAKeyFileFailsAndDoesNotCreateOne(): void
    {
        $sealed = $this->box()->seal('s3cret-value', self::CONTEXT);
        $paths = $this->newPaths();
        $box = new SecretBox(new SecretKeyFile($paths));

        try {
            $box->open($sealed, self::CONTEXT);
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

        $box->seal('x', self::CONTEXT);

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
