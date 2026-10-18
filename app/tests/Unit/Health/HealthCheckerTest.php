<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Fleet\FleetPaths;
use App\Health\HealthChecker;
use App\Health\HealthReport;
use App\Tests\Support\TempFleetHome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class HealthCheckerTest extends TestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        $this->restoreFleetHome();
    }

    private static function memoryConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testEverythingOkOnAFreshHome(): void
    {
        $home = $this->useFreshFleetHome();

        $report = new HealthChecker(new FleetPaths($home, $home.'/caddy'), self::memoryConnection(), self::memoryConnection())->check();

        self::assertTrue($report->isOk());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['fleet_db' => 'ok', 'queue_db' => 'ok', 'home_writable' => 'ok']],
            $report->toArray(),
        );
        self::assertSame([], glob($home.'/var/.health-*'), 'the probe file is removed');
    }

    public function testReportsFailWhenHomeNotWritable(): void
    {
        // mkdir under /proc fails for every user, root included.
        $report = new HealthChecker(new FleetPaths('/proc/nonexistent', '/proc/nonexistent/caddy'), self::memoryConnection(), self::memoryConnection())->check();

        self::assertFalse($report->isOk());
        self::assertSame(
            ['status' => 'fail', 'checks' => ['fleet_db' => 'ok', 'queue_db' => 'ok', 'home_writable' => 'fail']],
            $report->toArray(),
        );
    }

    public function testAFailedCheckIsLoggedButNotExposed(): void
    {
        $home = $this->useFreshFleetHome();
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, $context];
            }
        };
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/proc/nonexistent/x.sqlite']);

        $report = new HealthChecker(new FleetPaths($home, $home.'/caddy'), self::memoryConnection(), $broken, $logger)->check();

        self::assertFalse($report->isOk());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('queue_db', $logger->records[0][1]['check']);
        self::assertStringNotContainsString('nonexistent', json_encode($report->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testReportsFailWhenADatabaseIsUnreachable(): void
    {
        $home = $this->useFreshFleetHome();
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/proc/nonexistent/x.sqlite']);

        $report = new HealthChecker(new FleetPaths($home, $home.'/caddy'), self::memoryConnection(), $broken)->check();

        self::assertSame('ok', $report->toArray()['checks']['fleet_db']);
        self::assertSame('fail', $report->toArray()['checks']['queue_db']);
        self::assertFalse($report->isOk());
    }

    public function testReportNeverLeaksDetails(): void
    {
        $report = new HealthReport(['a' => true, 'b' => false]);

        self::assertSame(['status' => 'fail', 'checks' => ['a' => 'ok', 'b' => 'fail']], $report->toArray());
    }
}
