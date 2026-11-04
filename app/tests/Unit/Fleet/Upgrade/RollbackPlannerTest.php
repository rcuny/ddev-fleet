<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Upgrade\HistoryEntry;
use App\Fleet\Upgrade\RollbackPlanner;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;

final class RollbackPlannerTest extends TestCase
{
    private function start(string $from, string $to, ?string $backup, string $ts = '2026-11-01T10:00:00+00:00', string $event = 'start'): HistoryEntry
    {
        return new HistoryEntry(new \DateTimeImmutable($ts), $event, 'started', $from, $to, null, 'alice', $backup);
    }

    /**
     * @param list<HistoryEntry> $history
     * @param list<string>       $backups
     */
    private function plan(string $installed, string $target, array $history, array $backups, bool $ahead = false, bool $noDb = false): \App\Fleet\Upgrade\RollbackPlan
    {
        return new RollbackPlanner()->plan(Version::parse($installed), Version::parse($target), $history, $backups, $ahead, $noDb);
    }

    public function testItPicksTheNewestBackupOfAnUpgradeFromTheTarget(): void
    {
        $history = [
            $this->start('v1.1.0', 'v1.2.1', 'fleet-20261103-100000.sqlite', '2026-11-03T10:00:00+00:00'),
            $this->start('v1.2.0', 'v1.2.1', 'fleet-20261102-100000.sqlite', '2026-11-02T10:00:00+00:00'),
            $this->start('v1.1.0', 'v1.2.0', 'fleet-20261101-100000.sqlite'),
        ];
        $plan = $this->plan('v1.2.1', 'v1.1.0', $history, ['fleet-20261101-100000.sqlite', 'fleet-20261103-100000.sqlite']);

        self::assertTrue($plan->ok());
        self::assertTrue($plan->restoreDb);
        self::assertSame('fleet-20261103-100000.sqlite', $plan->backup);
        self::assertSame('2026-11-03T10:00:00+00:00', $plan->since?->format(\DATE_ATOM));
    }

    public function testABackupFileThatIsGoneIsSkipped(): void
    {
        $history = [$this->start('v1.1.0', 'v1.2.1', 'fleet-20261103-100000.sqlite', '2026-11-03T10:00:00+00:00'), $this->start('v1.1.0', 'v1.2.0', 'fleet-20261101-100000.sqlite')];

        $plan = $this->plan('v1.2.1', 'v1.1.0', $history, ['fleet-20261101-100000.sqlite']);

        self::assertSame('fleet-20261101-100000.sqlite', $plan->backup);
    }

    public function testNoBackupRefusesWithTheOptions(): void
    {
        $plan = $this->plan('v1.2.1', 'v1.1.0', [$this->start('v1.2.0', 'v1.2.1', 'fleet-20261101-100000.sqlite')], ['fleet-20261101-100000.sqlite']);

        self::assertFalse($plan->ok());
        self::assertStringContainsString('No pre-upgrade backup of v1.1.0', $plan->refusals[0]);
        self::assertStringContainsString('--no-db-restore', $plan->refusals[0]);
    }

    public function testOnlyStartLinesWithAnUpwardMoveCount(): void
    {
        $history = [
            $this->start('v1.1.0', 'v1.2.1', 'fleet-20261103-100000.sqlite', event: 'installer'),
            $this->start('v1.1.0', 'v1.0.6', 'fleet-20261102-100000.sqlite'),
            $this->start('v1.1.0', 'v1.2.1', null),
        ];

        self::assertFalse($this->plan('v1.2.1', 'v1.1.0', $history, ['fleet-20261103-100000.sqlite', 'fleet-20261102-100000.sqlite'])->ok());
    }

    public function testNoDbRestoreIsAllowedOnlyWhenNoUnknownMigrationIsApplied(): void
    {
        $allowed = $this->plan('v1.2.1', 'v1.1.0', [], [], false, true);
        self::assertTrue($allowed->ok());
        self::assertFalse($allowed->restoreDb);
        self::assertNull($allowed->backup);

        $refused = $this->plan('v1.2.1', 'v1.1.0', [], [], true, true);
        self::assertFalse($refused->ok());
        self::assertStringContainsString('--no-db-restore is refused', $refused->refusals[0]);
    }

    public function testBelowTheFloorAcrossAMajorAndPreReleasesAreRefused(): void
    {
        self::assertStringContainsString('below v1.1.0', $this->plan('v1.2.1', 'v1.0.6', [], [])->refusals[0]);
        self::assertStringContainsString('across a major', $this->plan('v2.0.0', 'v1.2.1', [], [])->refusals[0]);
        self::assertStringContainsString('pre-release', $this->plan('v1.2.1', 'v1.2.0-rc.1', [], [])->refusals[0]);
    }
}
