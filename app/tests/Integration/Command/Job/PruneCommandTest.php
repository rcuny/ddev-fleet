<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Job;

use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testPrintsTheCountWithTheDefaultRetention(): void
    {
        $tester = $this->cli('fleet:job:prune');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('0', trim($tester->getDisplay()));
    }

    public function testDaysMustBeAWholeNumberInRange(): void
    {
        foreach (['0', 'x', '-1', '3651', '1.5'] as $days) {
            self::assertSame(2, $this->cli('fleet:job:prune', ['--days' => $days])->getStatusCode(), $days);
        }
        self::assertSame(0, $this->cli('fleet:job:prune', ['--days' => '30'])->getStatusCode());
    }
}
