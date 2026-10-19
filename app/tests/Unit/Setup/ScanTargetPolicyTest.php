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
        yield 'trailing dot' => ['localhost.'];
        yield 'trailing dot under localhost' => ['git.localhost.'];
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

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function resolutions(): iterable
    {
        yield 'unknown name' => [[], false];
        yield 'public' => [['203.0.113.7'], false];
        yield 'loopback' => [['127.0.0.1'], true];
        yield 'loopback and public' => [['127.0.0.1', '203.0.113.7'], false];
        yield 'link local' => [['169.254.169.254'], true];
        yield 'unspecified' => [['0.0.0.0'], true];
        yield 'ipv6 loopback' => [['::1'], true];
        yield 'ipv6 unspecified' => [['::'], true];
        yield 'ipv6 link local' => [['fe80::1'], true];
        yield 'ipv6 mapped loopback' => [['::ffff:127.0.0.1'], true];
        yield 'ipv6 public' => [['2001:db8::1'], false];
        yield 'private network' => [['10.0.0.5'], false];
        yield 'both loopbacks' => [['127.0.0.1', '::1'], true];
        yield 'not an address' => [['garbage'], false];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('resolutions')]
    public function testANameThatResolvesOnlyToThisMachineIsRefused(array $addresses, bool $refused): void
    {
        self::assertSame($refused, null !== ScanTargetPolicy::refusalForAddresses($addresses));
    }
}
