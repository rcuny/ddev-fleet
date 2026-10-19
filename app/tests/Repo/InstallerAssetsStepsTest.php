<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Steps 70 to 98 (env, code, app init, privileged files, units, render and start): the order and values of the dry-run
 * plan, and step 98's restart decisions and health wait run for real against stub systemctl/curl/runuser.
 */
final class InstallerAssetsStepsTest extends TestCase
{
    use InstallerHarness;

    public function testThereAreTwelveStepsAndTheDryRunListsThemInOrder(): void
    {
        [$status, $out, $err] = $this->dryRun();

        self::assertSame(0, $status, $err);
        self::assertSame(12, preg_match_all('/^==> /m', $out));
        preg_match_all('/^==> (\S+)$/m', $out, $names);
        self::assertSame(['10-packages', '20-docker', '30-fleet-user', '40-ddev', '50-caddy', '60-authelia', '70-env', '80-code', '85-app-init', '90-privileged', '95-units', '98-render-start'], $names[1]);
    }

    public function testCodeStepRunsComposerAndTheAssetPipelineAsFleetInOrder(): void
    {
        [, $out] = $this->dryRun();
        $section = $this->section($out, '80-code');

        $lines = [
            'php8.4 /usr/bin/composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader',
            'php8.4 bin/console importmap:install --no-interaction',
            'php8.4 bin/console tailwind:build --minify --no-interaction',
            'php8.4 bin/console asset-map:compile --no-interaction',
            'php8.4 bin/console cache:clear --no-interaction',
            'php8.4 bin/console cache:warmup --no-interaction',
        ];
        $previous = -1;
        foreach ($lines as $line) {
            $at = strpos($section, '+ as fleet, in /opt/ddev-fleet/app with /etc/ddev-fleet/fleet.env loaded: '.$line);
            self::assertNotFalse($at, $line);
            self::assertGreaterThan($previous, $at, 'order: '.$line);
            $previous = $at;
        }
    }

    public function testCodeAndAppStepsUseTheSourceDirectoryTheBootstrapExported(): void
    {
        [, $out] = $this->dryRun(null, ['FLEET_SRC_DIR' => '/srv/checkout']);

        foreach (['80-code', '85-app-init', '98-render-start'] as $step) {
            $section = $this->section($out, $step);
            self::assertStringContainsString('in /srv/checkout/app with', $section, $step);
            self::assertStringNotContainsString('/opt/ddev-fleet/app', $section, $step);
        }
    }

    public function testAppInitMigratesSetsUpTheQueueAndInitialisesTheHost(): void
    {
        [, $out] = $this->dryRun();
        $section = $this->section($out, '85-app-init');

        $migrate = $this->position($section, 'bin/console doctrine:migrations:migrate --no-interaction');
        $queue = $this->position($section, 'bin/console messenger:setup-transports --no-interaction');
        $init = $this->position($section, 'bin/console fleet:host:init --domain=fleet.example.com');
        self::assertLessThan($queue, $migrate);
        self::assertLessThan($init, $queue);
        self::assertStringNotContainsString('--acme-email', $section);

        [, $withEmail] = $this->dryRun(['--domain=fleet.example.com', '--acme-email=ops@example.com', '--skip-dns-check']);
        self::assertStringContainsString('fleet:host:init --domain=fleet.example.com --acme-email=ops@example.com', $withEmail);
    }

    public function testPrivilegedStepInstallsFromTheRootOwnedExportAndChecksSudoersFirst(): void
    {
        [, $out] = $this->dryRun();
        $section = $this->section($out, '90-privileged');

        $check = $this->position($section, '+ visudo -cf ');
        $sudoers = $this->position($section, '+ write /etc/sudoers.d/fleet-apply 0440 root:root');
        self::assertLessThan($sudoers, $check, 'visudo before the file lands');
        self::assertStringContainsString('+ dir /usr/local/libexec/fleet 0755 root:root', $section);
        self::assertStringContainsString('+ write /usr/local/libexec/fleet/apply 0755 root:root', $section);
        self::assertStringContainsString('+ write /usr/local/bin/fleet 0755 root:root', $section);

        $step = (string) file_get_contents(self::installDir().'/steps/90-privileged.sh');
        self::assertStringContainsString('<"$INSTALL_DIR/libexec/apply"', $step, 'from the root-owned export');
        self::assertStringNotContainsString('FLEET_SRC_DIR', $step, 'never from the fleet-writable checkout');
        self::assertStringNotContainsString('FLEET_OPT_DIR', $step);
    }

