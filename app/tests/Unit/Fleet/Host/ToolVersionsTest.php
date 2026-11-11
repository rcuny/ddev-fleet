<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\Docker\ToolVersions;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use PHPUnit\Framework\TestCase;

final class ToolVersionsTest extends TestCase
{
    /**
     * @param array<string, CommandResult|\Throwable> $answers keyed by the argv joined with spaces
     */
    private function versions(array $answers): ToolVersions
    {
        return new ToolVersions(new class($answers) implements CommandRunner {
            /** @param array<string, CommandResult|\Throwable> $answers */
            public function __construct(private array $answers)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $answer = $this->answers[implode(' ', $argv)] ?? new CommandResult(127);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        });
    }

    public function testNewEnoughToolsAreOk(): void
    {
        $rows = $this->versions([
            'docker version --format {{.Server.Version}}' => new CommandResult(0, "27.3.1\n"),
            'docker compose version --short' => new CommandResult(0, "v2.29.7\n"),
            'ddev --version' => new CommandResult(0, "ddev version v1.24.3\n"),
        ])->read();

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertTrue($row->ok, $row->label);
        }
        self::assertSame('2.29.7', $rows[1]->version);
        self::assertSame('1.24.3', $rows[2]->version);
    }

    public function testTooOldToolsAreNotOk(): void
    {
        $rows = $this->versions([
            'docker version --format {{.Server.Version}}' => new CommandResult(0, "25.0.5\n"),
            'docker compose version --short' => new CommandResult(0, "2.24.3\n"),
            'ddev --version' => new CommandResult(0, "ddev version v1.23.5\n"),
        ])->read();

        foreach ($rows as $row) {
            self::assertFalse($row->ok, $row->label);
        }
    }

    public function testTheExactMinimumPasses(): void
    {
        $rows = $this->versions([
            'docker version --format {{.Server.Version}}' => new CommandResult(0, "26.0.0\n"),
            'docker compose version --short' => new CommandResult(0, "2.24.4\n"),
            'ddev --version' => new CommandResult(0, "ddev version v1.24.0\n"),
        ])->read();

        foreach ($rows as $row) {
            self::assertTrue($row->ok, $row->label);
        }
    }

    public function testAMissingFailingOrThrowingToolHasNoVersion(): void
    {
        $rows = $this->versions([
            'docker version --format {{.Server.Version}}' => new CommandResult(1, '', 'cannot connect'),
            'ddev --version' => new \RuntimeException('boom'),
        ])->read();

        foreach ($rows as $row) {
            self::assertNull($row->version, $row->label);
            self::assertFalse($row->ok, $row->label);
        }
    }
}
