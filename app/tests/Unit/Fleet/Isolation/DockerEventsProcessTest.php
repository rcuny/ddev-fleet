<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Fleet\Isolation\DockerEventsProcess;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use PHPUnit\Framework\TestCase;

final class DockerEventsProcessTest extends TestCase
{
    public function testLinesSplitAcrossChunksAreHandedOnWhole(): void
    {
        $runner = new class implements CommandRunner {
            /** @var list<list<string>> */
            public array $argv = [];

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $this->argv[] = $argv;
                \assert(null !== $onOutput);
                $onOutput('stdout', "{\"a\":1}\n{\"b\"");
                $onOutput('stderr', 'noise');
                $onOutput('stdout', ":2}\n{\"c\":3}");

                return new CommandResult(0, '', '', 0.0);
            }
        };
        $got = [];
        $source = new DockerEventsProcess($runner);

        self::assertTrue($source->consume(static function (string $lines) use (&$got): void {
            $got[] = $lines;
        }));
        self::assertSame(['{"a":1}', '{"b":2}'], $got, 'the trailing partial line waits for its newline');
        self::assertSame(['docker', 'events', '--format', '{{json .}}'], \array_slice($runner->argv[0], 0, 4));

        $source->consume(static function (): void {
        });
        self::assertSame($runner->argv[0][array_search('--until', $runner->argv[0], true) + 1], $runner->argv[1][array_search('--since', $runner->argv[1], true) + 1], 'the next window starts where the last one ended');
    }

    public function testADockerFailureIsReported(): void
    {
        $runner = new class implements CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                return new CommandResult(1, '', 'Cannot connect', 0.0);
            }
        };

        self::assertFalse(new DockerEventsProcess($runner)->consume(static function (): void {
        }));
    }
}
