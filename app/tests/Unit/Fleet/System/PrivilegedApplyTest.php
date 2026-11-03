<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\PrivilegedApplyFailed;
use PHPUnit\Framework\TestCase;

final class PrivilegedApplyTest extends TestCase
{
    public function testArgvIsExact(): void
    {
        $runner = new RecordingCommandRunner();
        $apply = new PrivilegedApply($runner);

        $apply->apply(ApplyTarget::Caddy);
        $apply->apply(ApplyTarget::Authelia);

        $calls = $runner->calls();
        self::assertCount(2, $calls);
        self::assertSame(['sudo', '-n', '--', '/usr/local/libexec/fleet/apply', 'caddy'], $calls[0]->argv);
        self::assertSame(['sudo', '-n', '--', '/usr/local/libexec/fleet/apply', 'authelia'], $calls[1]->argv);
        self::assertSame(60.0, $calls[0]->timeout);
        self::assertSame([], $calls[0]->env, 'no environment is passed to the helper');
        self::assertNull($calls[0]->cwd);
    }

    public function testTheOnlyArgumentsAreTheClosedSetOfTargets(): void
    {
        self::assertSame(['caddy', 'authelia'], array_map(static fn (ApplyTarget $t): string => $t->value, ApplyTarget::cases()));
        self::assertSame('/usr/local/libexec/fleet/apply', PrivilegedApply::HELPER);
    }

    public function testFailureCarriesStderrTail(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['sudo'], new CommandResult(3, '', "validate: boom\nCaddyfile:12: unknown directive\n"));

        try {
            new PrivilegedApply($runner)->apply(ApplyTarget::Caddy);
            self::fail('Expected PrivilegedApplyFailed.');
        } catch (PrivilegedApplyFailed $e) {
            self::assertStringContainsString('"caddy"', $e->getMessage());
            self::assertStringContainsString('exit 3', $e->getMessage());
            self::assertStringContainsString('Caddyfile:12: unknown directive', $e->getMessage());
        }
    }

    public function testTheTailIsBoundedAndNeverCarriesCredentialAssignments(): void
    {
        $runner = new RecordingCommandRunner();
        $stderr = str_repeat("noise line\n", 500)."OVH_APPLICATION_SECRET=hunter2hunter2 FLEET_PROXY_SECRET=abcdefabcdef\n";
        $runner->expect(['sudo'], new CommandResult(1, '', $stderr));

        try {
            new PrivilegedApply($runner)->apply(ApplyTarget::Caddy);
            self::fail('Expected PrivilegedApplyFailed.');
        } catch (PrivilegedApplyFailed $e) {
            self::assertLessThan(1000, \strlen($e->getMessage()));
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringNotContainsString('abcdefabcdef', $e->getMessage());
            self::assertStringContainsString('OVH_APPLICATION_SECRET=[redacted]', $e->getMessage());
        }
    }

    public function testMissingSudoersLineIsAReadableFailure(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['sudo'], new CommandResult(1, '', "sudo: a password is required\n"));

        $this->expectException(PrivilegedApplyFailed::class);
        $this->expectExceptionMessage('a password is required');

        new PrivilegedApply($runner)->apply(ApplyTarget::Authelia);
    }

    public function testATimeoutBecomesTheSameFailure(): void
    {
        $runner = new class implements CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandTimedOut('too slow', 'out', 'OVH_CONSUMER_KEY=leaky-value-123');
            }
        };

        try {
            new PrivilegedApply($runner)->apply(ApplyTarget::Caddy);
            self::fail('Expected PrivilegedApplyFailed.');
        } catch (PrivilegedApplyFailed $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertStringNotContainsString('leaky', $e->getMessage());
        }
    }

    public function testARunnerErrorBecomesTheSameFailure(): void
    {
        $runner = new class implements CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandRunnerException('cannot spawn');
            }
        };

        $this->expectException(PrivilegedApplyFailed::class);
        $this->expectExceptionMessage('could not start sudo');

        new PrivilegedApply($runner)->apply(ApplyTarget::Caddy);
    }
}
