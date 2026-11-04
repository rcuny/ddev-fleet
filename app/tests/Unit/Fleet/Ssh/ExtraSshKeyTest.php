<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Ssh\ExtraSshKey;
use PHPUnit\Framework\TestCase;

final class ExtraSshKeyTest extends TestCase
{
    private string $home;
    private ExtraSshKey $check;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle190-key-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        $this->check = new ExtraSshKey(new FleetPaths($this->home, $this->home.'/caddy'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    public function testAnOwnedRegularFileWithMode0600IsUsable(): void
    {
        touch($this->home.'/ssh/staging_ed25519');
        chmod($this->home.'/ssh/staging_ed25519', 0o600);

        self::assertNull($this->check->problem('staging'));
        self::assertSame($this->home.'/ssh/staging_ed25519', $this->check->path('staging'));
    }

    public function testRefusesMissingLooseSymlinkedAndReservedKeys(): void
    {
        touch($this->home.'/ssh/loose_ed25519');
        chmod($this->home.'/ssh/loose_ed25519', 0o640);
        touch($this->home.'/real');
        chmod($this->home.'/real', 0o600);
        symlink($this->home.'/real', $this->home.'/ssh/linked_ed25519');
        touch($this->home.'/ssh/deploy_ed25519');
        chmod($this->home.'/ssh/deploy_ed25519', 0o600);

        foreach (['missing', 'loose', 'linked', 'deploy', 'push', '../real'] as $name) {
            $problem = $this->check->problem($name);
            self::assertIsString($problem, $name);
            self::assertStringNotContainsString($this->home, $problem);
        }
    }
}
