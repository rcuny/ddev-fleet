<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Secrets;

use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretKeyFile;
use PHPUnit\Framework\TestCase;

final class SecretKeyFileTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-keyfile-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0o700);
    }

    protected function tearDown(): void
    {
        $keys = $this->home.'/keys';
        foreach (glob($keys.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            is_file($file) || is_link($file) ? @unlink($file) : null;
        }
        @rmdir($keys);
        @unlink($this->home.'/elsewhere');
        @rmdir($this->home);
    }

    public function testEnsureCreatesMode0600Key(): void
    {
        $file = $this->keyFile();

        $file->ensure();

        $path = $this->paths()->secretKey();
        self::assertFileExists($path);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES, filesize($path));
        self::assertSame(file_get_contents($path), $file->key());
        self::assertSame(0o700, fileperms($this->paths()->keysDir()) & 0o777);
        self::assertSame([], glob($this->paths()->keysDir().'/.secret.key.*') ?: [], 'no temp file is left behind');
    }

    public function testEnsureNeverOverwrites(): void
    {
        $file = $this->keyFile();
        $file->ensure();
        $first = $file->key();

        $file->ensure();
        $this->keyFile()->ensure();

        self::assertSame($first, $file->key());
    }

    public function testEnsureLosingARaceKeepsTheWinnersKey(): void
    {
        // A key that appears between the existence check and link() is the same as one that was there already.
        $path = $this->paths()->secretKey();
        mkdir($this->paths()->keysDir(), 0o700);
        $winner = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        file_put_contents($path, $winner);
        chmod($path, 0o600);

        $this->keyFile()->ensure();

        self::assertSame($winner, file_get_contents($path));
    }

    public function testWideModeIsRefused(): void
    {
        $path = $this->plantKey(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 0o640);

        try {
            $this->keyFile()->ensure();
            self::fail('A group-readable key must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0640', $e->getMessage());
        }
        self::assertSame(0o640, fileperms($path) & 0o777, 'the mode is not silently repaired');

        $this->expectException(\RuntimeException::class);
        $this->keyFile()->key();
    }

    public function testWrongSizeIsRefusedBeforeAnyWrite(): void
    {
        $path = $this->plantKey('too-short', 0o600);

        try {
            $this->keyFile()->ensure();
            self::fail('A wrong-size key must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exactly', $e->getMessage());
            self::assertStringNotContainsString('too-short', $e->getMessage());
        }
        self::assertSame('too-short', file_get_contents($path));
    }

    public function testSymlinkedKeyIsRefused(): void
    {
        $target = $this->home.'/elsewhere';
        file_put_contents($target, random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        chmod($target, 0o600);
        mkdir($this->paths()->keysDir(), 0o700);
        symlink($target, $this->paths()->secretKey());

        $this->expectException(\RuntimeException::class);
        $this->keyFile()->ensure();
    }

    public function testKeyDoesNotCreateAMissingFile(): void
    {
        try {
            $this->keyFile()->key();
            self::fail('A missing key must not be minted by key().');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->paths()->secretKey());
        }
    }

    private function paths(): FleetPaths
    {
        return new FleetPaths($this->home, $this->home.'/caddy');
    }

    private function keyFile(): SecretKeyFile
    {
        return new SecretKeyFile($this->paths());
    }

    private function plantKey(string $content, int $mode): string
    {
        mkdir($this->paths()->keysDir(), 0o700);
        $path = $this->paths()->secretKey();
        file_put_contents($path, $content);
        chmod($path, $mode);

        return $path;
    }
}
