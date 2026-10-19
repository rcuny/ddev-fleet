<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The base steps (user, DDEV, Caddy, Authelia): the order and values of the dry-run plan, and the shell functions that
 * carry the idempotency guards, run for real against a scratch directory with chown/curl stubbed out.
 */
final class InstallerStepsTest extends TestCase
{
    use InstallerHarness;

    public function testFleetUserAndDirectories(): void
    {
        [, $out] = $this->dryRun();

        foreach ([
            '+ useradd --system --create-home --home-dir /home/fleet --shell /bin/bash --user-group fleet',
            '+ usermod -aG docker fleet',
            '+ dir /srv/fleet 0750 fleet:fleet',
            '+ dir /srv/fleet/var 0750 fleet:fleet',
            '+ dir /srv/fleet/staging 0700 fleet:fleet',
            '+ dir /etc/ddev-fleet 0750 root:fleet',
            '+ chown -R fleet:fleet /opt/ddev-fleet',
            '+ systemctl enable --now docker.service',
        ] as $line) {
            self::assertStringContainsString($line."\n", $out);
        }
    }

    public function testDdevIsConfiguredAsTheFleetUser(): void
    {
        [, $out] = $this->dryRun();

        self::assertStringContainsString(
            '+ runuser -u fleet -- env HOME=/home/fleet DDEV_NONINTERACTIVE=true ddev config global --router-http-port=8080 --router-https-port=8443 --instrumentation-opt-in=false',
            $out,
        );
        self::assertStringContainsString('TRUST_STORES=nss mkcert -install', $out);
    }

    public function testCaddyPlan(): void
    {
        [, $out] = $this->dryRun();

        $divert = $this->position($out, '+ dpkg-divert --divert /usr/bin/caddy.default --rename /usr/bin/caddy');
        $download = $this->position($out, '+ fetch https://caddyserver.com/api/download?os=linux&arch=');
        $alternatives = $this->position($out, '+ update-alternatives --install /usr/bin/caddy caddy /usr/bin/caddy.custom 50');
        self::assertLessThan($download, $divert);
        self::assertLessThan($alternatives, $download);
        self::assertStringContainsString('p=github.com%2Fcaddy-dns%2Fovh', $out);
        self::assertStringContainsString('+ update-alternatives --install /usr/bin/caddy caddy /usr/bin/caddy.default 10', $out);
        self::assertStringContainsString('/etc/systemd/system/caddy.service.d/10-fleet-sandbox.conf', $out);
        self::assertStringContainsString('/etc/systemd/system/caddy.service.d/20-fleet-env.conf', $out);
        foreach (['/etc/caddy/fleet', '/etc/caddy/fleet/instances', '/etc/caddy/fleet/ports'] as $dir) {
            self::assertStringContainsString('+ dir '.$dir.' 2750 fleet:caddy'."\n", $out, 'snippet directories need the setgid bit');
        }
        // The package binary is never replaced: no write to /usr/bin/caddy itself.
        self::assertStringNotContainsString('mv /usr/bin/caddy.custom.new /usr/bin/caddy ', $out);
    }

    public function testAutheliaPlanMasksTmpfilesBeforeThePackageAndGuardsEverySecret(): void
    {
        [, $out] = $this->dryRun();

        $mask = $this->position($out, '+ write /etc/tmpfiles.d/authelia.conf 0644 root:root');
        $install = $this->position($out, ' DPkg::Lock::Timeout=120 install authelia');
        $firstSecret = $this->position($out, '+ secret /etc/authelia/secrets/session_secret');
        $users = $this->position($out, '+ write /srv/fleet/authelia/users.yml 0640 fleet:authelia');
        $assertion = $this->position($out, '+ assert no default account');
        self::assertLessThan($install, $mask, 'the tmpfiles mask must exist before the package is installed');
        self::assertLessThan($firstSecret, $install);
        self::assertLessThan($users, $firstSecret);
        self::assertLessThan($assertion, $users);

        foreach (['session_secret', 'storage_encryption_key', 'reset_password_jwt_secret'] as $secret) {
            self::assertStringContainsString('+ secret /etc/authelia/secrets/'.$secret.' (openssl rand -hex 32, only when missing) 0640 root:authelia', $out);
        }
        self::assertStringContainsString('+ dir /etc/authelia/secrets 0750 root:authelia', $out);
        self::assertStringContainsString('+ dir /srv/fleet/authelia 2750 fleet:authelia', $out, 'users.yml directory: setgid, Authelia group');
        self::assertStringContainsString('+ setfacl -m u:authelia:x /srv/fleet', $out);
        self::assertStringContainsString('+ dir /var/lib/authelia 0750 authelia:authelia', $out);
        self::assertStringContainsString('/etc/systemd/system/authelia.service.d/secrets.conf', $out);
        self::assertStringContainsString('+ systemd-tmpfiles --create --prefix=/etc/authelia', $out);
    }

