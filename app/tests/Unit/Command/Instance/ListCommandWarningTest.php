<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Instance;

use App\Command\Instance\ListCommand;
use PHPUnit\Framework\TestCase;

final class ListCommandWarningTest extends TestCase
{
    public function testNoOverlongIdMeansNoWarning(): void
    {
        self::assertNull(ListCommand::overlongWarning([]));
    }

    public function testTheWarningNamesTheCountAndTheIds(): void
    {
        $warning = ListCommand::overlongWarning(['blog--a', 'blog--b']);

        self::assertNotNull($warning);
        self::assertStringContainsString('2 instance id(s)', $warning);
        self::assertStringContainsString('blog--a, blog--b', $warning);
        self::assertStringContainsString('Redeploy them under a shorter label', $warning);
    }
}
