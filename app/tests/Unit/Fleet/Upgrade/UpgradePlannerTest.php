<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Upgrade\CatalogState;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\Release;
use App\Fleet\Upgrade\UnknownRelease;
use App\Fleet\Upgrade\UpgradePlanner;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;

final class UpgradePlannerTest extends TestCase
{
    private function catalog(string ...$tags): CatalogState
    {
        $releases = array_map(static fn (string $t): Release => new Release(Version::parse($t), str_repeat('a', 40)), array_values($tags));

        return new CatalogState($releases, null, null);
    }

    /**
     * @return list<string>
     */
    private static function tags(\App\Fleet\Upgrade\Plan $plan): array
    {
        return array_map(static fn (Release $r): string => $r->tag(), $plan->releases);
    }

    public function testBehindPlansUpToTheLatestStable(): void
    {
        $plan = new UpgradePlanner()->plan('v1.1.0', $this->catalog('v1.3.0-rc.1', 'v1.2.1', 'v1.2.0', 'v1.1.0', 'v1.0.6'));

        self::assertSame(Direction::Up, $plan->direction);
        self::assertSame('v1.2.1', $plan->target?->tag());
        self::assertSame(['v1.2.1', 'v1.2.0'], self::tags($plan));
    }

    public function testCurrentIsUpToDate(): void
    {
        $plan = new UpgradePlanner()->plan('v1.2.1', $this->catalog('v1.2.1', 'v1.2.0'));

        self::assertTrue($plan->isUpToDate());
        self::assertSame([], $plan->releases);
    }

    public function testAnExplicitOlderTargetIsADowngrade(): void
    {
        $plan = new UpgradePlanner()->plan('v1.2.1', $this->catalog('v1.2.1', 'v1.2.0', 'v1.1.0'), Version::parse('v1.1.0'));

        self::assertSame(Direction::Down, $plan->direction);
        self::assertSame(['v1.2.1', 'v1.2.0'], self::tags($plan));
    }

    public function testAnExplicitPreReleaseTargetIsAllowedWhenPublished(): void
    {
        $plan = new UpgradePlanner()->plan('v1.2.1', $this->catalog('v1.3.0-rc.1', 'v1.2.1'), Version::parse('v1.3.0-rc.1'));

        self::assertSame('v1.3.0-rc.1', $plan->target?->tag());
        self::assertSame(Direction::Up, $plan->direction);
    }

    public function testUnknownTargetListsWhatExists(): void
    {
        try {
            new UpgradePlanner()->plan('v1.1.0', $this->catalog('v1.2.0', 'v1.1.0'), Version::parse('v1.9.9'));
            self::fail('expected UnknownRelease');
        } catch (UnknownRelease $e) {
            self::assertStringContainsString('v1.9.9 is not a published release; available: v1.2.0, v1.1.0', $e->getMessage());
        }
    }

    public function testABranchInstallHasAnUnknownDirectionButATarget(): void
    {
        $plan = new UpgradePlanner()->plan('main@abc1234', $this->catalog('v1.2.0'));

        self::assertSame(Direction::Unknown, $plan->direction);
        self::assertSame('v1.2.0', $plan->target?->tag());
        self::assertNull($plan->fromVersion);
    }

    public function testAnEmptyCatalogHasNoTarget(): void
    {
        $plan = new UpgradePlanner()->plan('v1.1.0', CatalogState::empty());

        self::assertFalse($plan->hasTarget());
        self::assertSame(Direction::Unknown, $plan->direction);
    }

    public function testInstalledAheadOfTheLatestIsADowngradePlan(): void
    {
        $plan = new UpgradePlanner()->plan('v1.5.0', $this->catalog('v1.2.0'));

        self::assertSame(Direction::Down, $plan->direction);
    }
}
