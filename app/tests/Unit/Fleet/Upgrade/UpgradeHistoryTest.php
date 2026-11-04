<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Command\Upgrade\UpgradeHistoryCommand;
use App\Fleet\Upgrade\HistoryEntry;
use App\Fleet\Upgrade\UpgradeHistory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UpgradeHistoryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/upgrade-history-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function line(string $event, string $result, string $ts, ?string $backup = null): string
    {
        return json_encode(['ts' => $ts, 'event' => $event, 'result' => $result, 'from' => 'v1.1.0', 'to' => 'v1.2.1', 'sha' => str_repeat('a', 40), 'actor' => 'alice', 'backup' => $backup], \JSON_THROW_ON_ERROR)."\n";
    }

    public function testAMissingFileIsAnEmptyHistory(): void
    {
        self::assertSame([], new UpgradeHistory($this->file)->entries());
    }

    public function testEntriesAreReadNewestFirstAndMalformedLinesAreSkipped(): void
    {
        file_put_contents($this->file, $this->line('start', 'started', '2026-11-04T10:00:00+00:00', 'fleet-1.sqlite')."not json\n".'{"ts":"nope"}'."\n\n".$this->line('installer', 'ok', '2026-11-04T10:05:00+00:00').$this->line('verify', 'ok', '2026-11-04T10:06:00+00:00'));

        $entries = new UpgradeHistory($this->file)->entries();

        self::assertSame(['verify', 'installer', 'start'], array_map(static fn (HistoryEntry $e): string => $e->event, $entries));
        self::assertSame('fleet-1.sqlite', $entries[2]->backup);
        self::assertSame('alice', $entries[0]->actor);
        self::assertCount(2, new UpgradeHistory($this->file)->entries(2));
    }

    public function testTheHistoryCommandPrintsLinesOrJsonOrTheEmptyMessage(): void
    {
        $tester = new CommandTester(new UpgradeHistoryCommand(new UpgradeHistory($this->file)));
        $tester->execute([]);
        self::assertStringContainsString('No upgrade has been run on this host yet.', $tester->getDisplay());

        file_put_contents($this->file, $this->line('start', 'started', '2026-11-04T10:00:00+00:00', 'fleet-1.sqlite').$this->line('verify', 'ok', '2026-11-04T10:06:00+00:00'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/2026-11-04 10:06:00  verify\s+ok\s+v1\.1\.0 -> v1\.2\.1  by alice\n2026-11-04 10:00:00  start\s+started\s+v1\.1\.0 -> v1\.2\.1  by alice  backup fleet-1\.sqlite/', $tester->getDisplay());

        $tester->execute(['--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('verify', $decoded[0]['event']);
        self::assertSame('fleet-1.sqlite', $decoded[1]['backup']);
    }
}
