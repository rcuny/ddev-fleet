<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Jobs;

use App\Fleet\Jobs\RegisteredSecretsMasker;
use PHPUnit\Framework\TestCase;

final class RegisteredSecretsMaskerTest extends TestCase
{
    public function testNothingRegisteredLeavesTheTextAlone(): void
    {
        $masker = new RegisteredSecretsMasker();

        self::assertSame('plain text', $masker->mask('plain text'));
        self::assertSame(0, $masker->longestSecret());
    }

    public function testEveryOccurrenceOfEverySecretIsMasked(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('alpha-123');
        $masker->register('beta-456');
        $masker->register('alpha-123');

        self::assertSame('[masked] and [masked] and [masked]', $masker->mask('alpha-123 and beta-456 and alpha-123'));
        self::assertSame(9, $masker->longestSecret());
    }

    public function testASecretContainingAnotherIsMaskedWhole(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('abcd');
        $masker->register('abcd-efgh-ijkl');

        self::assertSame('x [masked] y', $masker->mask('x abcd-efgh-ijkl y'));
    }

    public function testAnEmptyValueIsIgnored(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('');

        self::assertSame('abc', $masker->mask('abc'));
    }

    public function testASecretRegisteredLaterIsMaskedFromThenOn(): void
    {
        $masker = new RegisteredSecretsMasker();
        self::assertSame('late-1', $masker->mask('late-1'));

        $masker->register('late-1');

        self::assertSame('[masked]', $masker->mask('late-1'));
    }

    public function testNumericSecretsAreMaskedToo(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('123456');

        self::assertSame('pin [masked]', $masker->mask('pin 123456'));
        self::assertSame(6, $masker->longestSecret());
    }

    public function testClearForgetsEverything(): void
    {
        $masker = new RegisteredSecretsMasker();
        $masker->register('gone-soon');
        $masker->clear();

        self::assertSame('gone-soon', $masker->mask('gone-soon'));
        self::assertSame(0, $masker->longestSecret());
    }
}
