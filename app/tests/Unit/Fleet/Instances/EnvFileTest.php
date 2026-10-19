<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/envfile-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,.}*', \GLOB_BRACE | \GLOB_NOSORT) ?: []);
        @rmdir($this->dir);
    }

    public function testReplacesTheFirstAssignmentInPlace(): void
    {
        $path = $this->dir.'/.env';
        file_put_contents($path, "# keep me\nDB=secret\n\nDRUPAL_ENV=old\nOTHER=1\nDRUPAL_ENV=dup\n");

        EnvFile::setVar($path, 'DRUPAL_ENV', 'dev');

        self::assertSame("# keep me\nDB=secret\n\nDRUPAL_ENV=dev\nOTHER=1\nDRUPAL_ENV=dup\n", file_get_contents($path));
    }

    public function testAppendsOrCreates(): void
    {
        $path = $this->dir.'/.env';
        EnvFile::setVar($path, 'DRUPAL_ENV', 'dev');
        self::assertSame("DRUPAL_ENV=dev\n", file_get_contents($path));

        file_put_contents($path, "A=1\n");
        EnvFile::setVar($path, 'DRUPAL_ENV', 'test');
        self::assertSame("A=1\nDRUPAL_ENV=test\n", file_get_contents($path));
    }

    public function testAValueWithANewlineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EnvFile::setVar($this->dir.'/.env', 'DRUPAL_ENV', "dev\nEVIL=1");
    }
}