    public function testAutheliaFilesAndGroupAreNotHardcodedOutsideTheDryRunPlaceholder(): void
    {
        $step = (string) file_get_contents(self::installDir().'/steps/60-authelia.sh');

        self::assertStringContainsString('systemctl show -p User --value authelia.service', $step);
        self::assertStringContainsString('systemctl show -p Group --value authelia.service', $step);
        self::assertStringNotContainsString('useradd', $step, 'the Authelia account belongs to the package');
        self::assertStringNotContainsString('groupadd', $step);
    }

    public function testTmpfilesMaskKeepsOnlyTheDirectoryLine(): void
    {
        $lines = array_values(array_filter(
            file(self::installDir().'/etc/authelia-tmpfiles.conf', \FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $l): bool => '' !== trim($l) && !str_starts_with(ltrim($l), '#'),
        ));

        self::assertSame(['e /etc/authelia 0755 authelia authelia -'], $lines);
    }

    public function testAutheliaSecretsDropInNamesTheThreeFiles(): void
    {
        $conf = (string) file_get_contents(self::installDir().'/etc/authelia-secrets.conf');

        self::assertStringContainsString('Environment=AUTHELIA_SESSION_SECRET_FILE=/etc/authelia/secrets/session_secret', $conf);
        self::assertStringContainsString('Environment=AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE=/etc/authelia/secrets/storage_encryption_key', $conf);
        self::assertStringContainsString('Environment=AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE=/etc/authelia/secrets/reset_password_jwt_secret', $conf);
    }

    public function testCaddyDropIns(): void
    {
        $sandbox = (string) file_get_contents(self::installDir().'/etc/caddy-sandbox.conf');
        $env = (string) file_get_contents(self::installDir().'/etc/caddy-env.conf');

        self::assertStringContainsString("[Service]\nNoNewPrivileges=yes", $sandbox);
        self::assertStringContainsString('ReadWritePaths=/var/lib/caddy', $sandbox);
        self::assertStringContainsString('AmbientCapabilities=CAP_NET_BIND_SERVICE', $sandbox);
        self::assertStringContainsString("[Service]\nEnvironmentFile=-/etc/caddy/fleet.env", $env);
    }

