<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * install/uninstall.sh. Only ever run with --dry-run (or refused) here: nothing is removed.
 */
final class UninstallTest extends TestCase
{
    use InstallerHarness;

    public function testRefusesWithoutYes(): void
    {
        foreach ([[], ['--dry-run'], ['--keep-data'], ['--purge-packages']] as $args) {
            [$status, $out, $err] = $this->uninstall($args);

            self::assertSame(64, $status, json_encode($args).': '.$err);
            self::assertStringContainsString('--yes', $err);
            self::assertStringNotContainsString('+ rm', $out, 'nothing is even planned without --yes');
        }
    }

    public function testRefusesUnknownArguments(): void
    {
        [$status, , $err] = $this->uninstall(['--yes', '--everything']);

        self::assertSame(64, $status);
        self::assertStringContainsString('unknown argument', $err);
    }

    public function testNeedsRootUnlessDryRun(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Running as root.');
        }

        [$status, , $err] = $this->uninstall(['--yes']);

        self::assertSame(77, $status, $err);
    }

    public function testDryRunListsRemovals(): void
    {
        [$status, $out, $err] = $this->uninstall(['--yes', '--dry-run']);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('dry-run: nothing is changed', $out);
        foreach ([
            '+ systemctl disable --now fleet-worker@1.service',
            '+ systemctl disable --now fleet-mercure.service',
            '+ systemctl disable --now caddy.service',
            '+ systemctl disable --now authelia.service',
            '+ rm -f -- /etc/systemd/system/fleet-worker@.service',
            '+ rm -f -- /etc/systemd/system/fleet-mercure.service',
            '+ rm -f -- /etc/systemd/system/php8.4-fpm.service.d/fleet-env.conf',
            '+ rm -f -- /etc/systemd/system/caddy.service.d/10-fleet-sandbox.conf',
            '+ rm -f -- /etc/systemd/system/authelia.service.d/secrets.conf',
            '+ rm -f -- /etc/tmpfiles.d/authelia.conf',
            '+ rm -rf --one-file-system -- /etc/ddev-fleet',
            '+ rm -rf --one-file-system -- /etc/caddy/fleet',
            '+ rm -f -- /etc/caddy/fleet.env',
            '+ update-alternatives --remove caddy /usr/bin/caddy.custom',
            '+ dpkg-divert --rename --remove /usr/bin/caddy',
            '+ rm -f -- /etc/caddy/Caddyfile',
            '--force-confmiss install --reinstall caddy',
            '+ rm -f -- /etc/php/8.4/fpm/pool.d/fleet.conf',
            '+ mv -f -- /etc/php/8.4/fpm/pool.d/www.conf.disabled /etc/php/8.4/fpm/pool.d/www.conf',
            '+ rm -f -- /etc/sudoers.d/fleet-apply',
            '+ rm -rf --one-file-system -- /usr/local/libexec/fleet',
            '+ rm -f -- /usr/local/bin/fleet',
            '+ rm -f -- /usr/local/bin/mercure',
            '+ rm -rf --one-file-system -- /opt/ddev-fleet',
            '+ rm -rf --one-file-system -- /srv/fleet',
            '+ userdel --remove fleet',
            'packages left installed',
        ] as $line) {
            self::assertStringContainsString($line, $out);
        }
        self::assertStringNotContainsString('apt-get -y -q purge', $out, 'packages stay without --purge-packages');
    }

    public function testEveryWorkerInstanceIsStoppedAndDisabledByFullNameBeforeAnythingIsRemoved(): void
    {
        // systemctl lists loaded instances (one failed, one only loaded); the enabled fleet-worker@1 is always included.
        $stubs = $this->stubs(['systemctl' => 'if [ "$1" = list-units ]; then echo "fleet-worker@2.service loaded active running w"; echo "● fleet-worker@7.service loaded failed failed w"; echo "caddy.service loaded active running c"; fi']);

        [$status, $out, $err] = $this->bash([self::installDir().'/uninstall.sh', '--yes', '--dry-run'], [], null, $stubs);

        self::assertSame(0, $status, $err);
        foreach (['1', '2', '7'] as $n) {
            self::assertSame(1, substr_count($out, '+ systemctl disable --now fleet-worker@'.$n.".service\n"), 'instance '.$n);
        }
        self::assertStringNotContainsString('fleet-worker@\\*', $out, 'no glob: disable takes unit file names');
        self::assertStringNotContainsString('--now caddy.service\n+ systemctl disable --now fleet-worker', $out);
        $workers = (int) strrpos($out, 'fleet-worker@7.service');
        self::assertLessThan((int) strpos($out, '+ rm -f -- /etc/systemd/system/fleet-worker@.service'), $workers, 'stopped before the unit file goes');
        self::assertLessThan((int) strpos($out, '+ userdel --remove fleet'), (int) strpos($out, '+ pkill -KILL -u fleet'), 'nothing of the user runs when userdel is called');
        self::assertLessThan((int) strpos($out, '+ userdel --remove fleet'), $workers);
    }

    public function testTheAutheliaTmpfilesMaskStaysWithKeepData(): void
    {
        [, $out] = $this->uninstall(['--yes', '--dry-run', '--keep-data']);
        self::assertStringNotContainsString('/etc/tmpfiles.d/authelia.conf', $out, 'the package and its secrets stay, so does the mask that protects them');
        self::assertStringNotContainsString('pkill', $out);

        [, $out] = $this->uninstall(['--yes', '--dry-run']);
        self::assertStringContainsString('+ rm -f -- /etc/tmpfiles.d/authelia.conf', $out);
    }

    public function testKeepDataKeepsTheStateAndItsOwner(): void
    {
        [$status, $out, $err] = $this->uninstall(['--yes', '--dry-run', '--keep-data']);

        self::assertSame(0, $status, $err);
        self::assertStringNotContainsString('-- /srv/fleet', $out);
        self::assertStringNotContainsString('userdel', $out, 'the files stay owned by the account that owns them');
        self::assertStringNotContainsString('/var/lib/authelia', $out);
        self::assertStringContainsString('kept: /srv/fleet', $out);
        self::assertStringContainsString('+ rm -rf --one-file-system -- /opt/ddev-fleet', $out, 'the code still goes');
    }

    public function testPurgePackagesRemovesThePackagesAndTheirRepositories(): void
    {
        [$status, $out, $err] = $this->uninstall(['--yes', '--dry-run', '--purge-packages']);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('apt-get -y -q purge caddy authelia ddev docker-ce', $out);
        foreach (['caddy', 'authelia', 'ddev', 'docker'] as $repo) {
            self::assertStringContainsString('+ rm -f -- /etc/apt/sources.list.d/'.$repo.'.list', $out);
        }
        self::assertStringNotContainsString('purge php', $out, 'PHP and the base tools may be used by other things');
    }

    public function testAllDestructivePathsAreGuarded(): void
    {
        $script = self::installDir().'/uninstall.sh';
        $guarded = 0;
        foreach (self::codeLines($script) as $number => $line) {
            // The two deleting commands exist only inside remove_file / remove_path (via do_cmd).
            if (1 === preg_match('/\b(rm|rmdir|chown|chmod|mv|shred|truncate)\s/', $line) && !str_contains($line, 'do_cmd')) {
                if (str_contains($line, 'printf') || str_contains($line, 'say ') || str_contains($line, 'sed ')) {
                    continue;
                }
                self::fail($script.':'.$number.' deletes or changes ownership outside do_cmd: '.trim($line));
            }
            if (1 === preg_match('/^\s*(remove_file|remove_path|restore_file)\s+(.*)$/', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[2])) ?: [] as $argument) {
                    self::assertMatchesRegularExpression('/^"\$\{[a-z_]+:\?\}[^"]*"$/', $argument, $script.':'.$number.' passes an unguarded path to '.$m[1]);
                    ++$guarded;
                }
            }
        }
        self::assertGreaterThan(30, $guarded);

        // The functions themselves refuse an empty, relative, short or climbing path.
        $source = (string) file_get_contents($script);
        self::assertStringContainsString('safe_path "${1:-}"', $source);
        self::assertStringContainsString('rm -rf --one-file-system', $source, 'a recursive removal never crosses a mount point');
    }

    public function testRemoveHelpersRefuseDangerousPaths(): void
    {
        $source = (string) file_get_contents(self::installDir().'/uninstall.sh');
        $safeFunction = $this->capture('/(  safe_path\(\) \{.*?\n  \}\n)/s', $source);
        $removeFunction = $this->capture('/(  remove_path\(\) \{.*?\n  \}\n)/s', $source);

        $marker = $this->scratch().'/should-survive';
        touch($marker);
        $code = 'dry=1; do_cmd() { printf "RAN %s\n" "$*"; }; '.$safeFunction.$removeFunction.
            'for p in "" / /etc /srv /opt /usr /var /home /root relative/path "/etc/../etc" "/srv/../" "/opt/ddev-fleet/../../etc"; do remove_path "$p" || true; done; echo done';
        [$status, $out, $err] = $this->bash(['-c', $code]);

        self::assertSame(0, $status, $err);
        self::assertStringNotContainsString('RAN', $out, 'no dangerous path reaches rm');
        self::assertStringContainsString('done', $out);
        self::assertFileExists($marker);

        [, $out] = $this->bash(['-c', 'do_cmd() { printf "RAN %s\n" "$*"; }; '.$safeFunction.$removeFunction.'remove_path /srv/fleet; remove_path /opt/ddev-fleet']);
        self::assertStringContainsString('RAN rm -rf --one-file-system -- /srv/fleet', $out);
        self::assertStringContainsString('RAN rm -rf --one-file-system -- /opt/ddev-fleet', $out);
    }

    public function testNeverMentionsSshdUfwIptables(): void
    {
        $source = strtolower((string) file_get_contents(self::installDir().'/uninstall.sh'));

        foreach (['sshd', 'ufw', 'iptables', 'ip6tables', 'fail2ban', 'nftables', 'sshd_config', 'authorized_keys'] as $word) {
            self::assertStringNotContainsString($word, $source, 'the uninstaller must not touch '.$word);
        }
    }

    public function testNothingInTheInstallerRunsTheUninstaller(): void
    {
        foreach (self::installFiles() as $file) {
            if (str_ends_with($file, '/uninstall.sh')) {
                continue;
            }
            foreach (self::codeLines($file) as $number => $line) {
                self::assertStringNotContainsString('uninstall.sh', $line, $file.':'.$number);
            }
        }
    }

    public function testHelpPointsAtTheRootOwnedCopyNotTheCheckout(): void
    {
        [$status, $out, $err] = $this->uninstall(['--help']);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh', $out);
        self::assertStringNotContainsString('sudo bash /opt/ddev-fleet', $out, 'the checkout belongs to the unprivileged fleet user');
        $source = (string) file_get_contents(self::installDir().'/uninstall.sh');
        self::assertStringNotContainsString('sudo bash /opt/ddev-fleet', $source);
        self::assertStringContainsString('sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh', (string) file_get_contents(self::installDir().'/../docs/installation.md'));
    }

    public function testARealRunRefusesAScriptThatIsNotRootOwned(): void
    {
        // The copy in the scratch directory belongs to the test user, as the checkout belongs to `fleet`. `id` says root,
        // so the refusal is the location check, not the missing-root check.
        $copy = $this->scratch().'/uninstall-copy.sh';
        copy(self::installDir().'/uninstall.sh', $copy);
        chmod($copy, 0o755); // not group/other-writable whatever the umask, so only the owner check can refuse
        if (0 === posix_geteuid()) {
            // CI runs the suite as root: hand the copy to another user so it is "not owned by root" there too.
            chown($copy, 65534);
        }
        $stubs = $this->stubs(['id' => 'echo 0']);

        [$status, $out, $err] = $this->bash([$copy, '--yes'], [], null, $stubs);

        self::assertSame(78, $status, $err);
        self::assertStringContainsString('refusing to run as root from here', $err);
        self::assertStringContainsString('is not owned by root', $err);
        self::assertStringContainsString('/usr/local/lib/ddev-fleet-installer/install/uninstall.sh', $err, 'it names the copy to use');
        self::assertStringNotContainsString('+ ', $out, 'nothing was planned, let alone removed');
    }

    public function testADryRunWorksFromAnywhere(): void
    {
        $copy = $this->scratch().'/uninstall-copy.sh';
        copy(self::installDir().'/uninstall.sh', $copy);

        [$status, , $err] = $this->bash([$copy, '--yes', '--dry-run']);

        self::assertSame(0, $status, $err);
    }

    public function testLocationCheckAcceptsOnlyAPathOwnedByRootAndClosedToWriters(): void
    {
        $me = (string) posix_geteuid();
        $good = $this->scratch().'/trusted';
        mkdir($good, 0o755);
        chmod($good, 0o755);
        file_put_contents($good.'/script.sh', '#');
        chmod($good.'/script.sh', 0o755);
        $groupWritableFile = $this->scratch().'/trusted/gw.sh';
        file_put_contents($groupWritableFile, '#');
        chmod($groupWritableFile, 0o775);
        $worldWritableDir = $this->scratch().'/open';
        mkdir($worldWritableDir, 0o777);
        chmod($worldWritableDir, 0o777);
        file_put_contents($worldWritableDir.'/script.sh', '#');
        chmod($worldWritableDir.'/script.sh', 0o755);

        $check = static fn (string $path, string $uid): string => 'source '.escapeshellarg(self::installDir().'/uninstall.sh').'; location_problem '.escapeshellarg($path).' '.$uid.'; echo " rc=$?"';

        // Everything above the scratch directory must be closed too, so only compare the verdict about our own parts.
        [, $out] = $this->bash(['-c', $check($good.'/script.sh', $me)]);
        self::assertStringNotContainsString($good, $out, 'owned by the trusted uid, no group/other write bit on the file or its directory');

        [, $out] = $this->bash(['-c', $check($good.'/script.sh', '0')]);
        if ('0' !== $me) {
            self::assertStringContainsString('is not owned by root', $out, 'a file owned by someone else is refused');
            self::assertStringContainsString('rc=1', $out);
        }

        [, $out] = $this->bash(['-c', $check($groupWritableFile, $me)]);
        self::assertStringContainsString($groupWritableFile.' is writable by group or others', $out);
        self::assertStringContainsString('rc=1', $out);

        [, $out] = $this->bash(['-c', $check($worldWritableDir.'/script.sh', $me)]);
        self::assertStringContainsString($worldWritableDir.' is writable by group or others', $out, 'a writable directory lets the file be swapped');
        self::assertStringContainsString('rc=1', $out);

        // The positive case: a system file, root-owned all the way up.
        [, $out] = $this->bash(['-c', $check('/usr/bin/env', '0')]);
        self::assertSame(" rc=0\n", $out);
    }

    public function testTheStageCopyIsNotGroupWritable(): void
    {
        // git archive stamps 0775; the root-owned copy the uninstaller runs from must not keep that.
        self::assertStringContainsString('chmod -R go-w "$stage_dir.new"', (string) file_get_contents(self::installDir().'/install.sh'));
    }

    public function testHelpExplainsTheFlags(): void
    {
        [$status, $out, $err] = $this->uninstall(['--help']);

        self::assertSame(0, $status, $err);
        foreach (['--yes', '--keep-data', '--purge-packages', '--dry-run'] as $flag) {
            self::assertStringContainsString($flag, $out);
        }
    }

    /** The first group of a pattern that must match. */
    private function capture(string $pattern, string $subject): string
    {
        self::assertSame(1, preg_match($pattern, $subject, $match), $pattern);

        return $match[1];
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function uninstall(array $args): array
    {
        return $this->bash([self::installDir().'/uninstall.sh', ...$args]);
    }
}
