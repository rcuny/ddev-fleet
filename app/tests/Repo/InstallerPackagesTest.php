<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * What the dry-run plan installs and from where.
 */
final class InstallerPackagesTest extends TestCase
{
    use InstallerHarness;

    public function testDryRunListsPhpExtensionsAndTools(): void
    {
        [$status, $out] = $this->dryRun();
        self::assertSame(0, $status);

        $packages = $this->packagesOf($out, 'php8.4-fpm');
        foreach ([
            'ca-certificates', 'curl', 'git', 'sudo', 'acl', 'openssl', 'unzip', 'jq', 'composer',
            'openssh-client', 'libnss3-tools',
            'php8.4-fpm', 'php8.4-cli', 'php8.4-opcache', 'php8.4-sqlite3', 'php8.4-intl', 'php8.4-mbstring',
            'php8.4-xml', 'php8.4-curl', 'php8.4-zip',
        ] as $expected) {
            self::assertContains($expected, $packages, $expected.' is missing from the base package list');
        }
        self::assertContains('docker-ce', $this->packagesOf($out, 'docker-ce'));
        foreach (['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin'] as $expected) {
            self::assertContains($expected, $this->packagesOf($out, 'docker-ce'));
        }
        self::assertSame(['ddev'], $this->packagesOf($out, 'ddev'));
        self::assertSame(['caddy'], $this->packagesOf($out, 'caddy'));
        self::assertSame(['authelia'], $this->packagesOf($out, 'authelia'));
    }

    public function testRepositoriesAreTheDocumentedOnes(): void
    {
        [, $out] = $this->dryRun();

        preg_match_all('/^\+ write \/etc\/apt\/sources\.list\.d\/(\w+)\.list: (deb .*)$/m', $out, $matches, \PREG_SET_ORDER);
        $lines = [];
        foreach ($matches as $match) {
            $lines[$match[1]] = $match[2];
        }

        self::assertSame(['docker', 'ddev', 'caddy', 'authelia'], array_keys($lines), 'exactly these four third-party repositories, in this order');
        self::assertMatchesRegularExpression('~^deb \[arch=(amd64|arm64) signed-by=/etc/apt/keyrings/docker\.asc\] https://download\.docker\.com/linux/debian trixie stable$~', $lines['docker']);
        self::assertSame('deb [signed-by=/etc/apt/keyrings/ddev.asc] https://pkg.ddev.com/apt/ * *', $lines['ddev']);
        self::assertSame('deb [signed-by=/etc/apt/keyrings/caddy.asc] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main', $lines['caddy']);
        self::assertSame('deb [signed-by=/etc/apt/keyrings/authelia-security.gpg] https://apt.authelia.com stable main', $lines['authelia']);

        foreach ([
            'https://download.docker.com/linux/debian/gpg -> /etc/apt/keyrings/docker.asc',
            'https://pkg.ddev.com/apt/gpg.key -> /etc/apt/keyrings/ddev.asc',
            'https://dl.cloudsmith.io/public/caddy/stable/gpg.key -> /etc/apt/keyrings/caddy.asc',
            'https://www.authelia.com/keys/authelia-security.gpg -> /etc/apt/keyrings/authelia-security.gpg',
        ] as $key) {
            self::assertStringContainsString('+ fetch '.$key, $out);
        }
        // Every repository is pinned to its own key: no apt-key, no trusted=yes.
        self::assertStringNotContainsString('trusted=yes', $out);
        self::assertStringNotContainsString('apt-key', $out);
    }

    public function testNoTmuxOrSuryRepo(): void
    {
        [, $out] = $this->dryRun();
        $all = $out.implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), self::installFiles()));

        self::assertDoesNotMatchRegularExpression('/\btmux\b/i', $all, 'tmux belongs to M0.11/12, not the installer');
        self::assertStringNotContainsStringIgnoringCase('sury', $all, 'PHP comes from Debian 13, not the sury repository');
        self::assertStringNotContainsString('packages.sury.org', $all);
        self::assertStringNotContainsString('add-apt-repository', $all);
    }

    public function testEveryAptCallKeepsConfigurationFilesAndNeverPrompts(): void
    {
        [, $out] = $this->dryRun();

        $calls = 0;
        foreach (explode("\n", $out) as $line) {
            if (!str_contains($line, 'apt-get ')) {
                continue;
            }
            ++$calls;
            foreach (['DEBIAN_FRONTEND=noninteractive', ' -y ', '-o Dpkg::Options::=--force-confdef', '-o Dpkg::Options::=--force-confold', '-o DPkg::Lock::Timeout=120'] as $needle) {
                self::assertStringContainsString($needle, $line, $line);
            }
        }
        self::assertGreaterThanOrEqual(12, $calls, 'stage 1 and stage 2 both run apt');
    }

    public function testAptGetIsOnlyCalledThroughTheTwoWrappers(): void
    {
        foreach (self::installFiles() as $file) {
            foreach (self::codeLines($file) as $number => $line) {
                if (1 === preg_match('/^\s*(run|step|apt-get|env)\b.*\bapt-get\b/', $line)) {
                    $name = basename($file);
                    self::assertContains($name, ['common.sh', 'install.sh'], $file.':'.$number.' calls apt-get directly; use apt_get/apt_install');
                    self::assertMatchesRegularExpression('/^\s*(run env DEBIAN_FRONTEND|step env DEBIAN_FRONTEND)/', $line, $file.':'.$number);
                }
            }
        }
    }

    public function testPhpIsDebiansOwnEightFour(): void
    {
        [, $out] = $this->dryRun();

        self::assertDoesNotMatchRegularExpression('/php(?!8\.4)\d\.\d/', $out, 'only php8.4 packages');
    }

    public function testBitbucketGatesInstallTheToolsTheRealBinaryTestsNeed(): void
    {
        $pipeline = (string) file_get_contents(self::productRoot().'/bitbucket-pipelines.yml');

        self::assertMatchesRegularExpression('/php8\.4-zip\s+composer git unzip curl ca-certificates gnupg openssh-client shellcheck sudo\b/', $pipeline);
    }

    /**
     * The package names of the apt-get install line that mentions $marker.
     *
     * @return list<string>
     */
    private function packagesOf(string $plan, string $marker): array
    {
        foreach (explode("\n", $plan) as $line) {
            if (str_contains($line, ' DPkg::Lock::Timeout=120 install ') && str_contains($line, $marker)) {
                $tail = substr($line, (int) strpos($line, ' DPkg::Lock::Timeout=120 install ') + \strlen(' DPkg::Lock::Timeout=120 install '));

                return array_values(array_filter(explode(' ', trim($tail))));
            }
        }

        self::fail('no apt-get install line mentions '.$marker);
    }
}
