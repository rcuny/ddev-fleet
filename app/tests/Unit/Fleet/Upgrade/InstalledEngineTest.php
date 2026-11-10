<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Upgrade\InstalledEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstalledEngineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/installed-engine-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testAHostWithoutTheRecordIsBash(): void
    {
        self::assertSame('bash', new InstalledEngine($this->dir.'/installed-engine')->name());
    }

    #[DataProvider('records')]
    public function testTheRecordDecides(string $content, string $expected): void
    {
        file_put_contents($this->dir.'/installed-engine', $content);

        self::assertSame($expected, new InstalledEngine($this->dir.'/installed-engine')->name());
    }

    /** @return iterable<string, array{string, string}> */
    public static function records(): iterable
    {
        yield 'ansible' => ["ansible\n", 'ansible'];
        yield 'bash' => ["bash\n", 'bash'];
        yield 'empty' => ['', 'bash'];
        yield 'unknown word' => ["puppet\n", 'bash'];
        yield 'not exact' => ["ansible; rm -rf /\n", 'bash'];
    }
}
