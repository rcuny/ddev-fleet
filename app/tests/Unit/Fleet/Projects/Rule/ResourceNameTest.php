<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Domain\Project\Project;
use App\Fleet\Projects\Rule\ResourceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach (['blog', 'a', 'a-b-1', '1', str_repeat('a', 40)] as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        foreach (['Blog', '-a', 'a-', 'a--b', 'a_b', 'a.b', str_repeat('a', 41), 'new', '', ' a', 'a ', "blog\n", "bl\0og", 'bló', '--', null, 5, ['blog']] as $i => $name) {
            yield '#'.$i.' '.(\is_string($name) ? addcslashes(substr($name, 0, 20), "\0..\37\177..\377") : get_debug_type($name)) => [$name];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $name): void
    {
        self::assertNull(ResourceName::error($name));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $name): void
    {
        $error = ResourceName::error($name);

        self::assertIsString($error);
        if (\is_string($name) && \strlen($name) >= 4) {
            self::assertStringNotContainsString($name, $error);
        }
    }

    public function testTheReservedNameCanBeAllowedForLookups(): void
    {
        self::assertNotNull(ResourceName::error('new'));
        self::assertNull(ResourceName::error('new', false));
        self::assertNotNull(ResourceName::error('Blog', false));
    }

    public function testTheLimitIsTheEntityColumnLength(): void
    {
        self::assertSame(Project::NAME_MAX, ResourceName::MAX);
        self::assertSame(40, ResourceName::MAX);
    }

    public function testHostnameLabelsMustFitTheDnsLabel(): void
    {
        self::assertNull(ResourceName::hostnameError('www', 'blog'));
        self::assertNull(ResourceName::hostnameError('a', 'blog'));
        // <label>-<project>--x : the instance label keeps at least 1 of the 63 characters
        self::assertNull(ResourceName::hostnameError(str_repeat('h', 63 - 1 - 4 - 2 - 1), 'blog'));
        self::assertNotNull(ResourceName::hostnameError(str_repeat('h', 63 - 1 - 4 - 2), 'blog'));
        self::assertNotNull(ResourceName::hostnameError(str_repeat('h', 40), str_repeat('p', 40)));
        foreach (['a.b', 'A', '-a', 'a-', 'a--b', '', 'a b', "a\n", 'www.', '*', null, 5] as $bad) {
            self::assertNotNull(ResourceName::hostnameError($bad, 'blog'), var_export($bad, true));
        }
    }
}