    public function testEachAutheliaSecretIsGeneratedOnlyWhenItsOwnFileIsMissing(): void
    {
        $dir = $this->scratch().'/secrets';
        mkdir($dir);
        $env = $this->shellEnv($dir);

        [$status, , $err] = $this->withStep('60-authelia', 'for n in "${AUTHELIA_SECRET_NAMES[@]}"; do authelia_secret "$n"; done', $env);
        self::assertSame(0, $status, $err);
        $first = [];
        foreach (['session_secret', 'storage_encryption_key', 'reset_password_jwt_secret'] as $name) {
            $content = (string) file_get_contents($dir.'/'.$name);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', $content, $name);
            self::assertSame('640', substr(\sprintf('%o', fileperms($dir.'/'.$name)), -3));
            $first[$name] = $content;
        }
        self::assertCount(3, array_unique($first), 'three independent secrets');

        // A second run changes nothing.
        $this->withStep('60-authelia', 'for n in "${AUTHELIA_SECRET_NAMES[@]}"; do authelia_secret "$n"; done', $env);
        foreach ($first as $name => $content) {
            self::assertSame($content, file_get_contents($dir.'/'.$name), $name.' was regenerated');
        }

        // A kill between the writes: one file never appeared, one is empty. Only those two are filled in.
        unlink($dir.'/storage_encryption_key');
        file_put_contents($dir.'/reset_password_jwt_secret', '');
        [$status, , $err] = $this->withStep('60-authelia', 'for n in "${AUTHELIA_SECRET_NAMES[@]}"; do authelia_secret "$n"; done', $env);
        self::assertSame(0, $status, $err);
        self::assertSame($first['session_secret'], file_get_contents($dir.'/session_secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', (string) file_get_contents($dir.'/storage_encryption_key'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', (string) file_get_contents($dir.'/reset_password_jwt_secret'));
        self::assertNotSame($first['storage_encryption_key'], file_get_contents($dir.'/storage_encryption_key'));

        $left = array_map('basename', glob($dir.'/{,.}*[!.]', \GLOB_BRACE) ?: []);
        sort($left);
        self::assertSame(['reset_password_jwt_secret', 'session_secret', 'storage_encryption_key'], $left, 'no temp file is left behind');
    }

    public function testNoDefaultAccountAssertion(): void
    {
        $etc = $this->scratch().'/etc-authelia';
        mkdir($etc);
        $users = $this->scratch().'/users.yml';
        file_put_contents($users, "\"users\": {}\n");
        $env = ['AUTHELIA_TEST_ETC' => $etc, 'AUTHELIA_TEST_USERS' => $users];
        $code = 'AUTHELIA_ETC_DIR=$AUTHELIA_TEST_ETC; authelia_assert_no_default_account "$AUTHELIA_TEST_USERS"';

        [$status, , $err] = $this->withStep('60-authelia', $code, $env);
        self::assertSame(0, $status, $err);

        file_put_contents($etc.'/users_database.yml', "users:\n  authelia:\n    password: x\n");
        [$status, , $err] = $this->withStep('60-authelia', $code, $env);
        self::assertSame(1, $status);
        self::assertStringContainsString("default account 'authelia'", $err);
        unlink($etc.'/users_database.yml');

        file_put_contents($users, "something: else\n");
        [$status, , $err] = $this->withStep('60-authelia', $code, $env);
        self::assertSame(1, $status);
        self::assertStringContainsString('no users map', $err);
    }

    public function testUsersFileIsWrittenOnceAndNeverClobbered(): void
    {
        $dir = $this->scratch().'/authelia';
        mkdir($dir);
        $file = $dir.'/users.yml';
        $owner = posix_getuid().':'.posix_getgid();
        $code = 'write_file_once '.escapeshellarg($file).' 0640 '.$owner.' <<<\'"users": {}\'';

        [$status] = $this->withStep('60-authelia', $code, []);
        self::assertSame(0, $status);
        self::assertSame("\"users\": {}\n", file_get_contents($file));
        self::assertSame('640', substr(\sprintf('%o', fileperms($file)), -3));

        file_put_contents($file, "\"users\":\n  \"admin\": {}\n");
        $this->withStep('60-authelia', $code, []);
        self::assertSame("\"users\":\n  \"admin\": {}\n", file_get_contents($file), 'a file the app has written must survive a re-run');
    }

    public function testCustomCaddyIsRefreshedWhenOlderThanThePackagedOrPinnedVersion(): void
    {
        $dir = $this->scratch();
        $env = ['CADDY_TEST_DIR' => $dir];
        $this->fakeCaddy($dir.'/default-new', 'v2.12.0', true);
        $this->fakeCaddy($dir.'/default-old', 'v2.10.0', true);
        $this->fakeCaddy($dir.'/custom-9', 'v2.11.9', true);
        $this->fakeCaddy($dir.'/custom-12', 'v2.12.0', true);
        $this->fakeCaddy($dir.'/custom-pinned', 'v2.11.7', true);
        $this->fakeCaddy($dir.'/custom-below-pin', 'v2.11.6', true);
        $this->fakeCaddy($dir.'/custom-no-ovh', 'v2.99.0', false);

        $cases = [
            // packaged, custom, current?
            ['default-new', 'custom-9', 1],
            ['default-new', 'custom-12', 0],
            ['default-old', 'custom-pinned', 0],
            ['default-old', 'custom-below-pin', 1],
            ['default-old', 'custom-no-ovh', 1],
            ['default-old', 'missing', 1],
        ];
        foreach ($cases as [$packaged, $custom, $current]) {
            $code = \sprintf(
                'CADDY_DEFAULT_BIN="$CADDY_TEST_DIR/%s"; CADDY_CUSTOM_BIN="$CADDY_TEST_DIR/%s"; caddy_custom_is_current "$(caddy_wanted_version)"',
                $packaged,
                $custom,
            );
            [$status] = $this->withStep('50-caddy', $code, $env);
            self::assertSame($current, $status, \sprintf('packaged %s, custom %s: current=%d expected', $packaged, $custom, $current));
        }

        [, $wanted] = $this->withStep('50-caddy', 'CADDY_DEFAULT_BIN="$CADDY_TEST_DIR/default-new"; caddy_wanted_version', $env);
        self::assertSame("v2.12.0\n", $wanted);
        [, $wanted] = $this->withStep('50-caddy', 'CADDY_DEFAULT_BIN="$CADDY_TEST_DIR/default-old"; caddy_wanted_version', $env);
        self::assertSame("2.11.7\n", $wanted, 'never below the pinned minimum');
    }

    public function testCustomCaddyDownloadIsVerifiedBeforeItReplacesTheOldOne(): void
    {
        $dir = $this->scratch();
        $this->fakeCaddy($dir.'/served-new', 'v2.11.9', true);
        $this->fakeCaddy($dir.'/served-old', 'v2.11.1', true);
        $this->fakeCaddy($dir.'/served-no-ovh', 'v2.11.9', false);
        file_put_contents($dir.'/served-broken', "#!/bin/sh\nexit 1\n");
        $this->fakeCaddy($dir.'/installed', 'v2.11.0', true);
        $stubs = $this->stubs([
            // curl -fsSL ... -o <out> <url>: serve the file named by CADDY_SERVE.
            'curl' => 'out=; while [ $# -gt 0 ]; do [ "$1" = -o ] && out=$2; shift; done; cp "$CADDY_SERVE" "$out"',
            'chown' => 'exit 0',
        ]);
        $run = fn (string $served): array => $this->withStep(
            '50-caddy',
            'CADDY_CUSTOM_BIN="$CADDY_TEST_DIR/installed"; caddy_download_custom 2.11.7',
            ['CADDY_TEST_DIR' => $dir, 'CADDY_SERVE' => $dir.'/'.$served],
            $stubs,
        );

        foreach (['served-old' => 'older than the required', 'served-no-ovh' => 'no dns.providers.ovh', 'served-broken' => 'does not run'] as $served => $message) {
            [$status, , $err] = $run($served);
            self::assertSame(1, $status, $served);
            self::assertStringContainsString($message, $err);
            self::assertStringContainsString('v2.11.0', (string) shell_exec(escapeshellarg($dir.'/installed').' version'), 'the installed build is untouched after '.$served);
        }

        [$status, $out, $err] = $run('served-new');
        self::assertSame(0, $status, $err);
        self::assertMatchesRegularExpression('/custom caddy v2\.11\.9 sha256 [0-9a-f]{64}/', $out, 'the downloaded build is fingerprinted in the log');
        self::assertStringContainsString('v2.11.9', (string) shell_exec(escapeshellarg($dir.'/installed').' version'));
        self::assertFileDoesNotExist($dir.'/installed.new');
    }

    public function testDpkgIsRepairedOnlyWhenInterruptedAndOnlyOnce(): void
    {
        $dir = $this->scratch();
        $updates = $dir.'/updates';
        mkdir($updates);
        $calls = $dir.'/calls';
        $stubs = $this->stubs([
            'dpkg' => 'echo "dpkg $*" >> "$CALLS"; if [ "$1" = --audit ] && [ -n "$AUDIT" ]; then echo "half-configured: foo"; fi; exit 0',
            'apt-get' => 'echo "apt-get $*" >> "$CALLS"',
        ]);
        $code = 'DPKG_UPDATES_DIR="$UPDATES"; dpkg_recover; dpkg_recover; apt_install foo';
        $run = function (array $env) use ($code, $calls, $stubs, $updates): string {
            @unlink($calls);
            $this->withCommon($code, $env + ['CALLS' => $calls, 'AUDIT' => '', 'UPDATES' => $updates], $stubs);

            return (string) @file_get_contents($calls);
        };

        $clean = $run([]);
        self::assertStringNotContainsString('--configure', $clean);
        self::assertStringContainsString(' install foo', $clean);

        $audited = $run(['AUDIT' => '1']);
        self::assertSame(1, substr_count($audited, '--configure -a'), 'repaired once, not once per call');
        self::assertLessThan(strpos($audited, ' install foo'), strpos($audited, '--configure -a'), 'repair comes before the install');
        self::assertStringContainsString('-f install', $audited);
        self::assertStringContainsString('--force-confold', $audited);

        touch($updates.'/0001');
        $pending = $run([]);
        self::assertStringContainsString('--configure -a', $pending, 'a pending dpkg journal counts as interrupted');
    }

    public function testStartPolicyIsOursToCreateAndRemoveOnly(): void
    {
        $policy = $this->scratch().'/policy-rc.d';
        $env = ['POLICY' => $policy];
        $stubs = $this->stubs(['chown' => 'exit 0']);

        $this->withCommon('POLICY_RC="$POLICY"; policy_deny_start', $env, $stubs);
        self::assertFileExists($policy);
        self::assertStringContainsString('exit 101', (string) file_get_contents($policy));
        self::assertSame('755', substr(\sprintf('%o', fileperms($policy)), -3));

        $this->withCommon('POLICY_RC="$POLICY"; policy_allow_start', $env, $stubs);
        self::assertFileDoesNotExist($policy);

        file_put_contents($policy, "#!/bin/sh\nexit 101\n# someone else's\n");
        [, , $err] = $this->withCommon('POLICY_RC="$POLICY"; policy_deny_start; policy_allow_start', $env, $stubs);
        self::assertFileExists($policy, "an administrator's own policy file is never removed");
        self::assertStringContainsString('not ours', $err);
    }

    public function testAutheliaIsInstalledBetweenStartDenyAndAllow(): void
    {
        [, $out] = $this->dryRun();

        $deny = $this->position($out, '+ write /usr/sbin/policy-rc.d 0755 root:root');
        $install = $this->position($out, ' DPkg::Lock::Timeout=120 install authelia');
        $allow = $this->position($out, '+ rm -f /usr/sbin/policy-rc.d');
        self::assertLessThan($install, $deny);
        self::assertLessThan($allow, $install);
    }

    public function testDryRunPlansTheDpkgRepairBeforeTheFirstAptCallOfBothStages(): void
    {
        [, $out] = $this->dryRun();

        self::assertSame(2, substr_count($out, "--force-confold --configure -a\n"), 'stage 1 and stage 2 each repair first');
        self::assertLessThan(strpos($out, ' update'), strpos($out, '--configure -a'));
        self::assertStringContainsString('git -C /opt/ddev-fleet checkout --quiet --force -B develop origin/develop', $out);
        self::assertStringContainsString('git -C /opt/ddev-fleet cat-file -e HEAD:install/run.sh', $out);
        self::assertStringNotContainsString('--ff-only', $out, 'a rewritten branch must converge, not wedge on a fast-forward');
    }

    public function testAnInstalledCustomCaddyIsKeptWhenTheBuildServiceFails(): void
    {
        $dir = $this->scratch();
        $this->fakeCaddy($dir.'/default', 'v2.12.0', true);
        $this->fakeCaddy($dir.'/ok-but-older', 'v2.11.8', true);
        $this->fakeCaddy($dir.'/below-pin', 'v2.11.0', true);
        $stubs = $this->stubs(['curl' => 'exit 22', 'chown' => 'exit 0']);
        $run = fn (string $installed): array => $this->withStep(
            '50-caddy',
            \sprintf('CADDY_DEFAULT_BIN="$CADDY_TEST_DIR/default"; CADDY_CUSTOM_BIN="$CADDY_TEST_DIR/%s"; caddy_refresh_custom "$(caddy_wanted_version)"', $installed),
            ['CADDY_TEST_DIR' => $dir],
            $stubs,
        );

        [$status, , $err] = $run('ok-but-older');
        self::assertSame(0, $status, $err);
        self::assertStringContainsString('keeping the installed custom Caddy v2.11.8', $err);

        [$status, , $err] = $run('below-pin');
        self::assertSame(1, $status);
        self::assertStringContainsString('no custom Caddy', $err);

        [$status] = $run('missing');
        self::assertSame(1, $status);
    }

    public function testEveryFailureNamesTheStepAndTheRerunHintOnce(): void
    {
        $copy = $this->scratch().'/copy';
        mkdir($copy);
        new Process(['cp', '-r', self::installDir(), $copy.'/install'])->mustRun();
        $env = ['FLEET_OS_RELEASE' => $this->osRelease()];
        $args = [$copy.'/install/run.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'];

        file_put_contents($copy.'/install/steps/05-boom.sh', "step_main() {\n  die 1 \"boom\"\n}\n");
        [$status, , $err] = $this->bash($args, $env);
        self::assertSame(1, $status);
        self::assertStringContainsString('ERROR: boom', $err);
        self::assertSame(1, substr_count($err, 'step "05-boom" failed (exit 1, at 05-boom.sh:2).'), $err);
        self::assertSame(1, substr_count($err, 're-run the same command'), $err);

        file_put_contents($copy.'/install/steps/05-boom.sh', "step_main() {\n  local x\n  false\n}\n");
        [$status, , $err] = $this->bash($args, $env);
        self::assertSame(1, $status);
        self::assertMatchesRegularExpression('/step "05-boom" failed \(exit 1, at 05-boom\.sh:3\)\./', $err, 'the real file and line, not run.sh');
        self::assertSame(1, substr_count($err, 're-run the same command'), $err);
    }

    /**
     * @return array<string, string> the environment that points the step helpers at a scratch secrets directory
     */
    private function shellEnv(string $secretsDir): array
    {
        return ['AUTHELIA_TEST_SECRETS' => $secretsDir];
    }

    /**
     * Sources common.sh and one step, then runs the code. chown is stubbed (the tests run unprivileged); a code
     * snippet that needs the scratch secrets directory gets it from AUTHELIA_TEST_SECRETS.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function withStep(string $step, string $code, array $env, ?string $stubs = null): array
    {
        $stubs ??= $this->stubs(['chown' => 'exit 0']);
        $prelude = '. "$INSTALL_DIR/steps/'.$step.'.sh"; AUTHELIA_GROUP=testgroup; ';
        if (isset($env['AUTHELIA_TEST_SECRETS'])) {
            $prelude .= 'AUTHELIA_SECRETS_DIR=$AUTHELIA_TEST_SECRETS; ';
        }

        return $this->withCommon($prelude.$code, $env, $stubs);
    }

    private function fakeCaddy(string $path, string $version, bool $ovh): void
    {
        file_put_contents($path, \sprintf(
            "#!/bin/sh\ncase \"\$1\" in\nversion) echo '%s h1:abcdef';;\nlist-modules) echo http.handlers.file_server; %s;;\nesac\n",
            $version,
            $ovh ? 'echo dns.providers.ovh' : 'echo dns.providers.cloudflare',
        ));
        chmod($path, 0o755);
    }

    private function position(string $haystack, string $needle): int
    {
        $position = strpos($haystack, $needle);
        self::assertNotFalse($position, 'the plan has no line containing: '.$needle);

        return $position;
    }
}
