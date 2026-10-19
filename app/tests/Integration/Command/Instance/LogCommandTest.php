<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Tests\Support\InstanceCliTestCase;

final class LogCommandTest extends InstanceCliTestCase
{
    public function testPrintsTheLatestJobLog(): void
    {
        $this->command('fleet:instance:deploy', ['project' => 'acme']);

        $tester = $this->command('fleet:instance:log', ['id' => 'acme--develop']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotSame('', trim($tester->getDisplay()));

        self::assertSame(3, $this->command('fleet:instance:log', ['id' => 'acme--develop', '--job' => '999'])->getStatusCode());
        self::assertSame(3, $this->command('fleet:instance:log', ['id' => 'acme--nope'])->getStatusCode());
    }

    public function testFollowStopsWhenTheJobEnds(): void
    {
        $this->command('fleet:instance:deploy', ['project' => 'acme']);

        $tester = $this->command('fleet:instance:log', ['id' => 'acme--develop', '--follow' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotSame('', trim($tester->getDisplay()));
    }
}
