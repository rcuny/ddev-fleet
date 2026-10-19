<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\Labels;
use App\Fleet\Projects\ValidationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabelsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalents(): iterable
    {
        yield 'dash' => ['PWD8-1781'];
        yield 'underscore' => ['PWD8_1781'];
        yield 'double dash' => ['pwd8--1781'];
        yield 'edge dashes' => ['-PWD8-1781-'];
    }

    #[DataProvider('equivalents')]
    public function testSlugifiesLikeV0(string $text): void
    {
        self::assertSame('pwd8-1781', Labels::slug($text));
    }

    public function testBranchesBecomeLabels(): void
    {
        self::assertSame('feature-fle-12-login', Labels::slug('feature/FLE-12/login'));
    }

    public function testNothingUsableIsAFieldError(): void
    {
        try {
            Labels::slug('!!!');
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['label'], array_keys($e->errors));
        }
    }
}
