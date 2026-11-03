<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Upgrade;

use App\Fleet\Upgrade\Check\ActiveJobsCheck;
use App\Fleet\Upgrade\Check\CheckoutCleanCheck;
use App\Fleet\Upgrade\Check\CheckRunner;
use App\Fleet\Upgrade\Check\DiskCheck;
use App\Fleet\Upgrade\Check\HostRootCheck;
use App\Fleet\Upgrade\Check\InstallerPreflightCheck;
use App\Fleet\Upgrade\Check\InstanceIdLengthCheck;
use App\Fleet\Upgrade\Check\NetworkCheck;
use App\Fleet\Upgrade\Check\RolloutActionCheck;
use App\Fleet\Upgrade\Check\SchemaAheadCheck;
use App\Fleet\Upgrade\Check\TargetTagCheck;
use App\Fleet\Upgrade\Check\UpgradeCheck;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpgradeCheckRegistrationTest extends KernelTestCase
{
    /**
     * @return list<UpgradeCheck>
     */
    private function registered(): array
    {
        $runner = self::getContainer()->get(CheckRunner::class);
        self::assertInstanceOf(CheckRunner::class, $runner);
        $checks = new \ReflectionProperty($runner, 'checks')->getValue($runner);
        self::assertIsIterable($checks);

        return iterator_to_array($checks, false);
    }

    public function testTheRegistryIsWiredInAFixedOrder(): void
    {
        self::assertSame([
            HostRootCheck::class,
            NetworkCheck::class,
            CheckoutCleanCheck::class,
            TargetTagCheck::class,
            DiskCheck::class,
            ActiveJobsCheck::class,
            InstanceIdLengthCheck::class,
            SchemaAheadCheck::class,
            RolloutActionCheck::class,
            InstallerPreflightCheck::class,
        ], array_map(static fn (UpgradeCheck $c): string => $c::class, $this->registered()));
    }

    public function testEveryUpgradeCheckClassIsTagged(): void
    {
        $registered = array_map(static fn (UpgradeCheck $c): string => $c::class, $this->registered());
        $root = \dirname(__DIR__, 4);
        $declared = [];
        foreach (glob($root.'/src/Fleet/Upgrade/Check/*.php') ?: [] as $file) {
            $class = 'App\\Fleet\\Upgrade\\Check\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, UpgradeCheck::class) && !new \ReflectionClass($class)->isAbstract()) {
                $declared[] = $class;
            }
        }

        self::assertNotEmpty($declared);
        self::assertEqualsCanonicalizing($declared, $registered, 'every UpgradeCheck must carry #[AutoconfigureTag(\'fleet.upgrade_check\', [\'priority\' => N])]');
    }

    public function testTheMigrationLedgerAndTargetSeamsResolve(): void
    {
        self::assertNotEmpty($this->registered());
        self::assertInstanceOf(CheckRunner::class, self::getContainer()->get(CheckRunner::class));
    }
}
