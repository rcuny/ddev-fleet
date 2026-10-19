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
        self::assertStringContainsString('apt-get install -y', $common);
    }

    public function testNoDirectDownloadsOutsideTheHelper(): void
    {
        // Downloads go through fetch() (https only, fail on error, retries); wget and ad-hoc pipes are out.
        $hits = $this->grepCode('/\bwget\b|\|\s*(sudo\s+)?(ba)?sh\b/');

        self::assertSame([], $hits, "wget or pipe-to-shell:\n".implode("\n", $hits));
    }

    /**
     * @return list<string> "file:line: text" for every non-comment line matching the pattern
     */
    private function grepCode(string $pattern): array
    {
        $hits = [];
        foreach (self::installFiles() as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                if (1 === preg_match($pattern, $line)) {
                    $hits[] = str_replace(self::installDir().'/', '', $file).':'.$number.': '.trim($line);
                }
            }
        }

        return $hits;
    }
}
