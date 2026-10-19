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
            '+ systemctl disable --now fleet-worker@\*.service',
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
