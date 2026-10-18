<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommandRunnerWiringTest extends KernelTestCase
{
    public function testTestEnvironmentWiresTheSharedRecordingRunner(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $runner = $container->get(CommandRunner::class);

        self::assertInstanceOf(RecordingCommandRunner::class, $runner);
        self::assertSame($container->get('fleet.test_alias.recording_command_runner'), $runner);
    }
}
