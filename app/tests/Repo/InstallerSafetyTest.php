<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Static guards over install/**: the things the installer must never do, whatever a step looks like next month.
 * Comment lines are exempt (they explain the rules); code lines are not.
 */
final class InstallerSafetyTest extends TestCase
{
    use InstallerHarness;

    public function testNoScriptTouchesSshdUfwFail2banIptables(): void
    {
        // Lessons of the lock-out incidents: the installer must not change sshd, the firewall or fail2ban at all.
        $hits = $this->grepCode('/\b(sshd|sshd_config|ufw|fail2ban|iptables|ip6tables|nftables|nft)\b/i');

        self::assertSame([], $hits, "forbidden host-hardening references:\n".implode("\n", $hits));
    }

    public function testNoScriptTouchesRemoteAccessOrTheFirewallThroughAnyOtherDoor(): void
    {
        // The first guard names the daemons. The same lock-out comes from the unit (ssh.service / ssh.socket), the
        // config tree, root's or a user's authorized keys, another firewall front end, a package that brings one in, or
        // a kernel switch that changes how packets are handled. None of them may appear in code or in any shipped file.
        $hits = $this->grepCode('~(firewalld|firewall-cmd|\bnftables?\b|\bnft\s|netfilter|iptables-(save|restore)|ip6tables|\bufw\b|\bfail2ban|'
            .'\bssh(d)?\.(service|socket)\b|systemctl\s+\S+\s+(--now\s+)?ssh(d)?\b|/etc/ssh\b|openssh-server|PermitRootLogin|PasswordAuthentication|'
            .'authorized_keys|/root/\.ssh|/home/[a-z]+/\.ssh|\bnet\.ipv[46]\.(ip_forward|conf)|DOCKER-USER|/etc/hosts\.(allow|deny)|\bsshguard\b|\bcsf\b)~i');

        self::assertSame([], $hits, "remote-access or firewall changes:\n".implode("\n", $hits));
    }

    public function testHardeningIsOnlyTheGatedRoleAndTheBashEngineIsGone(): void
    {
        // FLE-263: the bash steps and install/harden/ no longer exist; the opt-in hardening is the Ansible role, run by
        // site.yml only when the host answers say so.
        self::assertDirectoryDoesNotExist(self::installDir().'/steps');
        self::assertDirectoryDoesNotExist(self::installDir().'/harden');
        $site = (string) file_get_contents(self::installDir().'/ansible/site.yml');
        self::assertMatchesRegularExpression('/- role: hardening\n\s+when: fleet_hardening_enabled/', $site);
        foreach (self::installFiles() as $file) {
            if (str_starts_with($file, self::installDir().'/ansible/')) {
                continue;
            }
            self::assertStringNotContainsString('harden/harden.sh', (string) file_get_contents($file), $file);
        }
    }

    public function testOnlyTheSshClientIsInstalled(): void
    {
        // ssh-keygen and ssh-keyscan come from openssh-client; the server package would also start sshd.
        $vars = (string) file_get_contents(self::installDir().'/ansible/group_vars/all.yml');

        self::assertStringContainsString('- openssh-client', $vars);
        self::assertStringNotContainsString('openssh-server', $vars);
        self::assertStringNotContainsString('openssh-sftp-server', $vars);
    }

    public function testEveryAptRepositoryPinsTheFingerprintOfItsSigningKey(): void
    {
        $vars = (string) file_get_contents(self::installDir().'/ansible/group_vars/all.yml');

        foreach ([
            'docker' => '9DC858229FC7DD38854AE2D88D81803C0EBFCD88',
            'ddev' => 'DBD730A3A581BDAC98BAF17FC16711FC1179C7CA',
            'caddy' => '65760C51EDEA2017CEA2CA15155B6D79CA56EA34',
            'authelia' => '192085915BD608A458AC58DCE461FA1531286EEA',
        ] as $name => $fingerprint) {
            self::assertStringContainsString("fleet_{$name}_key_fingerprint: {$fingerprint}\n", $vars);
        }
        self::assertSame(4, preg_match_all('/^fleet_\w+_key_fingerprint: [0-9A-F]{40}$/m', $vars), 'docker, ddev, caddy and authelia');
        // The shared role refuses a key whose fingerprint differs.
        self::assertStringContainsString('fingerprint', (string) file_get_contents(self::installDir().'/ansible/roles/apt_repo/tasks/main.yml'));
    }

