<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Token\ApiTokenPolicy;
use App\Api\Token\InvalidApiTokenRequest;
use App\Domain\ApiToken\ApiScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTokenPolicyTest extends TestCase
{
    private const string NOW = '2026-11-10 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     * @param list<string>   $ipAllow
     */
    private function validate(array $scopes, array $projects = ['blog'], array $ipAllow = [], string $in = '+10 days'): void
    {
        ApiTokenPolicy::validate($scopes, $projects, $ipAllow, $this->now(), $this->now()->modify($in));
    }

    public function testAnOrdinaryTokenIsValid(): void
    {
        $this->validate([ApiScope::Read, ApiScope::Deploy, ApiScope::Lifecycle, ApiScope::LifecycleDestroy]);
        $this->validate([ApiScope::Read], ['*']);
        $this->validate([ApiScope::TerminalWrite], ['blog'], ['192.0.2.1'], '+7 days');
        self::addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{list<ApiScope>, list<string>, list<string>, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no scope' => [[], ['blog'], [], '+1 day', 'at least one scope'];
        yield 'no project' => [[ApiScope::Read], [], [], '+1 day', 'project list'];
        yield 'wildcard with deploy' => [[ApiScope::Read, ApiScope::Deploy], ['*'], [], '+1 day', 'read-only'];
        yield 'wildcard with lifecycle' => [[ApiScope::Lifecycle], ['*'], [], '+1 day', 'read-only'];
        yield 'wildcard with terminal read' => [[ApiScope::TerminalRead], ['*'], [], '+1 day', 'read-only'];
        yield 'wildcard mixed with a name' => [[ApiScope::Read], ['*', 'blog'], [], '+1 day', 'mixed'];
        yield 'destroy flag alone' => [[ApiScope::LifecycleDestroy], ['blog'], [], '+1 day', 'add lifecycle'];
        yield 'terminal write with deploy' => [[ApiScope::TerminalWrite, ApiScope::Deploy], ['blog'], ['192.0.2.1'], '+1 day', 'cannot be combined'];
        yield 'terminal write with lifecycle' => [[ApiScope::TerminalWrite, ApiScope::Lifecycle], ['blog'], ['192.0.2.1'], '+1 day', 'cannot be combined'];
        yield 'terminal write without ip list' => [[ApiScope::TerminalWrite], ['blog'], [], '+1 day', 'IP allow-list'];
        yield 'terminal write over 7 days' => [[ApiScope::TerminalWrite], ['blog'], ['192.0.2.1'], '+8 days', '7 days'];
        yield 'over a year' => [[ApiScope::Read], ['blog'], [], '+366 days', '365 days'];
        yield 'already expired' => [[ApiScope::Read], ['blog'], [], '-1 second', 'future'];
        yield 'expires now' => [[ApiScope::Read], ['blog'], [], '+0 seconds', 'future'];
    }

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     * @param list<string>   $ipAllow
     */
    #[DataProvider('refusals')]
    public function testRefusals(array $scopes, array $projects, array $ipAllow, string $in, string $message): void
    {
        $this->expectException(InvalidApiTokenRequest::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');

        $this->validate($scopes, $projects, $ipAllow, $in);
    }

    public function testDefaultLifetimes(): void
    {
        self::assertSame(90, ApiTokenPolicy::defaultLifetimeDays([ApiScope::Read, ApiScope::Deploy]));
        self::assertSame(30, ApiTokenPolicy::defaultLifetimeDays([ApiScope::Read, ApiScope::Lifecycle]));
        self::assertSame(1, ApiTokenPolicy::defaultLifetimeDays([ApiScope::TerminalRead]));
        self::assertSame(1, ApiTokenPolicy::defaultLifetimeDays([ApiScope::TerminalWrite]));
        self::assertSame(365, ApiTokenPolicy::maxLifetimeDays([ApiScope::Read]));
        self::assertSame(7, ApiTokenPolicy::maxLifetimeDays([ApiScope::TerminalWrite]));
    }
}
