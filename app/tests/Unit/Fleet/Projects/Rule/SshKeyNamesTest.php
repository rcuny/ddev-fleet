<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\SshKeyNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshKeyNamesTest extends TestCase
{
    public function testAcceptsAllowListedNames(): void
    {
        self::assertNull(SshKeyNames::error([]));
        self::assertNull(SshKeyNames::error(['staging', 'ci-runner', 'a1']));
        self::assertNull(SshKeyNames::error(array_map(static fn (int $i): string => 'k'.$i, range(1, SshKeyNames::MAX_KEYS))));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refused(): iterable
    {
        yield 'deploy key' => [['deploy']];
        yield 'push key' => [['push']];
        yield 'upper case' => [['Staging']];
        yield 'underscore' => [['my_key']];
        yield 'path' => [['../etc/passwd']];
        yield 'slash' => [['a/b']];
        yield 'leading dash' => [['-oProxyCommand']];
        yield 'leading digit' => [['1key']];
        yield 'double dash' => [['a--b']];
        yield 'trailing dash' => [['a-']];
        yield 'space' => [['a b']];
        yield 'empty' => [['']];
        yield 'newline' => [["a\nb"]];
        yield 'too long' => [[str_repeat('a', SshKeyNames::MAX_LENGTH + 1)]];
        yield 'duplicate' => [['a', 'a']];
        yield 'too many' => [array_map(static fn (int $i): string => 'k'.$i, range(1, SshKeyNames::MAX_KEYS + 1))];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('refused')]
    public function testRefusesEverythingElse(array $names): void
    {
        self::assertIsString(SshKeyNames::error($names));
    }
}
