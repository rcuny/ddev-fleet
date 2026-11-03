<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testParsesTagsAndBareVersions(): void
    {
        self::assertSame('1.2.3', (string) Version::parse('v1.2.3'));
        self::assertSame('v1.2.3', Version::parse('1.2.3')->tag());
        self::assertSame('v1.3.0-rc.1', Version::parse('v1.3.0-rc.1')->tag());
        self::assertTrue(Version::parse('v1.3.0-rc.1')->isPreRelease());
        self::assertFalse(Version::parse('v1.3.0')->isPreRelease());
    }

    #[DataProvider('notVersions')]
    public function testRejectsWhatIsNotAVersion(string $text): void
    {
        self::assertNull(Version::tryParse($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVersions(): iterable
    {
        yield 'branch' => ['main@abc1234'];
        yield 'dev' => ['dev'];
        yield 'two parts' => ['v1.2'];
        yield 'leading zero' => ['v01.2.3'];
        yield 'build metadata' => ['v1.2.3+build'];
        yield 'trailing junk' => ["v1.2.3\nrm"];
        yield 'empty suffix' => ['v1.2.3-'];
        yield 'empty' => [''];
    }

    public function testParseThrowsOnGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Version::parse('nope');
    }

    #[DataProvider('orderings')]
    public function testSemverPrecedence(string $lower, string $higher): void
    {
        self::assertLessThan(0, Version::parse($lower)->compare(Version::parse($higher)));
        self::assertGreaterThan(0, Version::parse($higher)->compare(Version::parse($lower)));
        self::assertTrue(Version::parse($higher)->isNewerThan(Version::parse($lower)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function orderings(): iterable
    {
        yield 'patch' => ['1.2.3', '1.2.4'];
        yield 'numeric not lexical' => ['1.2.9', '1.2.10'];
        yield 'minor beats patch' => ['1.9.9', '1.10.0'];
        yield 'pre-release before release' => ['1.3.0-rc.1', '1.3.0'];
        yield 'pre-release numbers' => ['1.3.0-rc.2', '1.3.0-rc.10'];
        yield 'pre-release names' => ['1.3.0-alpha', '1.3.0-beta'];
        yield 'numeric before alphanumeric' => ['1.3.0-1', '1.3.0-alpha'];
        yield 'shorter pre-release first' => ['1.3.0-rc', '1.3.0-rc.1'];
    }

    public function testEqualVersionsCompareEqual(): void
    {
        self::assertSame(0, Version::parse('v1.2.3')->compare(Version::parse('1.2.3')));
        self::assertSame(0, Version::parse('1.2.3-rc.1')->compare(Version::parse('v1.2.3-rc.1')));
    }
}
