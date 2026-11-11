<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Terminal\EvidenceMasker;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceMaskerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function secrets(): iterable
    {
        yield 'github' => ['ghp_abcdefghijklmnopqrstuvwxyz0123456789AB'];
        yield 'fleet token' => ['flt_svc_'.str_repeat('A1b2', 12).'xyz'];
        yield 'bearer' => ['Bearer abcdef1234567890.token'];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r'];
        yield 'long key' => ['AbCdEfGhIjKlMnOpQrStUvWxYz0123456789+/AbCd'];
    }

    #[DataProvider('secrets')]
    public function testMasksTokenShapedStrings(string $secret): void
    {
        $out = new EvidenceMasker(new RegisteredSecretsMasker())->mask("please use $secret now");

        self::assertStringNotContainsString($secret, $out);
        self::assertStringStartsWith('please use ', $out);
        self::assertStringEndsWith(' now', $out);
    }

    public function testKeepsOrdinaryTextAndMasksRegisteredSecrets(): void
    {
        $registry = new RegisteredSecretsMasker();
        $registry->register('hunter2');
        $masker = new EvidenceMasker($registry);

        self::assertSame('Please rebase onto develop and push.', $masker->mask('Please rebase onto develop and push.'));
        self::assertSame('pw is [masked]', $masker->mask('pw is hunter2'));
    }
}
