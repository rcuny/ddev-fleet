<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Fleet\System\SlicesRenderer;
use PHPUnit\Framework\TestCase;

final class SlicesRendererTest extends TestCase
{
    public function testTheTreeIsRootProjectsAndTemplatesWithEncodedNames(): void
    {
        $units = new SlicesRenderer()->units(14, [
            ['name' => 'my-blog', 'cpuThreads' => 6, 'templates' => [
                ['name' => 'ci-full', 'cpuThreads' => 4],
                ['name' => 'web', 'cpuThreads' => null],
            ]],
            ['name' => 'shop', 'cpuThreads' => null, 'templates' => []],
        ]);

        self::assertSame([
            ['unit' => 'fleet.slice', 'quota_percent' => 1400],
            ['unit' => 'fleet-my_blog.slice', 'quota_percent' => 600],
            ['unit' => 'fleet-my_blog-ci_full.slice', 'quota_percent' => 400],
            ['unit' => 'fleet-my_blog-web.slice', 'quota_percent' => null],
            ['unit' => 'fleet-shop.slice', 'quota_percent' => null],
        ], $units);
    }

    public function testLimitsAreCappedAtTheirParent(): void
    {
        $units = new SlicesRenderer()->units(10, [
            ['name' => 'a', 'cpuThreads' => 40, 'templates' => [['name' => 'b', 'cpuThreads' => 50]]],
            ['name' => 'c', 'cpuThreads' => 3, 'templates' => [['name' => 'd', 'cpuThreads' => 5]]],
        ]);

        self::assertSame([1000, 1000, 1000, 300, 300], array_column($units, 'quota_percent'));
    }

    public function testEveryUnitNameMatchesTheHelpersPattern(): void
    {
        $name = SlicesRenderer::unitName('ddev-x-1', 'a-b');

        self::assertSame('fleet-ddev_x_1-a_b.slice', $name);
        self::assertMatchesRegularExpression('/^fleet(-[a-z0-9_]+){0,2}\.slice$/', $name);
    }

    public function testTheJsonIsAListOfUnitAndQuota(): void
    {
        self::assertSame('[{"unit":"fleet.slice","quota_percent":200}]', new SlicesRenderer()->render(2, []));
    }
}
