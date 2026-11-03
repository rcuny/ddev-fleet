<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * docs/moving-projects.md must describe the export, import and backup:verify commands as they are, and the indexes,
 * CLAUDE.md, the installation guide and the changelog fragment must point at the feature. The commands live in
 * App\Command\Export and App\Command\Import (sub-directories, so InstallDocsTest's flat glob does not apply) and
 * App\Command\BackupVerifyCommand; this test is their guard, as AssetDocsTest is for the asset commands.
 */
final class ExportDocsTest extends TestCase
{
    private const string GUIDE = 'docs/moving-projects.md';

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
        $files = [...glob(self::root().'/app/src/Command/{Export,Import}/*Command.php', \GLOB_BRACE) ?: [], self::root().'/app/src/Command/BackupVerifyCommand.php'];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (1 === preg_match("/name: '(fleet:[a-z]+:[a-z-]+)'/", $source, $name)) {
                $commands[] = $name[1];
            }
            preg_match_all("/addOption\\('([a-z][a-z-]*)'/", $source, $found);
            array_push($options, ...array_map(static fn (string $option): string => '--'.$option, $found[1]));
            preg_match_all("/addArgument\\('([a-z][a-z-]*)'/", $source, $found);
            array_push($arguments, ...$found[1]);
        }
        // The shared --format and --json options come from JsonOutput::addOptions(); --passphrase-stdin is PassphraseInput::OPTION.
        array_push($options, '--format', '--json', '--passphrase-stdin');
        sort($commands);
        self::assertSame(['fleet:backup:verify', 'fleet:export:project', 'fleet:import:apply', 'fleet:import:preview'], $commands);

        return ['commands' => $commands, 'options' => array_values(array_unique($options)), 'arguments' => array_values(array_unique($arguments))];
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
    }

    public function testTheGuideNamesNoCommandOrOptionThatDoesNotExist(): void
    {
        $guide = self::read(self::GUIDE);
        $commands = array_map(static fn (string $command): string => substr($command, \strlen('fleet:')), self::cli()['commands']);

        preg_match_all('/`fleet ((?:export|import|backup):[a-z-]+)/', $guide, $found);
        foreach (array_unique($found[1]) as $command) {
            self::assertContains($command, $commands, "the guide shows `fleet $command`, which is not a command");
        }

        preg_match_all('/(?<![\w-])(--[a-z][a-z0-9-]*)/', $guide, $flags);
        // Flags of other programs the guide shows: rsync.
        $foreign = ['--info'];
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), self::cli()['options'], $foreign)), 'the guide names options nothing implements');
    }

    public function testEveryExitCodeIsInTheTable(): void
    {
        $guide = self::read(self::GUIDE);

        foreach ([0, 1, 2, 3] as $code) {
            self::assertMatchesRegularExpression('/^\| `'.$code.'` \|/m', $guide, "exit code $code is not in the exit-code table");
        }
    }

    public function testTheGuideStatesTheLimitsAndTheFormatTheCodeEnforces(): void
    {
        $guide = self::read(self::GUIDE);

        self::assertStringContainsString('2 MiB', $guide);
        self::assertStringContainsString('"fleet-project-export"', $guide);
        self::assertStringContainsString('FLEET_EXPORT_PASSPHRASE', $guide);
        self::assertStringContainsString('crypto_pwhash', $guide);
        self::assertStringContainsString('rsync', $guide);
        self::assertStringContainsString('backup:verify', $guide);

        $format = (string) file_get_contents(self::root().'/app/src/Fleet/Export/ExportFormat.php');
        self::assertStringContainsString("NAME = 'fleet-project-export'", $format);
        self::assertStringContainsString('VERSION = 1', $format);
        self::assertStringContainsString('MAX_BYTES = 2 * 1024 * 1024', $format);
        $sealer = (string) file_get_contents(self::root().'/app/src/Fleet/Export/PassphraseSealer.php');
        self::assertStringContainsString('MIN_PASSPHRASE = 8', $sealer);
        self::assertStringContainsString('MAX_PASSPHRASE = 1024', $sealer);
        self::assertStringContainsString('MIN_OPS = 2', $sealer);
        self::assertStringContainsString('MAX_OPS = 10', $sealer);
    }

    public function testExamplesAreNeutral(): void
    {
        $guide = self::read(self::GUIDE);

        self::assertStringNotContainsString('/var/www', $guide);
        $workspace = 'claude';
        self::assertStringNotContainsString('.'.$workspace.'/', $guide);
        preg_match_all('~(?:https?://|@|\s)([a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?=[:/\s`)|]|$)~i', $guide, $hosts);
        $foreign = array_filter(array_unique($hosts[1]), static fn (string $host): bool => !str_ends_with($host, 'example.com') && 1 !== preg_match('/\.(md|json|sh|php|env|yml|yaml|txt|sql|gz|sqlite)$/', $host));
        self::assertSame([], array_values($foreign), 'examples use example.com hosts only');
    }

    public function testTheIndexesAndInstructionsPointAtTheGuide(): void
    {
        foreach (['README.md', 'docs/README.md', 'CLAUDE.md'] as $file) {
            self::assertStringContainsString('moving-projects.md', self::read($file), "$file should link the guide");
        }
        self::assertStringContainsString('moving-projects.md', self::read('docs/installation.md'));
        self::assertStringContainsString('fleet:backup:verify', self::read('docs/installation.md'));
        $claude = self::read('CLAUDE.md');
        self::assertStringContainsString('App\Fleet\Export', $claude);
        self::assertStringContainsString('ExportDocsTest', $claude);
        // The entry is a changelog.d fragment until a release assembles it into a version section.
        self::assertStringContainsString('FLE-94', UnreleasedChangelog::text(self::root()));
    }
}
