<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\FleetPaths;
use App\Fleet\Upgrade\Check\ActiveJobCounter;
use App\Fleet\Upgrade\Check\ActiveJobsCheck;
use App\Fleet\Upgrade\Check\AppliedMigrations;
use App\Fleet\Upgrade\Check\DiskCheck;
use App\Fleet\Upgrade\Check\InstanceIdLengthCheck;
use App\Fleet\Upgrade\Check\InstanceIdSource;
use App\Fleet\Upgrade\Check\SchemaAheadCheck;
use App\Fleet\Upgrade\Check\Severity;
use App\Fleet\Upgrade\Check\TargetMigrations;
use App\Fleet\Upgrade\Release;
use App\Health\HostHealth;
use Symfony\Component\Filesystem\Filesystem;

final class SimpleChecksTest extends CheckTestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-simple-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->home);
    }

    private function disk(int $headroom): DiskCheck
    {
        $paths = new FleetPaths($this->home, $this->home.'/caddy');
        file_put_contents($paths->fleetDb(), str_repeat('x', 1000));

        return new DiskCheck(new HostHealth($paths), $paths, $headroom);
    }

    public function testPlentyOfDiskIsQuiet(): void
    {
        self::assertSame([], $this->disk(0)->check(self::plan()));
    }

    public function testTooLittleDiskBlocksAndIsNotOverridable(): void
    {
        $results = $this->disk(intdiv(\PHP_INT_MAX, 2))->check(self::plan());

        self::assertSame([DiskCheck::SPACE], self::ids($results));
        self::assertSame(Severity::Block, $results[0]->severity);
        self::assertFalse($results[0]->overridable);
    }

    private static function jobs(int $n): ActiveJobCounter
    {
        return new readonly class($n) implements ActiveJobCounter {
            public function __construct(private int $n)
            {
            }

            public function count(): int
            {
                return $this->n;
            }
        };
    }

    public function testNoActiveJobsIsQuiet(): void
    {
        self::assertSame([], new ActiveJobsCheck(self::jobs(0))->check(self::plan()));
    }

    public function testActiveJobsWarnAndCanBeForced(): void
    {
        $results = new ActiveJobsCheck(self::jobs(2))->check(self::plan());

        self::assertSame([ActiveJobsCheck::ACTIVE], self::ids($results));
        self::assertSame(Severity::Warn, $results[0]->severity);
        self::assertTrue($results[0]->overridable);
        self::assertStringContainsString('2 jobs are', $results[0]->message);
    }

    /**
     * @param list<string> $ids
     */
    private static function ids59(array $ids): InstanceIdSource
    {
        return new readonly class($ids) implements InstanceIdSource {
            /** @param list<string> $ids */
            public function __construct(private array $ids)
            {
            }

            public function ids(): array
            {
                return $this->ids;
            }
        };
    }

    public function testTheLimitComesFromTheInstanceIdPattern(): void
    {
        self::assertSame(59, InstanceIdLengthCheck::limit());
    }

    public function testInstanceIdsWithinTheLimitAreQuiet(): void
    {
        $ok = str_repeat('a', 30).'--'.str_repeat('b', 27);

        self::assertSame(59, \strlen($ok));
        self::assertSame([], new InstanceIdLengthCheck(self::ids59([$ok, 'acme--main']))->check(self::plan()));
    }

    public function testAnOverlongInstanceIdBlocksButMayBeForced(): void
    {
        $long = str_repeat('a', 30).'--'.str_repeat('b', 28);

        $results = new InstanceIdLengthCheck(self::ids59(['acme--main', $long]))->check(self::plan());

        self::assertSame([InstanceIdLengthCheck::ID_LENGTH], self::ids($results));
        self::assertTrue($results[0]->overridable);
        self::assertStringContainsString($long, $results[0]->message);
    }

    /**
     * @param list<string>|null $shipped
     * @param list<string>      $applied
     */
    private static function schema(array $applied, ?array $shipped): SchemaAheadCheck
    {
        $a = new readonly class($applied) implements AppliedMigrations {
            /** @param list<string> $names */
            public function __construct(private array $names)
            {
            }

            public function names(): array
            {
                return $this->names;
            }
        };
        $t = new readonly class($shipped) implements TargetMigrations {
            /** @param list<string>|null $names */
            public function __construct(private ?array $names)
            {
            }

            public function at(Release $release): ?array
            {
                return $this->names;
            }
        };

        return new SchemaAheadCheck($a, $t);
    }

    public function testASchemaTheTargetCoversIsQuiet(): void
    {
        self::assertSame([], self::schema(['V1', 'V2'], ['V1', 'V2', 'V3'])->check(self::plan()));
    }

    public function testMigrationsTheTargetLacksForceTheRollbackPath(): void
    {
        $results = self::schema(['V1', 'V2', 'V3'], ['V1'])->check(self::plan());

        self::assertSame([SchemaAheadCheck::AHEAD], self::ids($results));
        self::assertSame(Severity::Warn, $results[0]->severity);
        self::assertFalse($results[0]->overridable);
        self::assertStringContainsString('V2, V3', $results[0]->message);
        self::assertStringContainsString('restored from the backup', $results[0]->remedy);
    }

    public function testUnreadableTargetMigrationsOnlyInform(): void
    {
        $results = self::schema(['V1'], null)->check(self::plan());

        self::assertSame([SchemaAheadCheck::UNKNOWN], self::ids($results));
        self::assertSame(Severity::Info, $results[0]->severity);
    }
}
