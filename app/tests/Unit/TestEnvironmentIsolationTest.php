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
}
