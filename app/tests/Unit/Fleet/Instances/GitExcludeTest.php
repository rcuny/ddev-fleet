<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\GitExclude;
use PHPUnit\Framework\TestCase;

final class GitExcludeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gitexclude-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/.git/info', 0o750, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.git/info/exclude');
        @rmdir($this->dir.'/.git/info');
        @rmdir($this->dir.'/.git');
        @rmdir($this->dir);
    }

    public function testAppendsOnlyMissingLines(): void
    {
        file_put_contents($this->dir.'/.git/info/exclude', "# header\n.ddev/config.fleet.yaml");

        GitExclude::add($this->dir, ['.ddev/config.fleet.yaml', 'sites/default/settings.local.php']);
        GitExclude::add($this->dir, ['sites/default/settings.local.php']);

        self::assertSame("# header\n.ddev/config.fleet.yaml\nsites/default/settings.local.php\n", file_get_contents($this->dir.'/.git/info/exclude'));
    }

    public function testCreatesTheFile(): void
    {
        GitExclude::add($this->dir, ['a', 'b']);

        self::assertSame("a\nb\n", file_get_contents($this->dir.'/.git/info/exclude'));
    }

    public function testANewlineInAPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GitExclude::add($this->dir, ["a\nb"]);
    }
}
