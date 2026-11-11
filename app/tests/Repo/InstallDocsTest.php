<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The installer reference, the configuration reference, the README and CLAUDE.md must stay in step with the installer and the console commands:
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

    /** Every page of the user documentation, concatenated: for phrases that may live in any of them. */
    private static function allDocs(): string
    {
        $text = self::read('README.md');
        foreach (self::markdownFiles() as $file) {
            $text .= "\n".self::read($file);
        }

        return $text;
    }

    /**
     * @return list<string> docs/**\/*.md, relative to the repository root
     */
    public static function markdownFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/docs', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('md' === $file->getExtension()) {
                $files[] = 'docs/'.substr($file->getPathname(), \strlen(self::root().'/docs/'));
            }
        }
        sort($files);

        return $files;
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
        $doc = self::read('docs/reference/04-installer.md');

        foreach (self::installerFlags() as $flag) {
            self::assertStringContainsString($flag, $doc, "docs/reference/04-installer.md does not document install.sh $flag");
        }
        // The uninstaller's flags are documented in the same guide.
        $uninstall = self::read('install/uninstall.sh');
        preg_match_all('/^\s+(--(?:yes|keep-data|purge-packages|dry-run))\)/m', $uninstall, $matches);
        self::assertCount(4, array_unique($matches[1]));
        foreach (array_unique($matches[1]) as $flag) {
            self::assertStringContainsString($flag, $doc, "docs/reference/04-installer.md does not document uninstall.sh $flag");
        }
    }

    public function testTheReferencesNameEveryConsoleCommandAndEveryEnvironmentKey(): void
    {
        $cli = self::read('docs/reference/01-cli.md');
        $configuration = self::read('docs/reference/02-configuration.md');

        $commands = glob(self::root().'/app/src/Command/*.php') ?: [];
        self::assertNotEmpty($commands);
        foreach ($commands as $file) {
            if (1 !== preg_match("/name: '(fleet:[a-z:-]+)'/", (string) file_get_contents($file), $name)) {
                self::fail("no command name in $file");
            }
            self::assertStringContainsString('fleet '.substr($name[1], \strlen('fleet:')), $cli, "docs/reference/01-cli.md does not mention {$name[1]}");
        }

        // Every KEY=VALUE the installer writes to fleet.env is explained.
        $env = self::read('install/ansible/roles/php_app/defaults/main.yml');
        preg_match_all('/^  - \{key: ([A-Z][A-Z_]+),/m', $env, $keys);
        self::assertGreaterThanOrEqual(10, \count($keys[1]));
        foreach ($keys[1] as $key) {
            self::assertStringContainsString($key, $configuration, "docs/reference/02-configuration.md does not explain $key in fleet.env");
        }
    }

    /**
     * The other direction: nothing in the guide may name a flag or a command the code does not have.
     */
    public function testInstallationDocDescribesTheUnitsAndTheRetentionSetting(): void
    {
        $files = self::read('docs/reference/03-files-on-disk.md');

        foreach (['fleet-status', 'fleet-boot', 'fleet-worker@2'] as $needle) {
            self::assertStringContainsString($needle, $files);
        }
        self::assertStringContainsString('fleet:instance:start --all --sequential', $files);
        self::assertStringContainsString('FLEET_JOB_RETENTION_DAYS', self::read('docs/reference/02-configuration.md'));
    }

    public function testInstallerReferenceNamesNoFlagOrCommandThatDoesNotExist(): void
    {
        $doc = self::read('docs/reference/04-installer.md');

        $known = self::installerFlags();
        preg_match_all('/^\s+(--[a-z][a-z-]*)\)/m', self::read('install/uninstall.sh'), $uninstallFlags);
        $known = [...$known, ...$uninstallFlags[1]];
        $commandNames = [];
        foreach (glob(self::root().'/app/src/Command/{,*/}*.php', \GLOB_BRACE) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/addOption\('([a-z][a-z-]*)'/", $source, $options);
            foreach ($options[1] as $option) {
                $known[] = '--'.$option;
            }
            if (1 === preg_match("/name: '(fleet:[a-z:-]+)'/", $source, $name)) {
                $commandNames[] = $name[1];
            }
        }
        // Flags of other programs the guide quotes, and `--check`, which the fleet wrapper maps to fleet:upgrade:check.
        $known = [...$known, '--no-dev', '--no-pager', '--help', '--exclude', '--config', '--adapter', '--check'];

        // A flag is "--name" not glued to a word or a colon (`authelia:--x` is an ACL, not a flag).
        preg_match_all('/(?<![\w:-])(--[a-z][a-z-]*)/', $doc, $flags);
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), $known)), 'docs/reference/04-installer.md names flags nothing implements');

        // `fleet:group:verb` is a console command; `fleet:fleet` and `fleet:caddy` are owner:group pairs.
        preg_match_all('/\bfleet:[a-z]+:[a-z-]+/', $doc, $commands);
        self::assertSame([], array_values(array_diff(array_unique($commands[0]), $commandNames)), 'docs/reference/04-installer.md names commands that do not exist');
    }

    public function testInstallationDocRecordsTheOperatorFacingLimits(): void
    {
        $doc = self::allDocs();

        foreach ([
            'ssh -t',                                       // the password reset needs a terminal
            'sudo fleet setup:reset',                       // recovery
            'user:reset-password',
            'ovh-eu', 'ovh-ca', 'ovh-us',                   // the OVH endpoint allow-list
            'libdns/ovh',                                   // where the OVH rights list comes from
            '`DELETE` `/domain/zone/example.com/*`',         // ... and the rights, one line per method
            'This call has not been granted',               // the 403 a wrong token produces
            'auth.fleet.example.com',                       // HTTP-01 needs a record for the login host too
            'every signed-in session',                      // an Authelia restart drops every session
            'is a credential for 24 hours',                 // the setup URL is not "one-time"
            '/usr/local/lib/ddev-fleet-installer/install/uninstall.sh', // the root-owned copy
            '**Back up first.**',                           // uninstall deletes the data
            '--keep-data',
            'clear text',                                   // OVH credentials in /etc/caddy/fleet.env
            '`/hooks/*`',                                   // bypasses forward_auth
            '`one_factor`',                                 // the Authelia policy
            'sshd',                                         // what the installer leaves alone
            'ufw',
        ] as $needle) {
            self::assertStringContainsString($needle, $doc, "the docs should mention '$needle'");
        }
        foreach (['docs/reference/04-installer.md', 'docs/guides/01-install.md'] as $page) {
            self::assertStringNotContainsString('one-time', self::read($page), 'the setup URL is a 24 hour credential, not one-time');
        }
        self::assertStringNotContainsString('/opt/ddev-fleet/install/uninstall.sh', $doc, 'root must run the uninstaller from the root-owned copy');
    }

    public function testReadmeMentionsInstallCommand(): void
    {
        $readme = self::read('README.md');

        self::assertStringContainsString('install/install.sh', $readme);
        self::assertStringContainsString('--domain=fleet.example.com', $readme);
        self::assertStringContainsString('docs/guides/01-install.md', $readme);
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
        foreach (['README.md', 'CONTRIBUTING.md', ...self::markdownFiles()] as $file) {
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
