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
        yield 'project:list' => [['project:list', '--json'], 'bin/console fleet:project:list --json'];
        yield 'two words' => [['project', 'list', '--json'], 'bin/console fleet:project:list --json'];
        yield 'two words with arguments' => [['template', 'show', 'blog', 'web'], 'bin/console fleet:template:show blog web'];
        yield 'two words, port group' => [['port', 'create', 'typesense', '--public=9108', '--router=9108'], 'bin/console fleet:port:create typesense --public=9108 --router=9108'];
        yield 'two words, older group' => [['user', 'reset-password', 'bob'], 'bin/console fleet:user:reset-password bob'];
        yield 'a group alone is not a command' => [['project'], 'bin/console project'];
        yield 'a group and an option' => [['project', '--help'], 'bin/console project --help'];
        yield 'help for a two word command' => [['help', 'project:list'], 'bin/console help project:list'];
        yield 'list a namespace' => [['list', 'fleet'], 'bin/console list fleet'];
        yield 'another namespace keeps its name' => [['doctrine:migrations:status'], 'bin/console doctrine:migrations:status'];
        yield 'another namespace, two words, is not ours' => [['doctrine', 'migrations'], 'bin/console doctrine migrations'];
        yield 'an unknown word pair is not prefixed' => [['widget', 'list'], 'bin/console widget list'];
        yield 'a verb with an uppercase letter is not a verb' => [['project', 'List'], 'bin/console project List'];
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

    public function testInstanceAndJobGroups(): void
    {
        $expected = [
            [['instance:deploy', 'acme'], 'bin/console fleet:instance:deploy acme'],
            [['instance', 'list', '--json'], 'bin/console fleet:instance:list --json'],
            [['instance', 'destroy', 'acme--main', '--yes'], 'bin/console fleet:instance:destroy acme--main --yes'],
            [['job:retry', '7'], 'bin/console fleet:job:retry 7'],
            [['job', 'show', '7'], 'bin/console fleet:job:show 7'],
            // The older groups are still on the list.
            [['tmux', 'list'], 'bin/console fleet:tmux:list'],
            [['secret', 'list', 'acme'], 'bin/console fleet:secret:list acme'],
        ];
        foreach ($expected as [$args, $command]) {
            [$status, $out, $err] = $this->asFleet($args);

            self::assertSame(0, $status, $err);
            self::assertSame('php8.4 /app/'.$command, explode('|', $out)[0], implode(' ', $args));
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function attachCommands(): iterable
    {
        yield 'no verb' => [['tmux'], "tmux -L fleet attach -t =fleet\n"];
        yield 'tmux:attach' => [['tmux:attach'], "tmux -L fleet attach -t =fleet\n"];
        yield 'tmux attach' => [['tmux', 'attach'], "tmux -L fleet attach -t =fleet\n"];
        yield 'an instance window' => [['tmux', 'acme--main'], "tmux -L fleet select-window -t =fleet:=acme--main\ntmux -L fleet attach -t =fleet\n"];
        yield 'an instance window via tmux:attach' => [['tmux:attach', 'acme--fle-9'], "tmux -L fleet select-window -t =fleet:=acme--fle-9\ntmux -L fleet attach -t =fleet\n"];
    }

    /**
     * @param list<string> $args
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attachCommands')]
    public function testTmuxAttachesToTheFleetSession(array $args, string $expectedTmuxCalls): void
    {
        $log = $this->scratch().'/calls.log';
        [$status, , $err] = $this->attach($args, $log, tty: true);

        self::assertSame(0, $status, $err);
        $calls = (string) file_get_contents($log);
        // The session is ensured, quietly, before tmux is run.
        self::assertStringStartsWith("php8.4 /app/bin/console fleet:tmux:ensure -q\n", $calls);
        self::assertSame($expectedTmuxCalls, substr($calls, \strlen("php8.4 /app/bin/console fleet:tmux:ensure -q\n")));
    }

    public function testTmuxVerbsAreNotAttach(): void
    {
        [$status, $out] = $this->asFleet(['tmux', 'list']);

        self::assertSame(0, $status);
        self::assertSame('php8.4 /app/bin/console fleet:tmux:list', explode('|', $out)[0]);
    }

    public function testTmuxWithoutATerminalIsRefused(): void
    {
        foreach ([['tmux'], ['tmux:attach'], ['tmux', 'acme--main']] as $args) {
            $log = $this->scratch().'/calls.log';
            [$status, , $err] = $this->attach($args, $log, tty: false);

            self::assertSame(2, $status, implode(' ', $args));
            self::assertStringContainsString('needs a terminal: use ssh -t', $err);
            self::assertFileDoesNotExist($log, 'nothing runs without a terminal');
        }
    }

    public function testTmuxRefusesAnUnknownWindow(): void
    {
        $log = $this->scratch().'/calls.log';
        [$status, , $err] = $this->attach(['tmux', 'acme--main'], $log, tty: true, selectBody: 'exit 1');

        self::assertSame(1, $status);
        self::assertStringContainsString('instance acme--main has no window', $err);
        self::assertStringNotContainsString('attach', (string) file_get_contents($log));
    }

    public function testTmuxValidatesTheInstanceIdBeforeTheSessionIsEnsured(): void
    {
        foreach ([['tmux', 'attach', 'Bad_Id'], ['tmux:attach', 'no-double-dash'], ['tmux', 'attach', '--x']] as $args) {
            $log = $this->scratch().'/calls.log';
            @unlink($log);
            [$status, , $err] = $this->attach($args, $log, tty: true);

            self::assertSame(2, $status, implode(' ', $args));
            self::assertStringContainsString('is not an instance id', $err);
            self::assertFileDoesNotExist($log, 'neither the ensure step nor tmux runs');
        }
    }

    public function testTmuxRefusesExtraArgumentsWithUsage(): void
    {
        foreach ([['tmux', 'attach', 'acme--main', 'more'], ['tmux:attach', 'acme--main', 'more'], ['tmux', 'acme--main', 'more']] as $args) {
            $log = $this->scratch().'/calls.log';
            @unlink($log);
            [$status, , $err] = $this->attach($args, $log, tty: true);

            self::assertSame(2, $status, implode(' ', $args));
            self::assertStringContainsString('usage: fleet tmux [attach] [<instance-id>]', $err);
            self::assertFileDoesNotExist($log);
        }
    }

    public function testTmuxInsideAnExistingTmuxClientPrintsAHintInsteadOfNesting(): void
    {
        $log = $this->scratch().'/calls.log';
        [$status, , $err] = $this->attach(['tmux', 'acme--main'], $log, tty: true, env: ['TMUX' => '/tmp/tmux-1000/default,1,0']);

        self::assertSame(2, $status);
        self::assertStringContainsString('already inside tmux', $err);
        self::assertFileDoesNotExist($log);
    }

    public function testTmuxDoesNotTreatOtherWordsAsAWindow(): void
    {
        // Not an instance id: goes to the console as it is, and tmux never runs.
        $log = $this->scratch().'/calls.log';
        [$status, $out] = $this->attach(['tmux', 'x; touch /tmp/pwned'], $log, tty: true);

        self::assertSame(0, $status);
        self::assertStringContainsString('bin/console tmux x; touch /tmp/pwned', $out);
        self::assertFileDoesNotExist($log);
    }

    /**
     * Runs the wrapper as the fleet user with stub php8.4 and tmux that log their calls to $log.
     *
     * @param list<string>          $args
     * @param array<string, string> $env  extra environment, e.g. TMUX
     *
     * @return array{int, string, string}
     */
    private function attach(array $args, string $log, bool $tty, string $selectBody = '', array $env = []): array
    {
        $envFile = $this->scratch().'/fleet.env';
        file_put_contents($envFile, "APP_ENV=prod\n");
        $logArg = escapeshellarg($log);
        $stubs = $this->stubs([
            'id' => 'echo fleet',
            'sudo' => 'echo "sudo must not run for the fleet user" >&2; exit 9',
            'php8.4' => 'case "$1 $2" in "/app/bin/console fleet:tmux:ensure") echo "php8.4 $*" >> '.$logArg.';; *) echo "php8.4 $*";; esac',
            'tmux' => 'echo "tmux $*" >> '.$logArg.'; '.('' !== $selectBody ? 'if [ "$3" = select-window ]; then '.$selectBody.'; fi' : ':'),
        ]);

        return $this->wrapper($args, $stubs, $env + [
            'FLEET_WRAPPER_ENV_FILE' => $envFile,
            'FLEET_WRAPPER_CONSOLE' => '/app/bin/console',
            'TMUX' => false, // a developer's own tmux must not leak into the tests
        ] + ($tty ? ['FLEET_WRAPPER_TTY' => '1'] : []));
    }

    public function testLoadsTheApplicationEnvironmentAndHome(): void
    {
        [$status, $out, $err] = $this->asFleet(['setup:token']);

        self::assertSame(0, $status, $err);
        // The stub prints its arguments, then |APP_ENV|MARKER|HOME as seen by the child process.
        self::assertSame('|prod|from-env-file|/home/fleet'."\n", substr($out, (int) strpos($out, '|')));
    }

    public function testTheApplicationEnvironmentIsLoadedForEveryConsoleCommandIncludingTheOnesWeDoNotPrefix(): void
    {
        foreach ([['doctrine:migrations:status'], ['project', 'list'], ['cache:clear']] as $args) {
            [$status, $out, $err] = $this->asFleet($args);

            self::assertSame(0, $status, $err);
            self::assertStringEndsWith('|prod|from-env-file|/home/fleet'."\n", $out, implode(' ', $args));
        }
    }

    public function testAnEnvironmentValueIsNotExecuted(): void
    {
        $envFile = $this->scratch().'/fleet.env';
        $marker = $this->scratch().'/executed';
        file_put_contents($envFile, "APP_ENV=prod\nMARKER='\$(touch ".$marker.")'\n");

        [$status, $out, $err] = $this->asFleet(['project', 'list'], $envFile);

        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($marker);
        self::assertStringContainsString('|prod|$(touch '.$marker.')|', $out);
    }

    public function testArgumentsWithSpacesAndGlobsSurvive(): void
    {
        [$status, $out, $err] = $this->asFleet(['user:reset-password', 'a b', '*']);

        self::assertSame(0, $status, $err);
        self::assertStringStartsWith('php8.4 /app/bin/console fleet:user:reset-password a b *|', $out);
    }

    public function testLeavesTheCallersWorkingDirectory(): void
    {
        $caller = $this->scratch().'/closed-to-fleet';
        mkdir($caller);
        $envFile = $this->scratch().'/fleet.env';
        file_put_contents($envFile, "APP_ENV=prod\n");
        $stubs = $this->stubs(['id' => 'echo fleet', 'sudo' => 'exit 9', 'php8.4' => 'pwd']);

        $process = new Process(['bash', self::installDir().'/bin/fleet', 'setup:token'], $caller, [
            'PATH' => $stubs.':'.(string) getenv('PATH'),
            'FLEET_WRAPPER_ENV_FILE' => $envFile,
            'FLEET_WRAPPER_CONSOLE' => '/app/bin/console',
        ], null, 30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        // The fleet user's home on a real host; the root directory where that does not exist (this test machine).
        self::assertContains(trim($process->getOutput()), ['/home/fleet', '/']);
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
     * @param list<string>                $args
     * @param array<string, string|false> $env
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
