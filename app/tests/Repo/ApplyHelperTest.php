<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * install/libexec/apply, the root helper, run unprivileged against a scratch tree (FLEET_APPLY_TEST_ROOT) with stub
 * caddy/authelia/systemctl/systemd-run on PATH. The stubs log every call into $log, so the tests can assert both what
 * ran and in which order. Nothing here touches the real /etc or /srv.
 */
final class ApplyHelperTest extends TestCase
{
    use InstallerHarness;

    private const string SECRET = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';
    private const string OLD_SECRET = 'oldoldoldoldoldoldoldoldoldoldoldoldold1';

    private string $root = '';
    private string $log = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid() && !str_contains($this->name(), 'RealRoot')) {
            self::markTestSkipped('The helper ignores FLEET_APPLY_TEST_ROOT as root, so these unprivileged scratch-tree tests cannot run as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        foreach (['srv/fleet/staging/caddy', 'srv/fleet/staging/authelia', 'etc/caddy', 'etc/authelia/secrets'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o700, true);
        }
        touch($this->log);
    }

    public function testRejectsUnknownTargetAndExtraArgs(): void
    {
        $stubs = $this->defaultStubs();
        foreach ([[], ['bogus'], ['caddy', 'extra'], ['../caddy'], ['caddy '], ['CADDY'], [''], ['caddy', ''], ['-h']] as $args) {
            [$status, , $err] = $this->apply($args, $stubs);
            self::assertSame(64, $status, 'arguments '.json_encode($args).': '.$err);
        }
        self::assertSame('', file_get_contents($this->log), 'no command may run for a refused target');
    }

    public function testRefusesToRunUnprivilegedWithoutTheTestRoot(): void
    {
        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs(), ['FLEET_APPLY_TEST_ROOT' => false]);

        self::assertSame(77, $status, $err);
        self::assertSame('', file_get_contents($this->log));
    }

    public function testRootIgnoresTestRootEnv(): void
    {
        // The structural guarantee, checkable without being root: the variable is read only in the non-root branch,
        // and the root branch pins PATH and the (empty) root prefix.
        $source = (string) file_get_contents(self::installDir().'/libexec/apply');
        $rootBranch = $this->between($source, 'if [ "$EUID" -eq 0 ]; then', "\nelse\n");
        self::assertStringNotContainsString('FLEET_APPLY_TEST_ROOT', $rootBranch, 'the root branch must not read the test variable');
        self::assertStringContainsString('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', $rootBranch);
        self::assertStringContainsString("ROOT=''", $rootBranch);

        $afterBranch = substr($source, (int) strpos($source, "\nelse\n"));
        $reads = 0;
        foreach (self::codeLines(self::installDir().'/libexec/apply') as $line) {
            $reads += substr_count($line, 'FLEET_APPLY_TEST_ROOT');
        }
        self::assertSame($reads, substr_count(preg_replace('/^\s*#.*$/m', '', $afterBranch) ?? '', 'FLEET_APPLY_TEST_ROOT'), 'every read of the variable sits after the non-root branch starts');
    }

    public function testRealRootIgnoresTheTestRootVariable(): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('Needs root: proves the helper does not follow FLEET_APPLY_TEST_ROOT.');
        }
        if (file_exists('/srv/fleet')) {
            self::markTestSkipped('This host has a real /srv/fleet; the helper would use it.');
        }
        $stubs = $this->defaultStubs();
        $this->stage('Caddyfile', "x {\n}\n");
        $this->stage('env', $this->envFile());

        [$status, , $err] = $this->apply(['caddy'], $stubs);

        self::assertSame(66, $status, 'root looks in /srv/fleet/staging, finds nothing: '.$err);
        self::assertSame('', file_get_contents($this->log), 'the staged files of the test root were not used');
    }

    public function testSymlinkedStagingFileIsRefused(): void
    {
        $this->seedLive();
        $outside = $this->scratch().'/outside-caddyfile';
        file_put_contents($outside, "example.com {\n}\n");
        $this->stage('env', $this->envFile());
        symlink($outside, $this->root.'/srv/fleet/staging/caddy/Caddyfile');

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(65, $status, $err);
        self::assertStringContainsString('symlink', $err);
        self::assertSame("LIVE-CADDYFILE\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));
        self::assertSame("example.com {\n}\n", file_get_contents($outside), 'the link target is never touched');
        self::assertSame('', file_get_contents($this->log), 'no validator runs on a refused file');
        self::assertSame([], $this->staged('caddy'), 'the refused files are removed too');
    }

    public function testSymlinkedStagingDirectoryIsRefused(): void
    {
        $this->seedLive();
        $elsewhere = $this->scratch().'/elsewhere';
        mkdir($elsewhere);
        file_put_contents($elsewhere.'/Caddyfile', "x {\n}\n");
        file_put_contents($elsewhere.'/env', $this->envFile());
        rmdir($this->root.'/srv/fleet/staging/caddy');
        symlink($elsewhere, $this->root.'/srv/fleet/staging/caddy');

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(65, $status, $err);
        self::assertFileExists($elsewhere.'/Caddyfile', 'the helper does not delete through a symlinked staging directory');
    }

    public function testOversizedFileIsRefusedAndTheLimitIsInclusive(): void
    {
        $this->seedLive();
        $this->stage('env', $this->envFile());
        $this->stage('Caddyfile', str_repeat('a', 262145));

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());
        self::assertSame(65, $status, $err);
        self::assertStringContainsString('larger than 262144', $err);
        self::assertSame("LIVE-CADDYFILE\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));

        $this->stage('env', $this->envFile());
        $this->stage('Caddyfile', str_repeat('a', 262144));
        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());
        self::assertSame(0, $status, $err);
    }

    public function testEmptyFileAndMissingFilesAreRefused(): void
    {
        $this->seedLive();
        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());
        self::assertSame(66, $status, 'nothing staged: '.$err);

        $this->stage('Caddyfile', '');
        $this->stage('env', $this->envFile());
        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());
        self::assertSame(65, $status, $err);

        $this->stage('Caddyfile', "x {\n}\n");
        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());
        self::assertSame(66, $status, 'the environment file is missing (consumed above): '.$err);
    }

    public function testValidatesTheCopyBeforeInstalling(): void
    {
        $this->seedLive();
        $this->stageCaddy("example.com {\n}\n");

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs(caddyExit: 1));

        self::assertSame(1, $status);
        self::assertStringContainsString('nothing was installed', $err);
        self::assertSame("LIVE-CADDYFILE\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'), 'a failing validation leaves the live Caddyfile untouched');
        self::assertSame('FLEET_PROXY_SECRET='.self::OLD_SECRET."\n", file_get_contents($this->root.'/etc/caddy/fleet.env'));
        self::assertStringNotContainsString('systemctl reload', (string) file_get_contents($this->log));
        self::assertStringNotContainsString(self::SECRET, $err, 'a secret never reaches the output');
        self::assertSame([], $this->staged('caddy'), 'the staged files are consumed even when validation fails');
    }

    public function testValidatorReceivesACopyAndOnlyTheParsedEnvironment(): void
    {
        $this->seedLive();
        $this->stageCaddy("example.com {\n}\n");

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs(), ['LD_PRELOAD' => '/nonexistent/evil.so', 'SECRET_THING' => 'leak']);

        self::assertSame(0, $status, $err);
        $log = (string) file_get_contents($this->log);
        self::assertMatchesRegularExpression('~caddy validate --config (/\S+/fleet-apply\.\w+/Caddyfile) --adapter caddyfile~', $log, 'the validator reads the copy in the work directory');
        self::assertStringNotContainsString('/srv/fleet/staging/caddy/Caddyfile', $log, 'never the staged path');
        self::assertStringContainsString('env FLEET_PROXY_SECRET='.self::SECRET, $log);
        self::assertStringNotContainsString('LD_PRELOAD', $log, 'env -i: the caller environment does not reach the validator');
        self::assertStringNotContainsString('SECRET_THING', $log);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileEnvFiles(): iterable
    {
        $secret = self::SECRET;

        yield 'command substitution' => ['FLEET_PROXY_SECRET='.$secret.'$(touch {MARK})'."\n"];
        yield 'backticks' => ['FLEET_PROXY_SECRET='.$secret.'`touch {MARK}`'."\n"];
        yield 'export prefix' => ['export FLEET_PROXY_SECRET='.$secret."\n"];
        yield 'semicolon' => ['FLEET_PROXY_SECRET='.$secret.'; touch {MARK}'."\n"];
        yield 'quoted value' => ['FLEET_PROXY_SECRET="'.$secret.'"'."\n"];
        yield 'unknown key' => ['FLEET_PROXY_SECRET='.$secret."\nLD_PRELOAD=/tmp/evil.so\n"];
        yield 'path override' => ['FLEET_PROXY_SECRET='.$secret."\nPATH=/tmp\n"];
        yield 'duplicate key' => ['FLEET_PROXY_SECRET='.$secret."\nFLEET_PROXY_SECRET=".$secret."\n"];
        yield 'comment line' => ['# hi'."\nFLEET_PROXY_SECRET=".$secret."\n"];
        yield 'carriage return' => ['FLEET_PROXY_SECRET='.$secret."\r\n"];
        yield 'short secret' => ["FLEET_PROXY_SECRET=short\n"];
        yield 'missing secret' => ["OVH_ENDPOINT=ovh-eu\n"];
        yield 'value with space' => ['FLEET_PROXY_SECRET='.$secret." x\n"];
        yield 'partial ovh' => ['FLEET_PROXY_SECRET='.$secret."\nOVH_ENDPOINT=ovh-eu\n"];
        yield 'bad endpoint' => ['FLEET_PROXY_SECRET='.$secret."\nOVH_ENDPOINT=evil\nOVH_APPLICATION_KEY=abcdefgh\nOVH_APPLICATION_SECRET=abcdefgh\nOVH_CONSUMER_KEY=abcdefgh\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileEnvFiles')]
    public function testTheStagedEnvironmentIsParsedNeverSourced(string $content): void
    {
        $this->seedLive();
        $mark = $this->scratch().'/PWNED';
        $this->stage('Caddyfile', "example.com {\n}\n");
        $this->stage('env', str_replace('{MARK}', $mark, $content));

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(65, $status, $err);
        self::assertFileDoesNotExist($mark);
        self::assertSame('', file_get_contents($this->log), 'a refused environment never reaches a validator');
        self::assertSame("LIVE-CADDYFILE\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));
        self::assertStringNotContainsString(self::SECRET, $err);
    }

    public function testAFullOvhEnvironmentIsAccepted(): void
    {
        $this->seedLive();
        $env = 'FLEET_PROXY_SECRET='.self::SECRET."\nOVH_ENDPOINT=ovh-eu\nOVH_APPLICATION_KEY=abcdefgh12\nOVH_APPLICATION_SECRET=ABCDEFGH12abcdefgh\nOVH_CONSUMER_KEY=zzzzzzzz99\n";
        $this->stage('Caddyfile', "example.com {\n}\n");
        $this->stage('env', $env);

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertSame($env, file_get_contents($this->root.'/etc/caddy/fleet.env'));
        self::assertSame(0o640, fileperms($this->root.'/etc/caddy/fleet.env') & 0o777);
    }

    public function testInstallsBothFilesAndReloads(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");

        [$status, $out, $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('caddy installed', $out);
        self::assertSame("new.example.com {\n}\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));
        self::assertSame(0o644, fileperms($this->root.'/etc/caddy/Caddyfile') & 0o777);
        self::assertSame('FLEET_PROXY_SECRET='.self::SECRET."\n", file_get_contents($this->root.'/etc/caddy/fleet.env'));
        self::assertSame(0o640, fileperms($this->root.'/etc/caddy/fleet.env') & 0o777);
        $log = (string) file_get_contents($this->log);
        self::assertLessThan(strpos($log, 'systemctl reload caddy'), strpos($log, 'caddy validate'), 'validate, then reload');
        self::assertSame([], glob($this->root.'/etc/caddy/*.??????') ?: [], 'no temp files left in /etc/caddy');
    }

    public function testReloadFailureRestoresPreviousConfig(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs(reloadFailures: 1));

        self::assertSame(1, $status);
        self::assertStringContainsString('restored', $err);
        self::assertSame("LIVE-CADDYFILE\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));
        self::assertSame('FLEET_PROXY_SECRET='.self::OLD_SECRET."\n", file_get_contents($this->root.'/etc/caddy/fleet.env'));
        self::assertSame(2, substr_count((string) file_get_contents($this->log), 'systemctl reload caddy'), 'reloaded again after the rollback');
        self::assertStringNotContainsString('systemd-run', (string) file_get_contents($this->log), 'no deferred restart for a rejected config');
        self::assertSame([], $this->staged('caddy'));
    }

    public function testReloadFailureWithoutPreviousFilesRemovesTheNewOnes(): void
    {
        $this->stageCaddy("new.example.com {\n}\n");

        [$status] = $this->apply(['caddy'], $this->defaultStubs(reloadFailures: 1));

        self::assertSame(1, $status);
        self::assertFileDoesNotExist($this->root.'/etc/caddy/Caddyfile');
        self::assertFileDoesNotExist($this->root.'/etc/caddy/fleet.env');
    }

    public function testEnvChangeSchedulesDeferredRestart(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('systemd-run --on-active=3 --quiet systemctl restart caddy', (string) file_get_contents($this->log));
    }

    public function testAFailedRestartScheduleIsReportedNotSwallowed(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");
        $stubs = $this->defaultStubs();
        file_put_contents($stubs.'/systemd-run', "#!/bin/sh\nexit 1\n");

        [$status, , $err] = $this->apply(['caddy'], $stubs);

        self::assertSame(1, $status);
        self::assertStringContainsString('could not be scheduled', $err);
        self::assertSame("new.example.com {\n}\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'), 'the installed files stay: they validated and reloaded');
    }

    public function testUnchangedEnvDoesNotRestart(): void
    {
        file_put_contents($this->root.'/etc/caddy/Caddyfile', "LIVE-CADDYFILE\n");
        file_put_contents($this->root.'/etc/caddy/fleet.env', 'FLEET_PROXY_SECRET='.self::SECRET."\n");
        $this->stageCaddy("new.example.com {\n}\n");

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertStringNotContainsString('systemd-run', (string) file_get_contents($this->log));
        self::assertStringContainsString('systemctl reload caddy', (string) file_get_contents($this->log), 'the Caddyfile is still reloaded');
    }

    public function testStoppedCaddyGetsTheFilesButNoReloadOrRestart(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");

        [$status, $out, $err] = $this->apply(['caddy'], $this->defaultStubs(active: false));

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('caddy is not running', $out);
        self::assertSame("new.example.com {\n}\n", file_get_contents($this->root.'/etc/caddy/Caddyfile'));
        $log = (string) file_get_contents($this->log);
        self::assertStringNotContainsString('systemctl reload', $log);
        self::assertStringNotContainsString('systemd-run', $log);
    }

    public function testStagingConsumedAfterSuccess(): void
    {
        $this->seedLive();
        $this->stageCaddy("new.example.com {\n}\n");
        file_put_contents($this->root.'/srv/fleet/staging/caddy/.env.tmp123', 'leftover of an interrupted write');

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertSame([], $this->staged('caddy'), 'staged files and temp leftovers are deleted');
        self::assertSame([], glob($this->root.'/fleet-apply.*') ?: [], 'the work directory is removed');
    }

    public function testAutheliaTargetValidatesThenRestarts(): void
    {
        file_put_contents($this->root.'/etc/authelia/configuration.yml', "old: true\n");
        $this->stage('configuration.yml', "server:\n  address: 'tcp://127.0.0.1:9091'\n", 'authelia');

        [$status, $out, $err] = $this->apply(['authelia'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('authelia configuration installed', $out);
        self::assertSame("server:\n  address: 'tcp://127.0.0.1:9091'\n", file_get_contents($this->root.'/etc/authelia/configuration.yml'));
        self::assertSame(0o640, fileperms($this->root.'/etc/authelia/configuration.yml') & 0o777);
        $log = (string) file_get_contents($this->log);
        self::assertLessThan(strpos($log, 'systemctl restart authelia'), strpos($log, 'authelia config validate --config'), 'validate, then restart');
        self::assertStringContainsString('AUTHELIA_SESSION_SECRET_FILE='.$this->root.'/etc/authelia/secrets/session_secret', $log, 'the validator gets the secret file variables of the unit');
        self::assertStringContainsString('AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE=', $log);
        self::assertStringContainsString('AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE=', $log);
        self::assertSame([], $this->staged('authelia'));
    }

    public function testAutheliaValidationFailureInstallsNothingAndDoesNotRestart(): void
    {
        file_put_contents($this->root.'/etc/authelia/configuration.yml', "old: true\n");
        $this->stage('configuration.yml', "broken\n", 'authelia');

        [$status, , $err] = $this->apply(['authelia'], $this->defaultStubs(autheliaExit: 1));

        self::assertSame(1, $status);
        self::assertStringContainsString('nothing was installed', $err);
        self::assertSame("old: true\n", file_get_contents($this->root.'/etc/authelia/configuration.yml'));
        self::assertStringNotContainsString('systemctl restart', (string) file_get_contents($this->log));
        self::assertSame([], $this->staged('authelia'));
    }

    public function testAutheliaRestartFailureRestoresThePreviousConfiguration(): void
    {
        file_put_contents($this->root.'/etc/authelia/configuration.yml', "old: true\n");
        $this->stage('configuration.yml', "new: true\n", 'authelia');

        [$status, , $err] = $this->apply(['authelia'], $this->defaultStubs(restartFailures: 1));

        self::assertSame(1, $status);
        self::assertStringContainsString('previous configuration was restored', $err);
        self::assertSame("old: true\n", file_get_contents($this->root.'/etc/authelia/configuration.yml'));
        self::assertSame(2, substr_count((string) file_get_contents($this->log), 'systemctl restart authelia'));
    }

    public function testAutheliaWithNothingStagedOnlyRestarts(): void
    {
        file_put_contents($this->root.'/etc/authelia/configuration.yml', "old: true\n");

        [$status, $out, $err] = $this->apply(['authelia'], $this->defaultStubs());

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('nothing staged, authelia restarted', $out);
        self::assertSame("old: true\n", file_get_contents($this->root.'/etc/authelia/configuration.yml'));
        $log = (string) file_get_contents($this->log);
        self::assertStringNotContainsString('authelia config validate', $log);
        self::assertSame(1, substr_count($log, 'systemctl restart authelia'));
    }

    public function testNothingStagedForCaddyIsRefused(): void
    {
        $this->seedLive();

        [$status, , $err] = $this->apply(['caddy'], $this->defaultStubs());

        self::assertSame(66, $status, $err);
        self::assertSame('', file_get_contents($this->log));
    }

    public function testEveryPathTheHelperUsesIsRelativeToTheRoot(): void
    {
        $allowed = 0;
        foreach (self::codeLines(self::installDir().'/libexec/apply') as $number => $line) {
            if (1 !== preg_match('~(?<![\w.])/(etc|srv|usr|var|opt|home|run)/~', $line)) {
                continue;
            }
            // $ROOT-relative paths, the secret-file constants (re-prefixed with $ROOT where used), the root PATH, /run.
            self::assertMatchesRegularExpression('~\$ROOT/|^AUTHELIA_SECRET_FILES|^readonly AUTHELIA_SECRET_FILES|PATH=/usr/local|\(sudo|fail .usage~', $line, 'line '.$number.' names a literal system path: '.$line);
            ++$allowed;
        }
        self::assertGreaterThanOrEqual(2, $allowed);
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|false> $env
     *
     * @return array{int, string, string}
     */
    private function apply(array $args, string $stubs, array $env = []): array
    {
        $env += ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')];
        $process = new Process(['bash', self::installDir().'/libexec/apply', ...$args], null, $env, null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    private function defaultStubs(int $caddyExit = 0, int $autheliaExit = 0, int $reloadFailures = 0, int $restartFailures = 0, bool $active = true): string
    {
        $log = $this->log;
        $count = $this->scratch().'/count';
        $systemctl = <<<SH
            echo "systemctl \$*" >> {$log}
            case "\$1" in
              is-active) exit {$this->rc($active ? 0 : 3)};;
              reload|restart)
                f={$count}-\$1-\$2; n=\$(cat \$f 2>/dev/null || echo 0); n=\$((n+1)); echo \$n > \$f
                if [ "\$1" = reload ] && [ "\$n" -le {$reloadFailures} ]; then exit 1; fi
                if [ "\$1" = restart ] && [ "\$n" -le {$restartFailures} ]; then exit 1; fi
                exit 0;;
            esac
            exit 0
            SH;

        return $this->stubs([
            'caddy' => "echo \"caddy \$* env \$(env | grep '^FLEET_PROXY_SECRET=\\|^LD_PRELOAD=\\|^SECRET_THING=' | tr '\\n' ' ')\" >> {$log}\nexit {$caddyExit}",
            'authelia' => "echo \"authelia \$* \$(env | grep '^AUTHELIA_' | tr '\\n' ' ')\" >> {$log}\nexit {$autheliaExit}",
            'systemctl' => $systemctl,
            'systemd-run' => "echo \"systemd-run \$*\" >> {$log}\nexit 0",
        ]);
    }

    private function rc(int $rc): string
    {
        return (string) $rc;
    }

    private function envFile(string $secret = self::SECRET): string
    {
        return 'FLEET_PROXY_SECRET='.$secret."\n";
    }

    private function stage(string $name, string $content, string $target = 'caddy'): void
    {
        file_put_contents($this->root.'/srv/fleet/staging/'.$target.'/'.$name, $content);
    }

    private function stageCaddy(string $caddyfile): void
    {
        $this->stage('Caddyfile', $caddyfile);
        $this->stage('env', $this->envFile());
    }

    private function seedLive(): void
    {
        file_put_contents($this->root.'/etc/caddy/Caddyfile', "LIVE-CADDYFILE\n");
        file_put_contents($this->root.'/etc/caddy/fleet.env', $this->envFile(self::OLD_SECRET));
    }

    /** @return list<string> */
    private function staged(string $target): array
    {
        return array_values(array_diff(scandir($this->root.'/srv/fleet/staging/'.$target) ?: [], ['.', '..']));
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($text, $to, $start);
        self::assertNotFalse($end, $to);

        return substr($text, $start, $end - $start);
    }
}
