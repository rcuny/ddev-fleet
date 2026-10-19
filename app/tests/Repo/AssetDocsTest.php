<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * docs/assets.md must describe the asset commands as they are, and the indexes, the installation guide, CLAUDE.md and the
 * CHANGELOG must point at the feature. The commands live in App\Command\Asset (a sub-directory, so InstallDocsTest's flat
 * glob does not apply; this test is their guard, as CliDocsTest is for the project commands).
 */
final class AssetDocsTest extends TestCase
{
    private const string GUIDE = 'docs/assets.md';

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
     * @return array{commands: list<string>, options: list<string>, arguments: list<string>}
     */
    private static function cli(): array
    {
        $commands = $options = $arguments = [];
        foreach (glob(self::root().'/app/src/Command/Asset/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (1 === preg_match("/name: '(fleet:asset:[a-z-]+)'/", $source, $name)) {
                $commands[] = $name[1];
            }
            preg_match_all("/addArgument\('([a-z][a-z-]*)'/", $source, $found);
            array_push($arguments, ...$found[1]);
        }
        // The shared --format and --json options come from JsonOutput::addOptions().
        $options = ['--format', '--json'];
        self::assertSame(['fleet:asset:gc', 'fleet:asset:list', 'fleet:asset:snapshot'], (static function (array $c): array {
            sort($c);

            return $c;
        })($commands));

        return ['commands' => $commands, 'options' => $options, 'arguments' => array_values(array_unique($arguments))];
    }

    public function testEveryCommandOptionAndArgumentIsDocumented(): void
    {
        $guide = self::read(self::GUIDE);
        $cli = self::cli();

        foreach ($cli['commands'] as $command) {
            self::assertStringContainsString('fleet '.substr($command, \strlen('fleet:')), $guide, "$command is not documented");
        }
        foreach ($cli['options'] as $option) {
            self::assertStringContainsString($option, $guide, "$option is not documented");
        }
        foreach ($cli['arguments'] as $argument) {
            self::assertStringContainsString('<'.$argument.'>', $guide, "the <$argument> argument is not documented");
        }
        self::assertStringContainsString('--format=table|json', $guide);
        self::assertStringContainsString('"error"', $guide);
    }

    public function testTheGuideNamesNoCommandThatDoesNotExist(): void
    {
        $guide = self::read(self::GUIDE);
        $commands = array_map(static fn (string $command): string => substr($command, \strlen('fleet:')), self::cli()['commands']);

        preg_match_all('/`fleet (asset:[a-z-]+)/', $guide, $found);
        foreach (array_unique($found[1]) as $command) {
            self::assertContains($command, $commands, "the guide shows `fleet $command`, which is not a command");
        }

        preg_match_all('/(?<![\w-])(--[a-z][a-z0-9-]*)/', $guide, $flags);
        // Flags of other programs the guide shows: rsync.
        $foreign = ['--rsync-path', '--chown', '--chmod', '--no-links'];
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), self::cli()['options'], $foreign)), 'the guide names options nothing implements');
    }

    public function testEveryExitCodeIsInTheTable(): void
    {
        $guide = self::read(self::GUIDE);

        foreach ([0, 1, 2, 3] as $code) {
            self::assertMatchesRegularExpression('/^\| `'.$code.'` \|/m', $guide, "exit code $code is not in the exit-code table");
        }
    }

    public function testTheGuideStatesTheLimitsTheCodeEnforces(): void
    {
        $guide = self::read(self::GUIDE);

        self::assertStringContainsString('2 GiB', $guide);
        self::assertStringContainsString('8 MiB', $guide);
        self::assertStringContainsString('asset_upload_max_bytes', $guide);
        self::assertStringContainsString('/srv/fleet/assets/<project>/', $guide);
        self::assertStringContainsString('Rescan', $guide);
        self::assertStringContainsString('never followed', $guide);

        $code = (string) file_get_contents(self::root().'/app/src/Fleet/Assets/UploadService.php');
        self::assertStringContainsString('MAX_CHUNK_BYTES = 8_388_608', $code);
        $host = (string) file_get_contents(self::root().'/app/src/Domain/Host/HostSettings.php');
        self::assertStringContainsString('DEFAULT_ASSET_UPLOAD_MAX_BYTES = 2_147_483_648', $host);
        $path = (string) file_get_contents(self::root().'/app/src/Fleet/Assets/AssetPath.php');
        self::assertStringContainsString('MAX_NAME_BYTES = 255', $path);
        self::assertStringContainsString('MAX_DEPTH = 8', $path);
        self::assertStringContainsString('MAX_PATH_BYTES = 1024', $path);
        $store = (string) file_get_contents(self::root().'/app/src/Fleet/Assets/AssetStore.php');
        self::assertStringContainsString('MAX_ENTRIES = 2000', $store);
        self::assertStringContainsString('ABANDON_AFTER = 86_400', $code);
    }

    public function testExamplesAreNeutral(): void
    {
        $guide = self::read(self::GUIDE);

        self::assertStringNotContainsString('/var/www', $guide);
        $workspace = 'claude';
        self::assertStringNotContainsString('.'.$workspace.'/', $guide);
        preg_match_all('~(?:https?://|@|\s)([a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?=[:/\s`)|]|$)~i', $guide, $hosts);
        $foreign = array_filter(array_unique($hosts[1]), static fn (string $host): bool => !str_ends_with($host, 'example.com') && 1 !== preg_match('/\.(md|json|sh|php|env|yml|yaml|txt|sql|gz)$/', $host));
        self::assertSame([], array_values($foreign), 'examples use example.com hosts only');
    }

    public function testTheIndexesAndInstructionsPointAtTheGuide(): void
    {
        foreach (['README.md', 'docs/README.md', 'CLAUDE.md', 'docs/installation.md'] as $file) {
            self::assertStringContainsString('assets.md', self::read($file), "$file should link the guide");
        }
        $claude = self::read('CLAUDE.md');
        foreach (['App\Fleet\Assets', 'App\Command\Asset', 'AssetStore', 'AssetPath', 'UploadService', 'never follows'] as $needle) {
            self::assertStringContainsString($needle, $claude, "CLAUDE.md should list $needle");
        }
        $installation = self::read('docs/installation.md');
        self::assertStringContainsString('/srv/fleet/uploads', $installation);
        self::assertStringContainsString('/srv/fleet/assets/<project>', $installation);
    }

    public function testChangelogHasAnUnreleasedEntry(): void
    {
        $changelog = self::read('CHANGELOG.md');
        if (1 !== preg_match('/^## \[Unreleased\]\R(.*?)(?=^## \[|\z)/ms', $changelog, $match)) {
            self::fail('CHANGELOG.md has no [Unreleased] section');
        }

        foreach (['FLE-52', 'fleet asset:list', 'asset:gc', '2 GiB', 'docs/assets.md', 'asset_upload_max_bytes'] as $needle) {
            self::assertStringContainsString($needle, $match[1], "[Unreleased] should mention $needle");
        }
    }
}
