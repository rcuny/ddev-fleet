<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Workspace;

use App\Fleet\Workspace\UsageLimit;
use PHPUnit\Framework\TestCase;

final class UsageLimitTest extends TestCase
{
    private const string DIALOG = <<<'TXT'
        ╭──────────────────────────────────────────────╮
        │ 5-hour limit reached ∙ resets 3pm (Europe/Paris) │
        │ What do you want to do?                       │
        │ ❯ 1. Stop and wait for limit to reset         │
        │   2. Wait here, then continue automatically   │
        ╰──────────────────────────────────────────────╯
        TXT;

    public function testDetectsTheDialogAndParsesTheResetTime(): void
    {
        $limit = UsageLimit::detect(self::DIALOG, new \DateTimeImmutable('2026-11-20T10:00:00+01:00'));

        self::assertNotNull($limit);
        self::assertSame('2026-11-20T15:00:00+01:00', $limit->resetsAt);
    }

    public function testATimeOfDayAlreadyPastMeansTomorrow(): void
    {
        $limit = UsageLimit::detect(self::DIALOG, new \DateTimeImmutable('2026-11-20T16:00:00+01:00'));

        self::assertSame('2026-11-21T15:00:00+01:00', $limit?->resetsAt);
    }

    public function testAnUnparsableResetTimeIsKeptAsPrinted(): void
    {
        $limit = UsageLimit::detect(str_replace('3pm (Europe/Paris)', 'soonish ¯\_(ツ)_/¯', self::DIALOG));

        self::assertNotNull($limit);
        self::assertStringContainsString('soonish', (string) $limit->resetsAt);
    }

    public function testTheDialogWithoutAResetTimeIsStillDetected(): void
    {
        $limit = UsageLimit::detect("What do you want to do?\n1. Stop and wait for limit to reset\n2. Wait here, then continue automatically\n");

        self::assertNotNull($limit);
        self::assertNull($limit->resetsAt);
    }

    public function testANormalPromptIsNotTheDialog(): void
    {
        self::assertNull(UsageLimit::detect("● Done.\n\n╭────╮\n│ ❯ \n╰────╯\n"));
    }

    public function testTextThatOnlyMentionsAUsageLimitIsNotTheDialog(): void
    {
        self::assertNull(UsageLimit::detect("The usage limit resets at 3pm, so I will stop and wait for limit to reset handling in the docs.\nWhat do you want to do?\n"));
    }
}
