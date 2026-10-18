<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Shell;

use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\ProcessCommandRunner;
use PHPUnit\Framework\TestCase;

final class ProcessCommandRunnerTest extends TestCase
{
    private ?string $lockedDir = null;

    public function testCapturesStdoutStderrAndExitCode(): void
    {
        $result = new ProcessCommandRunner()->run(['sh', '-c', 'echo out; echo err >&2; exit 3']);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertFalse($result->isSuccess());
        self::assertGreaterThanOrEqual(0.0, $result->durationSeconds);
    }

    public function testSuccessfulCommandIsSuccess(): void
    {
        self::assertTrue(new ProcessCommandRunner()->run(['true'])->isSuccess());
    }

    public function testStreamsOutputToCallback(): void
    {
        $seen = [];
        new ProcessCommandRunner()->run(
            ['sh', '-c', 'echo out; echo err >&2'],
            onOutput: static function (string $stream, string $chunk) use (&$seen): void {
                $seen[] = [$stream, $chunk];
            },
        );

        self::assertContains(['stdout', "out\n"], $seen);
        self::assertContains(['stderr', "err\n"], $seen);
    }

    public function testPassesCwdAndEnv(): void
    {
        $dir = sys_get_temp_dir().'/fleet-runner-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $runner = new ProcessCommandRunner();
            self::assertSame(realpath($dir), trim($runner->run(['pwd'], $dir)->stdout));
            self::assertSame('1', $runner->run(['sh', '-c', 'printf %s "$FLEET_X"'], null, ['FLEET_X' => '1'])->stdout);
        } finally {
            rmdir($dir);
        }
    }

    public function testMissingBinaryGivesExit127(): void
    {
        $result = new ProcessCommandRunner()->run(['fleet-definitely-not-a-binary', '--x']);

        self::assertSame(127, $result->exitCode);
        self::assertStringContainsString('fleet-definitely-not-a-binary', $result->stderr);
    }

    public function testMissingCwdThrowsCommandRunnerException(): void
    {
        $this->expectException(CommandRunnerException::class);

        new ProcessCommandRunner()->run(['true'], '/nonexistent/fleet/dir');
    }

    public function testTimeoutThrowsCommandTimedOutWithPartialOutput(): void
    {
        $started = microtime(true);
        try {
            new ProcessCommandRunner()->run(['sh', '-c', 'echo partial; sleep 5'], null, [], null, 0.3);
            self::fail('Expected CommandTimedOut.');
        } catch (CommandTimedOut $e) {
            self::assertSame("partial\n", $e->stdout);
            self::assertSame('', $e->stderr);
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testDoesNotInterpretShellMetacharacters(): void
    {
        $result = new ProcessCommandRunner()->run(['printf', '%s', 'a; echo pwned $(id)']);

        self::assertSame('a; echo pwned $(id)', $result->stdout);
    }

    public function testCapturesOneMebibyteOfOutput(): void
    {
        $result = new ProcessCommandRunner()->run(['sh', '-c', 'head -c 1048576 /dev/zero | tr "\\0" x']);

        self::assertSame(0, $result->exitCode);
        self::assertSame(1048576, \strlen($result->stdout));
    }

    public function testEmptyArgvIsRejected(): void
    {
        $this->expectException(CommandRunnerException::class);

        new ProcessCommandRunner()->run([]);
    }

    public function testChildKilledBySignalGivesExit128PlusSignal(): void
    {
        $result = new ProcessCommandRunner()->run(['sh', '-c', 'kill -TERM $$']);

        self::assertSame(143, $result->exitCode);
        self::assertStringContainsString('signal 15', $result->stderr);
    }

    public function testUnusableCwdThrowsInsteadOfReportingMissingBinary(): void
    {
        $dir = sys_get_temp_dir().'/fleet-runner-noaccess-'.bin2hex(random_bytes(4));
        mkdir($dir);
        chmod($dir, 0o000);
        $this->lockedDir = $dir;

        try {
            $result = new ProcessCommandRunner()->run(['true'], $dir);
        } catch (CommandRunnerException $e) {
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());

            return;
        }
        // Running as a user who can enter the directory anyway (e.g. root): nothing to assert.
        self::assertTrue($result->isSuccess());
    }

    public function testNonExecutableFileGivesExit126(): void
    {
        $file = sys_get_temp_dir().'/fleet-runner-noexec-'.bin2hex(random_bytes(4));
        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o644);
        try {
            self::assertSame(126, new ProcessCommandRunner()->run([$file])->exitCode);
        } finally {
            unlink($file);
        }
    }

    public function testNulByteAndEmptyProgramAreRejected(): void
    {
        $runner = new ProcessCommandRunner();
        foreach ([['echo', "a\0b"], ['']] as $argv) {
            try {
                $runner->run($argv);
                self::fail('Expected CommandRunnerException.');
            } catch (CommandRunnerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testThrowingCallbackStopsTheChild(): void
    {
        $started = microtime(true);
        $caught = null;
        try {
            new ProcessCommandRunner()->run(
                ['sh', '-c', 'echo go; sleep 5'],
                onOutput: static function (): void {
                    throw new \LogicException('callback failed');
                },
            );
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertSame('callback failed', $caught->getMessage());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    protected function tearDown(): void
    {
        if (null !== $this->lockedDir) {
            chmod($this->lockedDir, 0o700);
            rmdir($this->lockedDir);
            $this->lockedDir = null;
        }
    }
}
