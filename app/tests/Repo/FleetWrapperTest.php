<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * install/bin/fleet, the `fleet` command, with stub id/sudo/php8.4 on PATH: nothing real is run.
 */
final class FleetWrapperTest extends TestCase
{
    use InstallerHarness;

    public function testReexecsAsFleetViaSudo(): void
    {
        $log = $this->scratch().'/calls.log';
        $stubs = $this->stubs([
            'id' => 'echo alice',
            'sudo' => 'printf "%s\n" "$*" >> '.escapeshellarg($log),
            'php8.4' => 'echo "php must not run before the switch to fleet" >> '.escapeshellarg($log),
        ]);

        [$status, , $err] = $this->wrapper(['setup:token', '--flag', 'a b'], $stubs);

        self::assertSame(0, $status, $err);
        self::assertSame("-u fleet -- /usr/local/bin/fleet setup:token --flag a b\n", file_get_contents($log));
    }

    public function testRootIsSwitchedToFleetToo(): void
    {
        $log = $this->scratch().'/calls.log';
        $stubs = $this->stubs(['id' => 'echo root', 'sudo' => 'printf "%s\n" "$*" >> '.escapeshellarg($log)]);

        $this->wrapper(['setup:reset'], $stubs);

        self::assertSame("-u fleet -- /usr/local/bin/fleet setup:reset\n", file_get_contents($log));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function commands(): iterable
    {
        yield 'group:verb' => [['setup:token'], 'bin/console fleet:setup:token'];
        yield 'with arguments' => [['user:reset-password', 'bob'], 'bin/console fleet:user:reset-password bob'];
        yield 'already prefixed' => [['fleet:host:render', '--gate=auto'], 'bin/console fleet:host:render --gate=auto'];
        yield 'nested verb' => [['host:init', '--domain=fleet.example.com'], 'bin/console fleet:host:init --domain=fleet.example.com'];
        yield 'no arguments lists the fleet commands' => [[], 'bin/console list fleet'];
        yield 'list passes through' => [['list'], 'bin/console list'];
        yield 'an option passes through' => [['--version'], 'bin/console --version'];
        yield 'help passes through' => [['help', 'setup:token'], 'bin/console help setup:token'];
    }

    /**
     * @param list<string> $args
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('commands')]
    public function testMapsGroupVerbToConsoleCommand(array $args, string $expected): void
    {
        [$status, $out, $err] = $this->asFleet($args);

        self::assertSame(0, $status, $err);
        self::assertSame('php8.4 /app/'.$expected, explode('|', $out)[0], $out);
    }

    public function testLoadsTheApplicationEnvironmentAndHome(): void
    {
        [$status, $out, $err] = $this->asFleet(['setup:token']);

        self::assertSame(0, $status, $err);
        // The stub prints its arguments, then |APP_ENV|MARKER|HOME as seen by the child process.
        self::assertSame('|prod|from-env-file|/home/fleet'."\n", substr($out, (int) strpos($out, '|')));
    }

    public function testArgumentsWithSpacesAndGlobsSurvive(): void
    {
        [$status, $out, $err] = $this->asFleet(['user:reset-password', 'a b', '*']);

        self::assertSame(0, $status, $err);
        self::assertStringStartsWith('php8.4 /app/bin/console fleet:user:reset-password a b *|', $out);
    }

    public function testFailsClearlyWithoutTheEnvironmentFile(): void
    {
        [$status, , $err] = $this->asFleet(['setup:token'], envFile: $this->scratch().'/missing.env');

        self::assertSame(1, $status);
        self::assertStringContainsString('cannot read', $err);
    }

    public function testPropagatesTheConsoleExitStatus(): void
    {
        [$status] = $this->asFleet(['setup:token'], phpBody: 'exit 3');

        self::assertSame(3, $status);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function wrapper(array $args, string $stubs, array $env = []): array
    {
        $process = new Process(['bash', self::installDir().'/bin/fleet', ...$args], null, $env + ['PATH' => $stubs.':'.(string) getenv('PATH')], null, 30);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /**
     * Runs the wrapper as if already the fleet user (the id stub says so) with a stub php8.4 that echoes its arguments.
     *
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function asFleet(array $args, ?string $envFile = null, string $phpBody = ''): array
    {
        if (null === $envFile) {
            $envFile = $this->scratch().'/fleet.env';
            file_put_contents($envFile, "APP_ENV=prod\nMARKER=from-env-file\n");
        }
        $stubs = $this->stubs([
            'id' => 'echo fleet',
            'sudo' => 'echo "sudo must not run for the fleet user" >&2; exit 9',
            'php8.4' => '' !== $phpBody ? $phpBody : 'printf "%s|%s|%s|%s\n" "php8.4 $*" "$APP_ENV" "$MARKER" "$HOME"',
        ]);

        return $this->wrapper($args, $stubs, [
            'FLEET_WRAPPER_ENV_FILE' => $envFile,
            'FLEET_WRAPPER_CONSOLE' => '/app/bin/console',
        ]);
    }
}
