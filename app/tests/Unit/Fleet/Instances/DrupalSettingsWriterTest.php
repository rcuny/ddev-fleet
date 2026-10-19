<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\DrupalSettingsWriter;
use PHPUnit\Framework\TestCase;

final class DrupalSettingsWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/drupal-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/.ddev', 0o750, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testNothingWithoutSitesDefault(): void
    {
        self::assertSame([], new DrupalSettingsWriter()->write($this->dir, 'fleet.example.com'));
        self::assertFileDoesNotExist($this->dir.'/sites/default/settings.local.php');
    }

    public function testUsesDocrootFromConfig(): void
    {
        file_put_contents($this->dir.'/.ddev/config.yaml', "name: blog\ndocroot: web\n");
        mkdir($this->dir.'/web/sites/default', 0o750, true);

        $written = new DrupalSettingsWriter()->write($this->dir, 'fleet.example.com');

        self::assertSame(['web/sites/default/settings.local.php', 'web/sites/default/services.fleet.yml'], $written);
        self::assertStringContainsString("'^.+\\.fleet\\.example\\.com\$'", (string) file_get_contents($this->dir.'/web/sites/default/settings.local.php'));
        self::assertStringContainsString("cookie_domain: ''", (string) file_get_contents($this->dir.'/web/sites/default/services.fleet.yml'));
    }

    public function testWithoutADocrootSitesDefaultIsAtTheRoot(): void
    {
        mkdir($this->dir.'/sites/default', 0o750, true);

        self::assertSame(['sites/default/settings.local.php', 'sites/default/services.fleet.yml'], new DrupalSettingsWriter()->write($this->dir, 'fleet.example.com'));
    }

    public function testADocrootOutsideTheCheckoutIsIgnored(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(6));
        mkdir($outside.'/sites/default', 0o750, true);
        file_put_contents($this->dir.'/.ddev/config.yaml', 'docroot: ../'.basename($outside)."\n");

        self::assertSame([], new DrupalSettingsWriter()->write($this->dir, 'fleet.example.com'));
        self::assertFileDoesNotExist($outside.'/sites/default/settings.local.php');
        rmdir($outside.'/sites/default');
        rmdir($outside.'/sites');
        rmdir($outside);
    }

    public function testASymlinkedSitesDefaultIsNotWrittenThrough(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0o750);
        mkdir($this->dir.'/sites', 0o750);
        symlink($outside, $this->dir.'/sites/default');

        self::assertSame([], new DrupalSettingsWriter()->write($this->dir, 'fleet.example.com'));
        self::assertSame([], glob($outside.'/*'));
        rmdir($outside);
    }
}