    public function testSudoersIsRefusedWhenItDoesNotParse(): void
    {
        $etc = $this->scratch().'/sudoers.d';
        mkdir($etc);
        $stubs = $this->stubs(['visudo' => 'exit 1', 'chown' => 'exit 0']);

        [$status, , $err] = $this->withCommon(
            '. "$INSTALL_DIR/steps/90-privileged.sh"; SUDOERS_DIR='.escapeshellarg($etc).'; install_sudoers',
            [],
            $stubs,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('does not parse', $err);
        self::assertSame([], glob($etc.'/*') ?: [], 'nothing is installed');
    }

    public function testUnitsStepPlan(): void
    {
        [, $out] = $this->dryRun();
        $section = $this->section($out, '95-units');

        foreach ([
            '+ write /etc/php/8.4/fpm/pool.d/fleet.conf 0644 root:root',
            '+ mv -f /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled',
            '+ write /etc/php/8.4/fpm/conf.d/90-fleet.ini 0644 root:root',
            '+ write /etc/systemd/system/php8.4-fpm.service.d/fleet-env.conf 0644 root:root',
            '+ php-fpm8.4 --test',
            '+ write /etc/systemd/system/fleet-worker@.service 0644 root:root',
            '+ write /etc/fleet-mercure/Caddyfile 0644 root:root',
            '+ write /etc/systemd/system/fleet-mercure.service 0644 root:root',
            '+ systemctl daemon-reload',
        ] as $line) {
            self::assertStringContainsString($line, $section);
        }
        self::assertLessThan($this->position($section, 'php-fpm8.4 --test'), $this->position($section, 'www.conf.disabled'), 'the pool is validated after the stock one is disabled');
        self::assertLessThan($this->position($section, '/etc/fleet-mercure/Caddyfile'), $this->position($section, 'mercure validate'), 'the hub configuration is validated before it is installed');
        self::assertStringNotContainsString('systemctl start', $section);
        self::assertStringNotContainsString('systemctl restart', $section, 'services are started by step 98');
        self::assertStringNotContainsString('enable', $section);
    }

    public function testMercureIsPinnedAndChecksumVerifiedForBothArchitectures(): void
    {
        $step = (string) file_get_contents(self::installDir().'/steps/95-units.sh');

        self::assertStringContainsString('MERCURE_VERSION=1.1.2', $step);
        self::assertStringContainsString('MERCURE_SHA256_AMD64=64d67a1189c96c0131229e309679695086eca0d4cff0d14f9fef94f109438e30', $step);
        self::assertStringContainsString('MERCURE_SHA256_ARM64=60a016f6e838c888f76081944e0607500e8d9f1eb280db6812838714e67a3abb', $step);
        self::assertStringContainsString('https://github.com/dunglas/mercure/releases/download/v$MERCURE_VERSION/mercure_Linux_$asset.tar.gz', $step);
        self::assertStringContainsString('asset=x86_64', $step);
        self::assertStringContainsString('asset=arm64', $step);

        // The check comes before the extraction.
        self::assertLessThan(strpos($step, 'tar -xzf "$tmp/mercure.tar.gz"'), strpos($step, 'sha256sum -c'));

        $stubs = $this->stubs(['uname' => 'echo x86_64']);
        [, $amd64] = $this->dryRun(null, [], $stubs);
        self::assertStringContainsString('mercure_Linux_x86_64.tar.gz', $amd64);
        self::assertStringContainsString('64d67a1189c96c0131229e309679695086eca0d4cff0d14f9fef94f109438e30  mercure.tar.gz', $amd64);
    }

    public function testMercureDownloadIsRefusedOnAChecksumMismatch(): void
    {
        $bin = $this->scratch().'/mercure';
        $marker = $this->scratch().'/marker';
        $stubs = $this->stubs([
            // fetch() calls: curl ... -o <file> <url>
            'curl' => 'while [ $# -gt 0 ]; do if [ "$1" = -o ]; then printf "not the hub" > "$2"; fi; shift; done',
            'chown' => 'exit 0',
            'install' => 'exit 99',
        ]);

        [$status, , $err] = $this->withCommon(
            '. "$INSTALL_DIR/steps/95-units.sh"; MERCURE_BIN='.escapeshellarg($bin).'; MERCURE_MARKER='.escapeshellarg($marker).'; install_mercure',
            [],
            $stubs,
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('does not match the pinned sha256', $err);
        self::assertFileDoesNotExist($bin);
        self::assertFileDoesNotExist($marker);
    }

    public function testMercureIsInstalledWhenTheChecksumMatchesAndNotDownloadedAgain(): void
    {
        $scratch = $this->scratch();
        mkdir($scratch.'/pack');
        file_put_contents($scratch.'/pack/mercure', "#!/bin/sh\necho hub\n");
        chmod($scratch.'/pack/mercure', 0o755);
        new \Symfony\Component\Process\Process(['tar', '-czf', $scratch.'/hub.tar.gz', '-C', $scratch.'/pack', 'mercure'])->mustRun();
        $sha = hash_file('sha256', $scratch.'/hub.tar.gz');
        $fetches = $scratch.'/fetches.log';
        $stubs = $this->stubs([
            'curl' => 'echo fetch >> '.escapeshellarg($fetches).'; while [ $# -gt 0 ]; do if [ "$1" = -o ]; then cp '.escapeshellarg($scratch.'/hub.tar.gz').' "$2"; fi; shift; done',
            'chown' => 'exit 0',
            // install -m MODE -o root -g root SRC DEST, without the ownership flags an unprivileged user cannot set
            'install' => 'for last; do :; done; src=; for a; do case "$a" in -*|root|0*) ;; *) src=$a; break;; esac; done; cp "$src" "$last"; chmod 0755 "$last"',
        ]);
        $code = '. "$INSTALL_DIR/steps/95-units.sh"; MERCURE_SHA256_AMD64='.$sha.'; MERCURE_SHA256_ARM64='.$sha.'; '.
            'MERCURE_BIN='.escapeshellarg($scratch.'/bin-mercure').'; MERCURE_MARKER_DIR='.escapeshellarg($scratch.'/lib').'; MERCURE_MARKER='.escapeshellarg($scratch.'/lib/mercure.version').'; '.
            'install_mercure; echo "changed=$MERCURE_CHANGED"; MERCURE_CHANGED=0; install_mercure; echo "changed-again=$MERCURE_CHANGED"';

        [$status, $out, $err] = $this->withCommon($code, [], $stubs);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString("changed=1\n", $out);
        self::assertStringContainsString("changed-again=0\n", $out, 'the pinned version is already there');
        self::assertSame("#!/bin/sh\necho hub\n", file_get_contents($scratch.'/bin-mercure'));
        self::assertSame('1.1.2 '.$sha."\n", file_get_contents($scratch.'/lib/mercure.version'));
        self::assertSame("fetch\n", file_get_contents($fetches), 'downloaded once');
    }

    public function testRenderStartOrder(): void
    {
        [, $out] = $this->dryRun();
        $section = $this->section($out, '98-render-start');

        $order = [
            'fleet:host:render --gate=auto --no-reload',
            '+ /usr/local/libexec/fleet/apply authelia',
            '+ /usr/local/libexec/fleet/apply caddy',
            '+ systemctl enable authelia php8.4-fpm fleet-mercure fleet-worker@1 caddy',
            '+ systemctl restart php8.4-fpm',
            '+ systemctl restart fleet-mercure',
            '+ systemctl restart fleet-worker@1.service',
            '+ systemctl restart caddy',
            '+ wait up to 120 s: curl -fsS https://fleet.example.com/health, curl -fsS http://127.0.0.1:3000/healthz, systemctl is-active docker caddy authelia php8.4-fpm fleet-mercure fleet-worker@1',
            'fleet:setup:token',
        ];
        $previous = -1;
        foreach ($order as $needle) {
            $at = strpos($section, $needle);
            self::assertNotFalse($at, $needle);
            self::assertGreaterThan($previous, $at, $needle);
            $previous = $at;
        }
        self::assertStringNotContainsString("\n+ systemctl reload", $section);
    }

    /**
     * @return iterable<string, array{array<string, int>, list<string>, list<string>}>
     */
    public static function restartScenarios(): iterable
    {
        $none = ['CODE_CHANGED' => 0, 'ENV_CHANGED' => 0, 'FPM_CHANGED' => 0, 'WORKER_UNIT_CHANGED' => 0, 'MERCURE_CHANGED' => 0];

        yield 'nothing changed: services are only started, Caddy is always restarted' => [
            $none,
            ['systemctl start php8.4-fpm', 'systemctl start fleet-mercure', 'systemctl start authelia', 'systemctl start fleet-worker@1', 'systemctl restart caddy'],
            ['systemctl restart php8.4-fpm', 'reload-or-restart', 'systemctl restart fleet-mercure', 'systemctl restart fleet-worker'],
        ];
        yield 'new code: fpm reloaded, workers restarted' => [
            ['CODE_CHANGED' => 1] + $none,
            ['systemctl reload-or-restart php8.4-fpm', 'systemctl restart fleet-worker@1.service', 'systemctl restart fleet-worker@2.service', 'systemctl start fleet-mercure'],
            ['systemctl restart php8.4-fpm', 'systemctl restart fleet-mercure'],
        ];
        yield 'new environment: fpm restarted (EnvironmentFile is read at start), workers restarted' => [
            ['ENV_CHANGED' => 1] + $none,
            ['systemctl restart php8.4-fpm', 'systemctl restart fleet-worker@1.service'],
            ['reload-or-restart'],
        ];
        yield 'new pool or ini: fpm restarted' => [
            ['FPM_CHANGED' => 1] + $none,
            ['systemctl restart php8.4-fpm', 'systemctl start fleet-worker@1'],
            ['systemctl restart fleet-worker'],
        ];
        yield 'new worker unit: workers restarted' => [
            ['WORKER_UNIT_CHANGED' => 1] + $none,
            ['systemctl restart fleet-worker@1.service'],
            ['systemctl restart php8.4-fpm'],
        ];
        yield 'new hub: restarted' => [
            ['MERCURE_CHANGED' => 1] + $none,
            ['systemctl restart fleet-mercure'],
            ['systemctl restart php8.4-fpm', 'systemctl restart fleet-worker'],
        ];
    }

    /**
     * @param array<string, int> $flags
     * @param list<string>       $expected
     * @param list<string>       $absent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('restartScenarios')]
    public function testRestartDecisions(array $flags, array $expected, array $absent): void
    {
        [$status, $calls, $err] = $this->runRenderStart($flags);

        self::assertSame(0, $status, $err);
        foreach ($expected as $call) {
            self::assertStringContainsString($call."\n", $calls, $call);
        }
        foreach ($absent as $call) {
            self::assertStringNotContainsString($call, $calls, $call);
        }
        self::assertLessThan(strpos($calls, 'systemctl restart caddy'), strpos($calls, 'apply caddy'), 'the helper installs before Caddy restarts');
    }

    public function testRenderStartRecordsTheInstalledRevisionOnlyWhenHealthy(): void
    {
        $etc = $this->scratch().'/etc';
        [$status, , $err] = $this->runRenderStart([], extra: 'CODE_REVISION=abc123;', etc: $etc);
        self::assertSame(0, $status, $err);
        self::assertSame("abc123\n", file_get_contents($etc.'/installed-revision'));

        unlink($etc.'/installed-revision');
        [$status] = $this->runRenderStart([], extra: 'CODE_REVISION=abc123;', etc: $etc, curlExit: 22, timeout: '0');
        self::assertSame(1, $status);
        self::assertFileDoesNotExist($etc.'/installed-revision', 'a failed install is retried as a code change');
    }

    public function testAnUnhealthyInstallFailsWithAJournalHint(): void
    {
        [$status, , $err] = $this->runRenderStart([], curlExit: 22, timeout: '0');

        self::assertSame(1, $status);
        self::assertStringContainsString('did not become healthy within 0s', $err);
        self::assertStringContainsString('journalctl -u caddy', $err);
        self::assertStringContainsString('https://fleet.example.com/health does not answer', $err);
        self::assertStringContainsString('the Mercure hub does not answer', $err);
    }

    public function testAUnitThatIsNotActiveIsNamed(): void
    {
        [$status, , $err] = $this->runRenderStart([], inactive: 'authelia', timeout: '0');

        self::assertSame(1, $status);
        self::assertStringContainsString('authelia is not active', $err);
        self::assertStringNotContainsString('caddy is not active', $err);
    }

    public function testHealthChecksUseVerifiedTlsAndLoopback(): void
    {
        $step = (string) file_get_contents(self::installDir().'/steps/98-render-start.sh');

        self::assertStringContainsString('curl -fsS --max-time 10 -o /dev/null "https://$DOMAIN/health"', $step);
        self::assertStringContainsString('http://127.0.0.1:3000/healthz', $step);
        self::assertStringNotContainsString('--insecure', $step);
        self::assertDoesNotMatchRegularExpression('/curl\s+[^\n]*\s-k\b/', $step);
    }

    /**
     * Runs step 98 for real with stub systemctl/curl/runuser and a stub apply helper.
     *
     * @param array<string, int> $flags the change flags to start with
     *
     * @return array{int, string, string} exit code, the recorded calls, stderr
     */
    private function runRenderStart(array $flags, string $extra = '', ?string $etc = null, int $curlExit = 0, string $timeout = '5', string $inactive = ''): array
    {
        $calls = $this->scratch().'/calls.log';
        file_put_contents($calls, '');
        $helperDir = $this->scratch().'/helper';
        @mkdir($helperDir);
        file_put_contents($helperDir.'/apply', "#!/bin/sh\necho \"apply \$*\" >> ".escapeshellarg($calls)."\n");
        chmod($helperDir.'/apply', 0o755);
        $etc ??= $this->scratch().'/etc';
        @mkdir($etc, 0o750, true);

        $stubs = $this->stubs([
            'systemctl' => 'echo "systemctl $*" >> '.escapeshellarg($calls).
                "\ncase \"\$1\" in\n  is-active) [ \"\$3\" = ".escapeshellarg($inactive)." ] && exit 3; exit 0;;\n  list-units) echo 'fleet-worker@1.service loaded active running w'; echo 'fleet-worker@2.service loaded active running w';;\nesac\nexit 0",
            'curl' => 'exit '.$curlExit,
            'runuser' => 'echo "runuser $*" | cut -c1-200 >> '.escapeshellarg($calls).'; echo https://fleet.example.com/setup?token=T',
            'chown' => 'exit 0',
            'sleep' => 'exit 0',
        ]);
        $assign = '';
        foreach ($flags as $name => $value) {
            $assign .= $name.'='.$value.'; ';
        }
        $code = '. "$INSTALL_DIR/steps/98-render-start.sh"; FLEET_DRY_RUN=0; DOMAIN=fleet.example.com; SETUP_COMPLETE=1; '.
            'HELPER_DIR='.escapeshellarg($helperDir).'; FLEET_ETC_DIR='.escapeshellarg($etc).'; '.$assign.$extra.' step_main';

        [$status, , $err] = $this->withCommon($code, ['FLEET_INSTALL_HEALTH_TIMEOUT' => $timeout], $stubs);

        return [$status, (string) file_get_contents($calls), $err];
    }

    private function section(string $plan, string $step): string
    {
        $start = strpos($plan, '==> '.$step."\n");
        self::assertNotFalse($start, $step);
        $next = strpos($plan, "\n==> ", $start + 1);

        return substr($plan, $start, false === $next ? null : $next - $start);
    }

    private function position(string $haystack, string $needle): int
    {
        $position = strpos($haystack, $needle);
        self::assertNotFalse($position, 'the plan has no line containing: '.$needle);

        return $position;
    }
}