    public function testNoRecursiveChownToRootOnOpt(): void
    {
        $root = $this->grepCode('/\bchown\b.*(-[a-zA-Z]*R|--recursive).*\broot\b/');
        self::assertSame([], $root, "chown -R to root:\n".implode("\n", $root));

        foreach (self::installFiles() as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                if (1 === preg_match('/\bchown\b.*-[a-zA-Z]*R/', $line) && str_contains($line, 'OPT')) {
                    self::assertMatchesRegularExpression('/\bfleet:fleet\b/', $line, $file.':'.$number.' must hand /opt/ddev-fleet to fleet:fleet');
                }
            }
        }
    }

    public function testEveryCurlUsesFailSilentHttps(): void
    {
        $curls = 0;
        foreach (self::installFiles() as $file) {
            $lines = self::codeLines($file);
            foreach ($lines as $number => $line) {
                // "curl" as a command word, not as a package name in an apt line.
                if (1 !== preg_match('/(^|[\s|;&(])curl\s/', $line) || str_contains($line, 'ca-certificates')) {
                    continue;
                }
                ++$curls;
                self::assertMatchesRegularExpression('/\s-[a-zA-Z]*f[a-zA-Z]*\b|--fail\b/', $line, $file.':'.$number.' curl without -f');
                self::assertDoesNotMatchRegularExpression('/\bhttp:\/\/(?!127\.0\.0\.1|localhost)/', $line, $file.':'.$number.' plain http');
                self::assertDoesNotMatchRegularExpression('/\s(-k|--insecure)\b/', $line, $file.':'.$number.' disables TLS verification');
            }
        }
        self::assertGreaterThanOrEqual(2, $curls, 'expected the download helper and the public-address lookup');
    }

    public function testNoPlainHttpUrlsExceptLoopback(): void
    {
        $hits = $this->grepCode('~\bhttp://(?!127\.0\.0\.1|localhost)~');

        self::assertSame([], $hits, "plain http urls:\n".implode("\n", $hits));
    }

    public function testNothingPipesIntoWriteFile(): void
    {
        // write_file reports a change through the FILE_CHANGED global; a pipeline stage is a subshell, so the caller
        // would never see it and the restarts of step 98 would silently be skipped.
        $hits = $this->grepCode('/\|\s*write_file(_once)?\b/');

        self::assertSame([], $hits, "a pipe into write_file:\n".implode("\n", $hits));
    }

    public function testNoShellTrace(): void
    {
        // A trace prints every command, secrets included, into the install log.
        $hits = $this->grepCode('/\bset\s+-[a-zA-Z]*x|\bbash\s+-[a-zA-Z]*x|xtrace|\bsh\s+-x\b/');

        self::assertSame([], $hits, "shell tracing:\n".implode("\n", $hits));
    }

    public function testGitNeverRunsAsRoot(): void
    {
        // /opt/ddev-fleet is owned by fleet; git as root there fails with "dubious ownership" on the next run.
        $checked = 0;
        foreach (self::installFiles() as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                // git as a command word (start of line, after a wrapper or env), not words in the usage text.
                if (1 === preg_match('/(^\s*|\bas_fleet\s+|\benv\s+(\S+\s+)*)git\s+(-C\s+\S+\s+)?(clone|fetch|checkout|merge|archive|rev-parse|remote|status|pull|reset|config)\b/', $line)) {
                    ++$checked;
                    self::assertMatchesRegularExpression('/\bas_fleet\b|\brunuser -u fleet\b|\brun_as_fleet\b/', $line, $file.':'.$number.' runs git without runuser -u fleet');
                }
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    public function testStageTwoRunsFromARootOwnedExportNotFromTheFleetCheckout(): void
    {
        $install = (string) file_get_contents(self::installDir().'/install.sh');

        self::assertStringContainsString('git -C "$opt_dir" archive', $install);
        self::assertStringContainsString('--no-same-owner', $install);
        self::assertStringContainsString('exec env "FLEET_SRC_DIR=$opt_dir" bash "$stage_dir/install/run.sh"', $install);
        self::assertStringNotContainsString('bash "$opt_dir/install', $install);
    }

    public function testChildrenNeverReadStdin(): void
    {
        $run = (string) file_get_contents(self::installDir().'/run.sh');
        $install = (string) file_get_contents(self::installDir().'/install.sh');
        $common = (string) file_get_contents(self::installDir().'/lib/common.sh');

        self::assertStringContainsString('exec </dev/null', $run);
        self::assertStringContainsString('exec </dev/null', $install);
        self::assertStringContainsString('"$@" </dev/null', $common, 'run() closes stdin for every command');
        self::assertStringContainsString('"$@" </dev/null', $install, 'install.sh step() closes stdin too');
        self::assertStringContainsString('set -Eeuo pipefail', $run);
        self::assertStringContainsString('set -Eeuo pipefail', $install);
        self::assertStringContainsString("trap 'on_error", $run);
    }

    public function testAptNeverPromptsAndEverythingIsNonInteractive(): void
    {
        $common = (string) file_get_contents(self::installDir().'/lib/common.sh');

        self::assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $common);
        self::assertStringContainsString('-o Dpkg::Options::=--force-confold', $common);
    }

    public function testNoDirectDownloadsOutsideTheHelper(): void
    {
        // Downloads go through fetch() (https only, fail on error, retries); wget and ad-hoc pipes are out.
        $hits = $this->grepCode('/\bwget\b|\|\s*(sudo\s+)?(ba)?sh\b/');

        self::assertSame([], $hits, "wget or pipe-to-shell:\n".implode("\n", $hits));
    }

    public function testNothingInTheInstallerMintsOrPrintsTheSetupUrl(): void
    {
        // The one-time URL is a credential for 24 hours: no installer script and no playbook task may run the token
        // command, so it can never land in the install log or an Ansible log. The playbook only says how to get it.
        foreach (self::installFiles() as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                self::assertStringNotContainsString('fleet:setup:token', $line, $file.':'.$number);
                self::assertStringNotContainsString('setup?token', $line, $file.':'.$number);
            }
        }
        $finish = (string) file_get_contents(self::installDir().'/ansible/roles/units/tasks/finish.yml');
        self::assertStringContainsString('sudo fleet setup:token', $finish);
        self::assertStringNotContainsString('ansible.builtin.command', substr($finish, (int) strpos($finish, 'Say how to get the setup URL')));
    }

    public function testDryRunNeverPrintsAUrlOrRunsTheTokenCommand(): void
    {
        [, $out] = $this->dryRun();

        self::assertStringNotContainsString('token=', $out);
        self::assertStringNotContainsString('setup:token', $out);
    }

    /**
     * FLE-299 (owner decision 2026-11-12): instance network isolation is the one deliberate nftables user outside the hardening role.
     * It owns the table `inet fleet_isolation` only, filters container traffic only (bridge interfaces) and is off until an admin
     * runs `fleet host:isolation enable`. The exception is narrow: the boot unit, and the lines of the root helper that name that
     * table (testTheIsolationExceptionStaysNarrow pins what the two files may contain).
     */
    private static function isolationException(string $file, string $line): bool
    {
        if ($file === self::installDir().'/etc/fleet-isolation.service') {
            return true;
        }

        return $file === self::installDir().'/libexec/apply'
            && 1 === preg_match('/fleet_isolation|ISOLATION_TABLE|"nftables":\[\]|command -v nft /', $line);
    }

    public function testTheIsolationExceptionStaysNarrow(): void
    {
        $unit = (string) file_get_contents(self::installDir().'/etc/fleet-isolation.service');
        $helper = (string) file_get_contents(self::installDir().'/libexec/apply');

        foreach ([$unit, $helper] as $text) {
            self::assertDoesNotMatchRegularExpression('/flush\s+ruleset|iptables|\bufw\b|sshd|fail2ban|DOCKER-USER|ip_forward/i', implode("\n", array_filter(explode("\n", $text), static fn (string $l): bool => !str_starts_with(ltrim($l), '#'))));
        }
        self::assertStringContainsString('ConditionPathExists=/etc/ddev-fleet/fleet_isolation.nft', $unit, 'a host with isolation off does nothing at boot');
        // The ruleset the app renders only filters traffic that enters from container bridges.
        $ruleset = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Fleet/Isolation/IsolationRuleset.php');
        self::assertStringNotContainsString('iifname "eth', $ruleset);
        self::assertStringNotContainsString('oifname "eth', $ruleset);
    }

    /**
     * @return list<string> "file:line: text" for every non-comment line matching the pattern
     */
    private function grepCode(string $pattern): array
    {
        $hits = [];
        foreach (self::installFiles() as $file) {
            // The opt-in hardening lives in install/ansible/roles/hardening/ and nowhere else (see testHardeningIsOnlyTheGatedRoleAndTheBashEngineIsGone).
            if (str_starts_with($file, self::installDir().'/ansible/')) {
                continue;
            }
            foreach (self::codeLines($file) as $number => $line) {
                if (self::isolationException($file, $line)) {
                    continue;
                }
                if (1 === preg_match($pattern, $line)) {
                    $hits[] = str_replace(self::installDir().'/', '', $file).':'.$number.': '.trim($line);
                }
            }
        }

        return $hits;
    }
}
