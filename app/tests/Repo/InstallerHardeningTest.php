<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-211: the opt-in host hardening (install/harden/, step 99). Dry-run plans, flag validation, the generated files
 * (unprivileged, against a scratch tree with stubbed commands) and the root scripts (sync, dead-man's switch).
 */
final class InstallerHardeningTest extends TestCase
{
    use InstallerHarness;

    public function testAPlainDryRunPlansNoHardening(): void
    {
        [$status, $out] = $this->dryRun();

        self::assertSame(0, $status);
        self::assertStringContainsString('host hardening: off', $out);
        foreach (['ufw', 'sshd', 'fail2ban', 'ssh/sshd_config.d', 'ufw/after.rules', 'resolved.conf.d', 'auditd'] as $word) {
            self::assertStringNotContainsString($word, $out, "a plain install must not mention $word");
        }
    }

    public function testDryRunWithHardenListsEveryChangeAndChangesNothing(): void
    {
        $before = $this->snapshot();
        [$status, $out, $err] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--harden', '--harden-tmp', '--trusted-ip=203.0.113.7', '--trusted-ip=2001:db8::/32']);

        self::assertSame(0, $status, $err);
        foreach ([
            'write /etc/ssh/sshd_config.d/10-fleet-hardening.conf',
            'sshd -t',
            'write /etc/fail2ban/jail.d/fleet-sshd.conf',
            'write /etc/systemd/resolved.conf.d/fleet-llmnr.conf',
            'write /etc/sysctl.d/60-fleet-hardening.conf',
            'write /etc/audit/rules.d/50-fleet.rules',
            'write /etc/systemd/system/tmp.mount.d/override.conf',
            'write /etc/ufw/after.rules',
            'ufw default deny incoming',
            'ufw allow 80/tcp',
            'ufw allow 443/tcp',
            'ufw allow from 203.0.113.7',
            'ufw allow from 2001:db8::/32',
            'write /usr/local/sbin/fleet-firewall-confirm',
            'write /usr/local/sbin/fleet-firewall-sync',
            'write /etc/systemd/system/fleet-firewall-deadman.timer',
            'write /etc/ddev-fleet/hardening.conf',
            'ufw --force enable',
        ] as $line) {
            self::assertStringContainsString($line, $out);
        }
        self::assertLessThan(strpos($out, 'ufw --force enable'), strpos($out, 'ufw allow 22/tcp'), 'SSH is allowed before the firewall is enabled');
        self::assertSame($before, $this->snapshot());
    }

    public function testFlagsAreValidated(): void
    {
        foreach ([
            ['--ssh-allow-user=x'],
            ['--trusted-ip=203.0.113.7'],
            ['--firewall-grace=5'],
            ['--harden-tmp'],
            ['--harden', '--trusted-ip=300.1.1.1'],
            ['--harden', '--trusted-ip=1.2.3.4/33'],
            ['--harden', '--trusted-ip=abc'],
            ['--harden', '--ssh-allow-user=Bad Name'],
            ['--harden', '--firewall-grace=1'],
            ['--harden', '--firewall-grace=500'],
        ] as $extra) {
            [$status] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', ...$extra]);
            self::assertSame(64, $status, implode(' ', $extra));
        }
    }

    public function testTheAccountInUseMustStayInTheSshAllowList(): void
    {
        [$status, , $err] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--harden', '--ssh-allow-user=somebodyelse'], ['SUDO_USER' => 'operator']);

        self::assertSame(64, $status);
        self::assertStringContainsString('would lock you out', $err);
        self::assertStringContainsString('operator', $err);

        [$status, $out] = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--harden', '--ssh-allow-user=operator', '--ssh-allow-user=deploy'], ['SUDO_USER' => 'operator']);
        self::assertSame(0, $status);
        self::assertStringContainsString('SSH users: operator deploy', $out);
    }

    public function testUsageAndStageOneKnowTheFlags(): void
    {
        foreach (['install.sh', 'lib/common.sh'] as $file) {
            $text = (string) file_get_contents(self::installDir().'/'.$file);
            foreach (['--harden', '--ssh-allow-user', '--trusted-ip', '--firewall-grace', '--harden-tmp'] as $flag) {
                self::assertStringContainsString($flag, $text, "$file: $flag");
            }
        }
    }

    /**
     * Runs the real step against a scratch tree.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function applyStep(string $root, string $conf = '', array $env = [], string $pre = ''): array
    {
        $stubs = $this->stubs([
            'apt-get' => 'exit 0',
            'dpkg' => 'exit 0',
            'sshd' => 'if [ "$1" = -T ]; then echo "port 2222"; '.($env['SSHD_INEFFECTIVE'] ?? '' ? '' : 'echo "passwordauthentication no"; echo "maxauthtries 3"; echo "allowusers operator deploy"; ').'fi; exit 0',
            'systemctl' => 'echo "systemctl $*" >> '.escapeshellarg($root.'/calls.log').'; case "$1" in show) [ "$2" = tmp.mount ] && echo "mode=1777,strictatime,nosuid,nodev,size=50%,nr_inodes=1m";; esac; exit 0',
            'ufw' => 'echo "ufw $*" >> '.escapeshellarg($root.'/calls.log').'; case "$1" in status) [ -f '.escapeshellarg($root.'/ufw-active').' ] && echo "Status: active"; exit 0;; show) echo "ufw allow 2222/tcp"; echo "ufw allow 80/tcp";; esac; exit 0',
            'findmnt' => 'echo /tmp',
            'augenrules' => 'exit 0',
            'sysctl' => 'exit 0',
            'runuser' => ($env['RUNUSER_FAIL_FIRST'] ?? '' ? 'n='.escapeshellarg($root.'/runuser-count').'; [ -f "$n" ] || { : > "$n"; exit 2; }; ' : '').'echo \'{"ports":[{"port":80},{"port":9108}]}\'',
        ]);
        $code = ($conf ? 'mkdir -p "$FLEET_ETC_DIR"; printf %s '.escapeshellarg($conf).' > "$FLEET_ETC_DIR/hardening.conf"; ' : '')
            .$pre.'DPKG_UPDATES_DIR="$FLEET_ROOT_PREFIX/none"; . "$INSTALL_DIR/steps/99-harden.sh"; step_main';

        $syncEnv = [
            'FLEET_FIREWALL_TEST_PATH' => $stubs.':'.(string) getenv('PATH'),
            'FLEET_FIREWALL_STATE' => $root.'/firewall-ports',
            'FLEET_FIREWALL_LOCK' => $root.'/firewall-sync.lock',
        ];

        return $this->withCommon($code, ['FLEET_ROOT_PREFIX' => $root] + $env + $syncEnv, $stubs);
    }

    private function prefix(bool $keys = true): string
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Root ignores FLEET_ROOT_PREFIX by design.');
        }
        $root = $this->scratch().'/root';
        mkdir($root.'/etc/ddev-fleet', 0o700, true);
        touch($root.'/calls.log');
        if ($keys) {
            foreach (['operator'] as $user) {
                mkdir($root.'/home/'.$user.'/.ssh', 0o700, true);
                file_put_contents($root.'/home/'.$user.'/.ssh/authorized_keys', "# comment\nssh-ed25519 AAAAC3Nza test@example\n");
            }
        }

        return $root;
    }

    public function testTheStepIsInertWithoutTheFlagOrAStoredSetting(): void
    {
        $root = $this->prefix();
        [$status, $out, $err] = $this->applyStep($root);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('host hardening: off', $out);
        self::assertSame('', (string) file_get_contents($root.'/calls.log'));
        self::assertSame(['calls.log', 'etc', 'home'], array_values(array_diff(scandir($root) ?: [], ['.', '..'])));

        [$status] = $this->applyStep($root, "ENABLED=0\n");
        self::assertSame(0, $status);
        self::assertFileDoesNotExist($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf');
    }

    public function testNamedPortsAreOpenedRightAfterTheFirstEnableEvenWhenTheFirstSyncFails(): void
    {
        $root = $this->prefix();
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator', 'RUNUSER_FAIL_FIRST' => '1'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); HARDEN_GRACE=15; ');

        self::assertSame(0, $status, $err);
        self::assertStringNotContainsString('named-port sync failed', $err, 'a failure before the enable is not a warning');
        $calls = (string) file_get_contents($root.'/calls.log');
        self::assertNotFalse(strpos($calls, 'ufw allow 9108/tcp'), 'the named port is opened by the sync after the enable');
        self::assertLessThan(strpos($calls, 'ufw allow 9108/tcp'), strpos($calls, 'ufw --force enable'));
    }

    public function testAnExplicitRunWritesTheFilesAndAStoredRunReappliesThem(): void
    {
        $root = $this->prefix();
        mkdir($root.'/home/deploy/.ssh', 0o700, true);
        file_put_contents($root.'/home/deploy/.ssh/authorized_keys', "ssh-ed25519 AAAAC3Nza deploy@example\n");
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator deploy); HARDEN_TRUSTED_IPS=(203.0.113.7); HARDEN_GRACE=15; HARDEN_TMP=1; ');

        self::assertSame(0, $status, $err);
        $ssh = (string) file_get_contents($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf');
        foreach (['PasswordAuthentication no', 'KbdInteractiveAuthentication no', 'PermitRootLogin prohibit-password', 'MaxAuthTries 3', 'AllowUsers operator deploy'] as $line) {
            self::assertStringContainsString($line, $ssh);
        }
        $f2b = (string) file_get_contents($root.'/etc/fail2ban/jail.d/fleet-sshd.conf');
        self::assertStringContainsString('ignoreip = 127.0.0.1/8 ::1 203.0.113.7', $f2b);
        self::assertStringContainsString('maxretry = 5', $f2b);
        self::assertStringContainsString('LLMNR=no', (string) file_get_contents($root.'/etc/systemd/resolved.conf.d/fleet-llmnr.conf'));
        $tmp = (string) file_get_contents($root.'/etc/systemd/system/tmp.mount.d/override.conf');
        self::assertStringContainsString('Options=mode=1777,strictatime,nosuid,nodev,size=50%,nr_inodes=1m,noexec', $tmp, 'keeps the existing options, adds only the missing one');
        self::assertStringContainsString('OnActiveSec=15min', (string) file_get_contents($root.'/etc/systemd/system/fleet-firewall-deadman.timer'));
        $conf = (string) file_get_contents($root.'/etc/ddev-fleet/hardening.conf');
        self::assertStringContainsString("ENABLED=1\nSSH_ALLOW_USERS=operator deploy\nTRUSTED_IPS=203.0.113.7\nFIREWALL_GRACE_MINUTES=15\nSSH_PORTS=2222\nHARDEN_TMP=1\n", $conf);
        $calls = (string) file_get_contents($root.'/calls.log');
        self::assertStringContainsString('ufw allow 2222/tcp', $calls, 'the real SSH port, not a guessed 22');
        self::assertStringContainsString('ufw allow from 203.0.113.7', $calls);
        self::assertLessThan(strpos($calls, 'ufw --force enable'), strpos($calls, 'ufw allow 2222/tcp'));
        self::assertLessThan(strpos($calls, 'ufw default deny incoming'), strpos($calls, 'ufw allow 2222/tcp'), 'allows are staged before the default policy changes');
        self::assertStringContainsString('backend = systemd', $f2b);
        self::assertStringContainsString('start fleet-firewall-deadman.timer', $calls, 'a first enable arms the dead-man switch');
        $after = (string) file_get_contents($root.'/etc/ufw/after.rules');
        self::assertStringStartsWith('# BEGIN ddev-fleet DOCKER-USER guard', $after);
        self::assertSame(1, substr_count($after, ':DOCKER-USER'));

        // A later run without the flag re-applies the stored settings, identical files, no second guard block.
        $before = $conf;
        [$status, , $err] = $this->applyStep($root);
        self::assertSame(0, $status, $err);
        self::assertSame($before, (string) file_get_contents($root.'/etc/ddev-fleet/hardening.conf'));
        self::assertSame(1, substr_count((string) file_get_contents($root.'/etc/ufw/after.rules'), ':DOCKER-USER'));
        self::assertStringContainsString('AllowUsers operator deploy', (string) file_get_contents($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf'));
    }

    public function testTmpIsLeftAloneWithoutItsOwnFlag(): void
    {
        $root = $this->prefix();
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');

        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($root.'/etc/systemd/system/tmp.mount.d/override.conf');
        self::assertStringContainsString('HARDEN_TMP=0', (string) file_get_contents($root.'/etc/ddev-fleet/hardening.conf'));
    }

    public function testTheCurrentSshAddressIsNeverBanned(): void
    {
        $root = $this->prefix();
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator', 'SSH_CONNECTION' => '198.51.100.9 5555 10.0.0.1 22'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('ignoreip = 127.0.0.1/8 ::1 198.51.100.9', (string) file_get_contents($root.'/etc/fail2ban/jail.d/fleet-sshd.conf'));
    }

    public function testRefusesWhenTheAccountInUseHasNoPublicKey(): void
    {
        $root = $this->prefix(false);
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');

        self::assertNotSame(0, $status);
        self::assertStringContainsString('no usable public key', $err);
        self::assertFileDoesNotExist($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf');
        self::assertFileDoesNotExist($root.'/etc/ddev-fleet/hardening.conf', 'nothing is stored by a refused run');
    }

    public function testRefusesToShutOutAnotherAccountThatHasKeys(): void
    {
        $root = $this->prefix();
        mkdir($root.'/home/colleague/.ssh', 0o700, true);
        file_put_contents($root.'/home/colleague/.ssh/authorized_keys', "ssh-ed25519 AAAAC3Nza colleague@example\n");

        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('colleague', $err);
        self::assertFileDoesNotExist($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf');

        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator colleague); ');
        self::assertSame(0, $status, $err);
    }

    public function testWarnsLoudlyWhenTheDropInIsNotTheEffectiveConfiguration(): void
    {
        $root = $this->prefix();
        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator', 'SSHD_INEFFECTIVE' => '1'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('NOT effective', $err);
    }

    public function testAChangeOfTrustedAddressesOrAReEnableRearmsTheDeadMansSwitch(): void
    {
        $root = $this->prefix();
        $base = "ENABLED=1\nSSH_ALLOW_USERS=operator\nTRUSTED_IPS=203.0.113.7\nFIREWALL_GRACE_MINUTES=10\nSSH_PORTS=2222\n";
        touch($root.'/ufw-active');
        touch($root.'/etc/ddev-fleet/firewall-confirmed');

        // Active and confirmed, nothing changed: stays confirmed, no new timer.
        [$status, , $err] = $this->applyStep($root, $base, ['SUDO_USER' => 'operator']);
        self::assertSame(0, $status, $err);
        self::assertFileExists($root.'/etc/ddev-fleet/firewall-confirmed');
        self::assertStringNotContainsString('start fleet-firewall-deadman.timer', (string) file_get_contents($root.'/calls.log'));

        // A new trusted address: confirmation is cleared and the switch armed again.
        [$status, , $err] = $this->applyStep($root, $base, ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_TRUSTED_IPS=(198.51.100.4); ');
        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($root.'/etc/ddev-fleet/firewall-confirmed');
        self::assertStringContainsString('start fleet-firewall-deadman.timer', (string) file_get_contents($root.'/calls.log'));

        // Firewall found inactive although confirmed earlier: re-enabling clears the marker and arms.
        unlink($root.'/ufw-active');
        touch($root.'/etc/ddev-fleet/firewall-confirmed');
        file_put_contents($root.'/calls.log', '');
        [$status, , $err] = $this->applyStep($root, $base, ['SUDO_USER' => 'operator']);
        self::assertSame(0, $status, $err);
        self::assertFileDoesNotExist($root.'/etc/ddev-fleet/firewall-confirmed');
        self::assertStringContainsString('start fleet-firewall-deadman.timer', (string) file_get_contents($root.'/calls.log'));
    }

    public function testASshdThatRejectsTheDropInChangesNothing(): void
    {
        $root = $this->prefix();
        $stubs = $this->stubs(['apt-get' => 'exit 0', 'dpkg' => 'exit 0', 'sshd' => 'if [ "$1" = -t ]; then exit 1; fi; exit 0', 'systemctl' => 'exit 0']);
        $code = 'HARDEN=1; HARDEN_SSH_USERS=(operator); DPKG_UPDATES_DIR="$FLEET_ROOT_PREFIX/none"; . "$INSTALL_DIR/steps/99-harden.sh"; step_main';

        [$status] = $this->withCommon($code, ['FLEET_ROOT_PREFIX' => $root, 'SUDO_USER' => 'operator'], $stubs);

        self::assertNotSame(0, $status);
        self::assertFileDoesNotExist($root.'/etc/ssh/sshd_config.d/10-fleet-hardening.conf');
    }

    public function testTheFirewallRefusesARouterOnAllInterfaces(): void
    {
        $root = $this->prefix();
        mkdir($root.'/home/fleet/.ddev', 0o700, true);
        file_put_contents($root.'/home/fleet/.ddev/global_config.yaml', "router_bind_all_interfaces: true\n");

        [$status, , $err] = $this->applyStep($root, '', ['SUDO_USER' => 'operator'], 'HARDEN=1; HARDEN_SSH_USERS=(operator); ');

        self::assertNotSame(0, $status);
        self::assertStringContainsString('router listens on all interfaces', $err);
        self::assertStringNotContainsString('ufw --force enable', (string) file_get_contents($root.'/calls.log'));
    }

    public function testTheConfirmScriptWritesTheMarkerBeforeStoppingTheTimer(): void
    {
        $dir = $this->scratch();
        $log = $dir.'/log';
        $stubs = $this->stubs(['systemctl' => 'if [ -f '.escapeshellarg($dir.'/marker').' ]; then echo "marker-first" > '.escapeshellarg($log).'; fi']);

        [$status, $out] = $this->bash([self::installDir().'/harden/etc/firewall-confirm'], ['FLEET_FIREWALL_MARKER' => $dir.'/marker'], null, $stubs);

        self::assertSame(0, $status);
        self::assertFileExists($dir.'/marker');
        self::assertSame("marker-first\n", file_get_contents($log));
        self::assertStringContainsString('timer is stopped', $out);
    }

    public function testTheFireScriptDisablesTheFirewallOnlyWithoutTheMarker(): void
    {
        $dir = $this->scratch();
        $log = $dir.'/ufw.log';
        $stubs = $this->stubs(['ufw' => 'echo "$*" >> '.escapeshellarg($log)]);
        $env = ['FLEET_FIREWALL_MARKER' => $dir.'/marker'];

        $this->bash([self::installDir().'/harden/etc/firewall-deadman-fire'], $env, null, $stubs);
        self::assertSame("disable\n", file_get_contents($log));

        touch($dir.'/marker');
        unlink($log);
        [$status] = $this->bash([self::installDir().'/harden/etc/firewall-deadman-fire'], $env, null, $stubs);
        self::assertSame(0, $status);
        self::assertFileDoesNotExist($log);
    }

    /**
     * @return array{int, string, string, string} status, stdout, stderr, the ufw calls
     */
    private function sync(string $json, string $state, string $sshPorts = '22'): array
    {
        $dir = $this->scratch();
        $log = $dir.'/ufw-'.bin2hex(random_bytes(2)).'.log';
        file_put_contents($dir.'/hardening.conf', "SSH_PORTS=$sshPorts\n");
        if ('' !== $state) {
            file_put_contents($dir.'/state', $state);
        }
        $stubs = $this->stubs([
            'runuser' => '[ -n "$FAIL" ] && exit 1; printf %s '.escapeshellarg($json),
            'ufw' => 'echo "$*" >> '.escapeshellarg($log),
        ]);
        [$status, $out, $err] = $this->bash([self::installDir().'/harden/etc/firewall-sync'], [
            'FLEET_FIREWALL_STATE' => $dir.'/state',
            'FLEET_FIREWALL_CONF' => $dir.'/hardening.conf',
            'FLEET_FIREWALL_LOCK' => $dir.'/lock',
            'FAIL' => '' === $json ? '1' : '',
            'FLEET_FIREWALL_TEST_PATH' => $stubs.':'.(string) getenv('PATH'),
        ], null, $stubs);
        $this->lastState = is_file($dir.'/state') ? (string) file_get_contents($dir.'/state') : '';

        return [$status, $out, $err, is_file($log) ? (string) file_get_contents($log) : ''];
    }

    private string $lastState = '';

    public function testTheSyncOpensNewNamedPortsAndClosesTheOnesNoProjectUses(): void
    {
        if ('' === trim((string) shell_exec('command -v jq'))) {
            self::markTestSkipped('jq is required by the sync script.');
        }
        $json = '{"ports":[{"port":80},{"port":443},{"port":22},{"port":9108},{"port":9111}]}';

        [$status, , , $ufw] = $this->sync($json, "9325\n9111\n");

        self::assertSame(0, $status);
        self::assertStringContainsString('allow 9108/tcp', $ufw);
        self::assertStringNotContainsString('allow 9111/tcp', $ufw, 'already open');
        self::assertStringContainsString('delete allow 9325/tcp', $ufw);
        self::assertStringNotContainsString('80/tcp', $ufw, '80, 443 and SSH are never ours');
        self::assertStringNotContainsString('443/tcp', $ufw);
        self::assertStringNotContainsString(' 22/tcp', $ufw);
        self::assertSame("9108\n9111\n", $this->lastState);
    }

    public function testTheSyncChangesNothingWhenFleetDoesNotAnswer(): void
    {
        if ('' === trim((string) shell_exec('command -v jq'))) {
            self::markTestSkipped('jq is required by the sync script.');
        }
        [$status, , $err, $ufw] = $this->sync('', "9108\n");
        self::assertSame(1, $status);
        self::assertSame('', $ufw);
        self::assertSame("9108\n", $this->lastState);
        self::assertStringContainsString('nothing changed', $err);

        [$status, , , $ufw] = $this->sync('{"ports":"nope"}', "9108\n");
        self::assertSame(1, $status);
        self::assertSame('', $ufw);
    }

    public function testNoNamedPortInUseClosesWhatWeOpened(): void
    {
        if ('' === trim((string) shell_exec('command -v jq'))) {
            self::markTestSkipped('jq is required by the sync script.');
        }
        [$status, , , $ufw] = $this->sync('{"ports":[{"port":80},{"port":443}]}', "9108\n");

        self::assertSame(0, $status);
        self::assertSame("delete allow 9108/tcp\n", $ufw);
        self::assertSame('', trim($this->lastState));
    }

    public function testRootScriptsParse(): void
    {
        foreach (['firewall-sync', 'firewall-confirm', 'firewall-deadman-fire'] as $script) {
            $p = new Process(['bash', '-n', self::installDir().'/harden/etc/'.$script]);
            $p->run();
            self::assertSame(0, $p->getExitCode(), $script.$p->getErrorOutput());
        }
    }

    public function testTheDeadMansSwitchIsArmedBeforeTheFirewallIsEnabled(): void
    {
        $sh = (string) file_get_contents(\dirname(__DIR__, 3).'/install/harden/harden.sh');
        $timer = strpos($sh, 'run systemctl start fleet-firewall-deadman.timer');
        $boot = strpos($sh, 'run systemctl enable fleet-firewall-deadman-bootcheck.service');
        $enable = strpos($sh, 'run ufw --force enable');
        self::assertNotFalse($timer);
        self::assertNotFalse($boot);
        self::assertNotFalse($enable);
        self::assertLessThan($enable, $timer);
        self::assertLessThan($enable, $boot);
        self::assertStringContainsString('ensure_dir "$FLEET_ETC_DIR" 0750 root:fleet', $sh);
    }

    /** @return list<string> */
    private function snapshot(): array
    {
        $files = [];
        foreach (['/etc/ssh', '/etc/ufw', '/etc/fail2ban', '/etc/ddev-fleet', '/usr/local/sbin'] as $dir) {
            $files[] = $dir.':'.(is_dir($dir) ? (string) filemtime($dir) : 'none');
        }

        return $files;
    }
}
