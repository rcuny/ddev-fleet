<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects;

use App\Fleet\Projects\NamedPortInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamedPortInputTest extends TestCase
{
    /**
     * @return iterable<string, array{int|string, int|string}>
     */
    public static function numbers(): iterable
    {
        yield 'plain digits become an int' => ['9108', 9108];
        yield 'surrounding blanks are trimmed' => [' 9108 ', 9108];
        yield 'an int stays' => [9108, 9108];
        yield 'five digits at most: 65535' => ['65535', 65535];
        yield 'zero is a number (the rule refuses it)' => ['0', 0];
        yield 'six digits stay text' => ['009001', '009001'];
        yield 'above five digits stay text' => ['123456', '123456'];
        yield 'text stays' => ['abc', 'abc'];
        yield 'letters after a digit stay' => ['x1', 'x1'];
        yield 'a decimal stays' => ['12.5', '12.5'];
        yield 'a negative stays' => ['-5', '-5'];
        yield 'inner blanks stay' => ['80 80', '80 80'];
        yield 'empty stays' => ['', ''];
    }

    #[DataProvider('numbers')]
    public function testNormalisedTurnsPlainDigitsIntoIntegersAndLeavesTheRestForTheRule(int|string $given, int|string $expected): void
    {
        $normalised = new NamedPortInput('a', $given, $given)->normalised();

        self::assertSame($expected, $normalised->public);
        self::assertSame($expected, $normalised->router);
    }

    public function testNormalisedTrimsTheNameButKeepsItsCase(): void
    {
        self::assertSame('Bad', new NamedPortInput(' Bad ', 1, 2)->normalised()->name);
    }
}
