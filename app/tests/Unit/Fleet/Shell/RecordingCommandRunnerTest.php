<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Shell;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use PHPUnit\Framework\TestCase;

final class RecordingCommandRunnerTest extends TestCase
{
    public function testUnscriptedCallSucceedsAndIsRecorded(): void
    {
        $runner = new RecordingCommandRunner();

        $result = $runner->run(['ddev', 'list']);

        self::assertSame(0, $result->exitCode);
        self::assertCount(1, $runner->calls());
    }

    public function testRecordsArgvCwdEnvAndTimeout(): void
    {
        $runner = new RecordingCommandRunner();

        $runner->run(['git', 'status'], '/srv/x', ['A' => 'b'], null, 12.5);

        $call = $runner->calls()[0];
        self::assertSame(['git', 'status'], $call->argv);
        self::assertSame('/srv/x', $call->cwd);
        self::assertSame(['A' => 'b'], $call->env);
        self::assertSame(12.5, $call->timeout);
    }

    public function testExpectMatchesByPrefixAndIsConsumedOnce(): void
    {
        $runner = new RecordingCommandRunner()
            ->expect(['git', 'fetch'], new CommandResult(1, '', 'boom'))
            ->expect(['git', 'fetch'], new CommandResult(0, 'second'));

        self::assertSame(0, $runner->run(['ddev', 'start'])->exitCode, 'unmatched call');
        self::assertSame(1, $runner->run(['git', 'fetch', 'origin'])->exitCode);
        self::assertSame('second', $runner->run(['git', 'fetch'])->stdout);
        self::assertSame('', $runner->run(['git', 'fetch'])->stdout, 'rules are consumed');
        self::assertCount(3, $runner->callsMatching(['git', 'fetch']));
        self::assertCount(1, $runner->callsMatching(['ddev']));
    }

    public function testReplaysScriptedStdoutToCallbackLineByLine(): void
    {
        $runner = new RecordingCommandRunner()
            ->expect(['x'], new CommandResult(0, "a\nb\n", "e\n"));
        $seen = [];

        $runner->run(['x'], onOutput: static function (string $stream, string $chunk) use (&$seen): void {
            $seen[] = [$stream, $chunk];
        });

        self::assertSame([['stdout', "a\n"], ['stdout', "b\n"], ['stderr', "e\n"]], $seen);
    }

    public function testResetClearsCallsAndRules(): void
    {
        $runner = new RecordingCommandRunner()->expect(['x'], new CommandResult(9));
        $runner->run(['y']);

        $runner->reset();

        self::assertSame([], $runner->calls());
        self::assertSame(0, $runner->run(['x'])->exitCode);
    }
}
