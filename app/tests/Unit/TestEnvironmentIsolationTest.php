<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * An APP_ENV / FLEET_HOME exported by the shell (the companion container does both) must never reach the suite.
 */
final class TestEnvironmentIsolationTest extends TestCase
{
    public function testTheSuiteRunsInTheTestEnvironment(): void
    {
        self::assertSame('test', $_SERVER['APP_ENV']);
        self::assertSame('test', getenv('APP_ENV'));
    }

    public function testFleetHomeIsThePerProcessTempDirectory(): void
    {
        self::assertSame(sys_get_temp_dir().'/fleet-test-'.getmypid(), $_SERVER['FLEET_HOME']);
        self::assertSame($_SERVER['FLEET_HOME'], getenv('FLEET_HOME'));
        self::assertDirectoryExists((string) $_SERVER['FLEET_HOME']);
    }

    public function testTheProcessDatabaseIsAlreadyMigrated(): void
    {
        $file = $_SERVER['FLEET_HOME'].'/var/fleet.sqlite';
        self::assertFileExists($file);

        $pdo = new \PDO('sqlite:'.$file);
        $found = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'project'");
        self::assertNotFalse($found);
        self::assertSame(1, (int) $found->fetchColumn());
    }
}
