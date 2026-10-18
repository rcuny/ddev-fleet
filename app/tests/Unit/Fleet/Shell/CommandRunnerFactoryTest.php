<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Shell;

use App\Fleet\Shell\CommandRunnerFactory;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use PHPUnit\Framework\TestCase;

final class CommandRunnerFactoryTest extends TestCase
{
    public function testCreatesProcessAndRecording(): void
    {
        $process = new ProcessCommandRunner();
        $recording = new RecordingCommandRunner();
        $factory = new CommandRunnerFactory($process, $recording);

        self::assertSame($process, $factory->create('process'));
        self::assertSame($recording, $factory->create('recording'));
    }

    public function testUnknownKindThrowsListingAllowedValues(): void
    {
        $factory = new CommandRunnerFactory(new ProcessCommandRunner(), new RecordingCommandRunner());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('process, recording');

        $factory->create('shell');
    }
}
