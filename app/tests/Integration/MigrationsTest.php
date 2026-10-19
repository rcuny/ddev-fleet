<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MigrationsTest extends KernelTestCase
{
    use FleetDatabase;

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testMigrationsBuildTheMappedSchema(): void
    {
        $em = $this->setUpFleetDatabase();

        [$exit, $output] = $this->console('doctrine:schema:validate');

        $output = (string) preg_replace('/\s+/', ' ', $output); // console output wraps at the terminal width

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('mapping files are correct', $output);
        self::assertStringContainsString('schema is in sync with the mapping files', $output);

        $tables = $em->getConnection()->createSchemaManager()->listTableNames();
        foreach (['host_settings', 'fleet_user', 'ssh_key', 'secret', 'project', 'template', 'named_port', 'project_port', 'instance', 'job'] as $table) {
            self::assertContains($table, $tables);
        }
    }

    public function testMigratingTwiceIsANoOp(): void
    {
        $this->setUpFleetDatabase();

        [$exit, $output] = $this->console('doctrine:migrations:migrate', ['--no-interaction' => true]);

        $output = (string) preg_replace('/\s+/', ' ', $output); // console output wraps at the terminal width

        self::assertSame(0, $exit);
        self::assertStringContainsString('Already at the latest version', $output);
    }
}
