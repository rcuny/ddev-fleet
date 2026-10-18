<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Setup\ScanTargetPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScanTargetPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed(): iterable
    {
        yield 'name' => ['git.example.com'];
        yield 'name with a leading digit' => ['1password.example.com'];
        yield 'name that starts with a digit and has hex-looking labels' => ['2fa.example.com'];
        yield 'name with a port' => ['[git.example.com]:2222'];
        yield 'public address' => ['203.0.113.7'];
        yield 'private address' => ['10.1.2.3'];
        yield 'address with a port' => ['[198.51.100.9]:2222'];
        yield 'near link-local' => ['169.253.1.1'];
        yield 'near loopback' => ['128.0.0.1'];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $host): void
    {
        self::assertNull(ScanTargetPolicy::refusal(ScanTargetPolicy::normalize($host)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback end' => ['127.255.255.255'];
        yield 'metadata' => ['169.254.169.254'];
        yield 'any' => ['0.0.0.0'];
        yield 'zero net' => ['0.1.2.3'];
        yield 'integer' => ['2130706433'];
        yield 'two parts' => ['127.1'];
        yield 'three parts' => ['127.0.1'];
        yield 'hex' => ['0x7f.0.0.1'];
        yield 'octal' => ['0177.0.0.1'];
        yield 'leading zeros' => ['127.000.000.001'];
        yield 'out of range' => ['256.1.1.1'];
        yield 'localhost' => ['localhost'];
        yield 'upper case localhost' => ['LOCALHOST'];
        yield 'under localhost' => ['git.localhost'];
        yield 'bracketed' => ['[127.0.0.1]:22'];
        yield 'bracketed localhost' => ['[localhost]:2222'];
    }

    #[DataProvider('refused')]
    public function testRefused(string $host): void
    {
        self::assertNotNull(ScanTargetPolicy::refusal(ScanTargetPolicy::normalize($host)));
    }

    public function testNormalizeRejectsWhatIsNotAHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScanTargetPolicy::normalize(' -oProxyCommand=x ');
    }

    public function testNormalizeTrimsAndLowercases(): void
    {
        self::assertSame('git.example.com', ScanTargetPolicy::normalize("  Git.Example.COM \n"));
    }
}
