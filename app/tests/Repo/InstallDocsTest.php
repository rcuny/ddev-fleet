<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The installation guide, the README and CLAUDE.md must stay in step with the installer and the console commands:
 * a documented flag that does not exist (or an existing one nobody documented) is caught here, not by a user.
 */
final class InstallDocsTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $file = self::root().'/'.$relative;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * @return list<string>
     */
    private static function installerFlags(): array
    {
        $usage = self::read('install/install.sh');
        if (1 !== preg_match('/<<\'USAGE\'\R(.*?)\RUSAGE\R/s', $usage, $block)) {
            self::fail('usage block of install.sh not found');
        }
        preg_match_all('/^\s+(--[a-z][a-z-]*)/m', $block[1], $matches);

        $flags = array_values(array_unique($matches[1]));
        self::assertGreaterThanOrEqual(7, \count($flags), 'install.sh documents its flags in its usage text');

        return $flags;
    }

    public function testInstallationDocNamesEveryInstallerFlag(): void
    {
        $doc = self::read('docs/installation.md');

        foreach (self::installerFlags() as $flag) {
            self::assertStringContainsString($flag, $doc, "docs/installation.md does not document install.sh $flag");
        }
        // The uninstaller's flags are documented in the same guide.
        $uninstall = self::read('install/uninstall.sh');
        preg_match_all('/^\s+(--(?:yes|keep-data|purge-packages|dry-run))\)/m', $uninstall, $matches);
        self::assertCount(4, array_unique($matches[1]));
        foreach (array_unique($matches[1]) as $flag) {
            self::assertStringContainsString($flag, $doc, "docs/installation.md does not document uninstall.sh $flag");
        }
    }

    public function testInstallationDocNamesEveryConsoleCommandAndEveryEnvironmentKey(): void
    {
        $doc = self::read('docs/installation.md');

        $commands = glob(self::root().'/app/src/Command/*.php') ?: [];
        self::assertNotEmpty($commands);
        foreach ($commands as $file) {
            if (1 !== preg_match("/name: '(fleet:[a-z:-]+)'/", (string) file_get_contents($file), $name)) {
                self::fail("no command name in $file");
            }
            self::assertStringContainsString($name[1], $doc, "docs/installation.md does not mention {$name[1]}");
            self::assertStringContainsString(substr($name[1], \strlen('fleet:')), $doc);
        }

        // Every KEY=VALUE the installer writes to fleet.env is explained.
        $env = self::read('install/steps/70-env.sh');
        if (1 !== preg_match('/env_ensure "\$env_file"(.*?)\n\n/s', $env, $block)) {
            self::fail('env_ensure call not found in 70-env.sh');
        }
        preg_match_all('/^\s*"?([A-Z][A-Z_]+)=/m', $block[1], $keys);
        self::assertGreaterThanOrEqual(10, \count($keys[1]));
        foreach ($keys[1] as $key) {
            self::assertStringContainsString($key, $doc, "docs/installation.md does not explain $key in fleet.env");
        }
    }

    /**
     * The other direction: nothing in the guide may name a flag or a command the code does not have.
     */
    public function testInstallationDocNamesNoFlagOrCommandThatDoesNotExist(): void
    {
        $doc = self::read('docs/installation.md');

        $known = self::installerFlags();
        preg_match_all('/^\s+(--[a-z][a-z-]*)\)/m', self::read('install/uninstall.sh'), $uninstallFlags);
        $known = [...$known, ...$uninstallFlags[1]];
        $commandNames = [];
        foreach (glob(self::root().'/app/src/Command/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/addOption\('([a-z][a-z-]*)'/", $source, $options);
            foreach ($options[1] as $option) {
                $known[] = '--'.$option;
            }
            if (1 === preg_match("/name: '(fleet:[a-z:-]+)'/", $source, $name)) {
                $commandNames[] = $name[1];
            }
        }
        // Flags of other programs the guide quotes.
        $known = [...$known, '--no-dev', '--no-pager', '--help', '--exclude', '--config', '--adapter'];

        // A flag is "--name" not glued to a word or a colon (`authelia:--x` is an ACL, not a flag).
        preg_match_all('/(?<![\w:-])(--[a-z][a-z-]*)/', $doc, $flags);
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), $known)), 'docs/installation.md names flags nothing implements');

        // `fleet:group:verb` is a console command; `fleet:fleet` and `fleet:caddy` are owner:group pairs.
        preg_match_all('/\bfleet:[a-z]+:[a-z-]+/', $doc, $commands);
        self::assertSame([], array_values(array_diff(array_unique($commands[0]), $commandNames)), 'docs/installation.md names commands that do not exist');
    }

    public function testInstallationDocRecordsTheOperatorFacingLimits(): void
    {
        $doc = self::read('docs/installation.md');

        foreach ([
            'ssh -t',                                       // the password reset needs a terminal
            'sudo fleet setup:reset',                       // recovery
            'user:reset-password',
            'ovh-eu', 'ovh-ca', 'ovh-us',                   // the OVH endpoint allow-list
            'libdns/ovh',                                   // where the OVH rights list comes from
            '/domain/zone/<zone>/refresh',                       // ... and the list itself
            'auth.fleet.example.com',                       // HTTP-01 needs a record for the login host too
            'every signed-in session',                      // an Authelia restart drops every session
            'is a credential for 24 hours',                 // the setup URL is not "one-time"
            'about **three minutes**',                      // a rebooted bare-metal box can be unreachable that long
            '/usr/local/lib/ddev-fleet-installer/install/uninstall.sh', // the root-owned copy
            '**Back up first.**',                           // uninstall deletes the data
            '--keep-data',
            'clear text',                                   // OVH credentials in /etc/caddy/fleet.env
            '`root:caddy` 0640',
            '`/hooks/*`',                                   // bypasses forward_auth
            '`one_factor`',                                 // the Authelia policy
            'sshd',                                         // what the installer leaves alone
            'ufw',
        ] as $needle) {
            self::assertStringContainsString($needle, $doc, "docs/installation.md should mention '$needle'");
        }
        self::assertStringNotContainsString('one-time', $doc, 'the setup URL is a 24 hour credential, not one-time');
        self::assertStringNotContainsString('/opt/ddev-fleet/install/uninstall.sh', $doc, 'root must run the uninstaller from the root-owned copy');
    }

    public function testReadmeMentionsInstallCommand(): void
    {
        $readme = self::read('README.md');

        self::assertStringContainsString('install/install.sh', $readme);
        self::assertStringContainsString('--domain=fleet.example.com', $readme);
        self::assertStringContainsString('docs/installation.md', $readme);
        self::assertStringNotContainsString('not ready to deploy', $readme, 'README status text predates the installer');
    }

    public function testClaudeMdListsInstallDirAndNewNamespaces(): void
    {
        $claude = self::read('CLAUDE.md');

        self::assertStringContainsString('`install/`', $claude);
        foreach ([
            'App\Setup', 'App\Fleet\Secrets', 'App\Fleet\Ssh', 'App\Fleet\Auth', 'App\Fleet\System',
            'App\Domain\Host', 'App\Domain\User', 'App\Domain\Ssh', 'App\Domain\Secret', 'App\Controller\Setup',
            'PrivilegedApply', 'SecretBox', 'FLEET_PROXY_AUTH', 'libexec/apply',
        ] as $needle) {
            self::assertStringContainsString($needle, $claude, "CLAUDE.md should list $needle");
        }
        // The K26 limitation is closed: the text must not claim it still holds.
        self::assertStringNotContainsString('Known limitation: trusted proxy', $claude);
        self::assertStringNotContainsString('must not face the Internet yet', $claude);
        self::assertStringNotContainsString('empty until M0.02', $claude);
    }

    public function testLegacyAnsibleBannerExistsAndSaysWhatItMeans(): void
    {
        $banner = self::read('ansible/LEGACY-0x.md');

        foreach (['0.x', '1.x', 'not used', 'develop', 'sshd', 'hardening'] as $needle) {
            self::assertStringContainsString($needle, $banner);
        }
    }

    public function testChangelogUnreleasedDescribesTheInstaller(): void
    {
        $unreleased = UnreleasedChangelog::text(self::root());

        foreach (['FLE-36', 'install.sh', 'setup wizard', 'fleet setup:reset', 'FLEET_PROXY_AUTH'] as $needle) {
            self::assertStringContainsString($needle, $unreleased, "[Unreleased] should mention $needle");
        }
        self::assertStringNotContainsString('do not expose the 1.x app to the Internet yet', $unreleased);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsWithRelativeLinks(): iterable
    {
        foreach (['README.md', 'docs/installation.md', 'docs/projects-and-templates.md', 'docs/README.md', 'docs/development.md', 'ansible/LEGACY-0x.md', 'tests/README-tests.md'] as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('documentsWithRelativeLinks')]
    public function testRelativeLinksResolve(string $relative): void
    {
        $text = self::read($relative);
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', $text, $links);

        $broken = [];
        foreach ($links[1] as $target) {
            if (1 === preg_match('~^[a-z][a-z0-9+.-]*:~i', $target)) {
                continue;
            }
            $path = \dirname(self::root().'/'.$relative).'/'.$target;
            if (!file_exists($path)) {
                $broken[] = $relative.' -> '.$target;
            }
        }

        self::assertSame([], $broken);
    }
}
