<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Projects\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class InstanceNamingTest extends TestCase
{
    public function testBareLabelWhenFree(): void
    {
        self::assertSame('develop', InstanceNaming::allocateFree(['acme--main'], 'acme', 'develop'));
    }

    public function testSuffixesWhenTaken(): void
    {
        self::assertSame('develop-1', InstanceNaming::allocateFree(['acme--develop'], 'acme', 'develop'));
        self::assertSame('develop-2', InstanceNaming::allocateFree(['acme--develop', 'acme--develop-1'], 'acme', 'develop'));
        self::assertSame('develop-1', InstanceNaming::allocateFree(['acme--develop', 'acme--develop-2'], 'acme', 'develop'), 'the first free number wins');
    }

    public function testAnotherProjectsIdDoesNotCount(): void
    {
        self::assertSame('develop', InstanceNaming::allocateFree(['blog--develop'], 'acme', 'develop'));
    }

    public function testBatchNeverUsesTheBareLabel(): void
    {
        self::assertSame(['develop-1', 'develop-2', 'develop-3'], InstanceNaming::allocateBatch([], 'acme', 'develop', 3));
        self::assertSame(['develop-1'], InstanceNaming::allocateBatch(['acme--develop'], 'acme', 'develop', 1));
    }

    public function testBatchSkipsTakenNumbers(): void
    {
        self::assertSame(
            ['develop-2', 'develop-4', 'develop-5'],
            InstanceNaming::allocateBatch(['acme--develop-1', 'acme--develop-3'], 'acme', 'develop', 3),
        );
    }

    public function testComposeRejectsOver63(): void
    {
        self::assertSame(63, \strlen(InstanceNaming::compose('acme', str_repeat('a', 57))));

        $this->expectException(ValidationFailed::class);
        InstanceNaming::compose('acme', str_repeat('a', 58));
    }

    public function testASuffixThatOverflowsIsRefused(): void
    {
        $label = str_repeat('a', 56); // 'acme--' + 56 = 62 characters: the bare label fits, '-1' does not... 62 + 2 = 64
        self::assertSame($label, InstanceNaming::allocateFree([], 'acme', $label));

        $this->expectException(ValidationFailed::class);
        InstanceNaming::allocateFree(['acme--'.$label], 'acme', $label);
    }

    public function testIsInstanceId(): void
    {
        self::assertTrue(InstanceNaming::isInstanceId('acme--develop-1'));
        self::assertFalse(InstanceNaming::isInstanceId('acme'));
        self::assertFalse(InstanceNaming::isInstanceId('../acme--x'));
        self::assertFalse(InstanceNaming::isInstanceId('Acme--x'));
    }
}
