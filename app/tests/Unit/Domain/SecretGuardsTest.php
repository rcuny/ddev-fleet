<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Secret\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretGuardsTest extends TestCase
{
    private static function nonce(): string
    {
        return str_repeat('n', \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    }

    public function testTheClockIsTheCallersAndIsKept(): void
    {
        $at = new \DateTimeImmutable('2026-01-02 03:04:05');
        $secret = new Secret(Secret::SCOPE_PROJECT, 'acme', 'token', 'c', self::nonce(), 1, 'admin', $at);
        self::assertSame($at, $secret->updatedAt());

        $later = new \DateTimeImmutable('2026-02-02 03:04:05');
        $secret->replace('c2', self::nonce(), 2, 'admin', $later);
        self::assertSame($later, $secret->updatedAt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badScopes(): iterable
    {
        yield 'unknown scope' => ['global', ''];
        yield 'fleet with a scope id' => [Secret::SCOPE_FLEET, 'acme'];
        yield 'project without a scope id' => [Secret::SCOPE_PROJECT, ''];
    }

    #[DataProvider('badScopes')]
    public function testScopeAndScopeIdMustAgree(string $scope, string $scopeId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Secret($scope, $scopeId, 'token', 'c', self::nonce(), 1, 'admin', new \DateTimeImmutable());
    }

    public function testFleetScopeWithEmptyIdIsAccepted(): void
    {
        $secret = new Secret(Secret::SCOPE_FLEET, '', 'token', 'c', self::nonce(), 1, 'admin', new \DateTimeImmutable());
        self::assertSame('', $secret->scopeId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNonces(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['nonce'];
        yield 'long' => [str_repeat('n', \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1)];
    }

    #[DataProvider('badNonces')]
    public function testTheNonceMustBeASecretboxNonce(string $nonce): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Secret(Secret::SCOPE_FLEET, '', 'token', 'c', $nonce, 1, 'admin', new \DateTimeImmutable());
    }

    #[DataProvider('badNonces')]
    public function testReplaceChecksTheNonceToo(string $nonce): void
    {
        $secret = new Secret(Secret::SCOPE_FLEET, '', 'token', 'c', self::nonce(), 1, 'admin', new \DateTimeImmutable());

        $this->expectException(\InvalidArgumentException::class);
        $secret->replace('c2', $nonce, 2, 'admin', new \DateTimeImmutable());
    }
}
