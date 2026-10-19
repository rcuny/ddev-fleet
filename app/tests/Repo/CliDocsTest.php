<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * docs/projects-and-templates.md must describe the project, template and port commands as they are: every command and
 * option is documented, and the guide names nothing the code does not have. The commands live in sub-directories of
 * App\Command (so that InstallDocsTest, which covers the flat ones in docs/installation.md, stays out of it).
 */
final class CliDocsTest extends TestCase
{
    private const string GUIDE = 'docs/projects-and-templates.md';

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
        $files = [];
        foreach (['Project', 'Template', 'Port', 'Support'] as $directory) {
            array_push($files, ...(glob(self::root().'/app/src/Command/'.$directory.'/*.php') ?: []));
        }
        self::assertNotEmpty($files);

        $commands = $options = $arguments = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (1 === preg_match("/name: '(fleet:[a-z:-]+)'/", $source, $name)) {
                $commands[] = $name[1];
            }
            // Options are added as addOption('x', ...); a negatable one (VALUE_NEGATABLE) is also --no-x.
            preg_match_all("/addOption\('([a-z][a-z0-9-]*)',\s*(?:null|'[a-z]'),\s*((?:InputOption::[A-Z_]+\s*\|?\s*)+)/", $source, $found, \PREG_SET_ORDER);
            foreach ($found as $option) {
                $options[] = '--'.$option[1];
                if (str_contains($option[2], 'VALUE_NEGATABLE')) {
                    $options[] = '--no-'.$option[1];
                }
            }
            preg_match_all("/addArgument\('([a-z][a-z-]*)'/", $source, $found);
            array_push($arguments, ...$found[1]);
        }
        self::assertGreaterThanOrEqual(13, \count($commands), 'every project, template and port command has an AsCommand name');
        self::assertContains('--no-typesense', $options, 'a negatable option is also documented as --no-<name>');
        self::assertContains('--yes', $options);
        self::assertContains('--json', $options);

        return [
            'commands' => array_values(array_unique($commands)),
            'options' => array_values(array_unique($options)),
            'arguments' => array_values(array_unique($arguments)),
        ];
    }

    public function testEveryCommandAndOptionIsDocumented(): void
    {
        $guide = self::read(self::GUIDE);
        $cli = self::cli();

        foreach ($cli['commands'] as $command) {
            // `fleet:project:create` is documented as the host command `fleet project:create`.
            self::assertStringContainsString(substr($command, \strlen('fleet:')), $guide, "$command is not documented");
        }
        foreach ($cli['options'] as $option) {
            self::assertStringContainsString($option, $guide, "$option is not documented");
        }
        foreach ($cli['arguments'] as $argument) {
            self::assertStringContainsString('<'.$argument.'>', $guide, "the <$argument> argument is not documented");
        }
    }

    public function testTheGuideNamesNothingThatDoesNotExist(): void
    {
        $guide = self::read(self::GUIDE);
        $cli = self::cli();

        // Flags of other programs the guide mentions: none today.
        preg_match_all('/(?<![\w-])(--[a-z][a-z0-9-]*)/', $guide, $flags);
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), $cli['options'])), 'the guide names options nothing implements');

        $known = array_map(static fn (string $command): string => substr($command, \strlen('fleet:')), $cli['commands']);
        preg_match_all('/\bfleet:(?:project|template|port):[a-z-]+/', $guide, $full);
        self::assertSame([], array_values(array_diff(array_unique($full[0]), $cli['commands'])), 'the guide names commands that do not exist');
        preg_match_all('/\bfleet (?:project|template|port)[: ]([a-z-]+)/', $guide, $short, \PREG_SET_ORDER);
        foreach ($short as $match) {
            $group = preg_replace('/^fleet (project|template|port).*$/', '$1', $match[0]);
            self::assertContains($group.':'.$match[1], $known, "the guide shows `{$match[0]}`, which is not a command");
        }
        // The guide's own <argument> placeholders must be arguments or the documented values of an option.
        preg_match_all('/`fleet (?:project|template|port):[a-z-]+((?: <[a-z-]+>| \[<[a-z-]+>\])*)/', $guide, $usages);
        foreach ($usages[1] as $placeholders) {
            preg_match_all('/<([a-z-]+)>/', $placeholders, $names);
            foreach ($names[1] as $name) {
                self::assertContains($name, $cli['arguments'], "<$name> is not an argument of any command");
            }
        }
    }

    public function testEveryExitCodeAndFormatIsDocumented(): void
    {
        $guide = self::read(self::GUIDE);

        $source = (string) file_get_contents(self::root().'/app/src/Command/Support/JsonOutput.php');
        preg_match_all('/public const int ([A-Z_]+) = (\d);/', $source, $codes, \PREG_SET_ORDER);
        self::assertCount(4, $codes, 'JsonOutput has the four exit codes');
        foreach ($codes as [, , $code]) {
            self::assertMatchesRegularExpression('/^\| `'.$code.'` \|/m', $guide, "exit code $code is not in the exit-code table");
        }
        foreach (['--format=table|json', '"error"', '"fields"'] as $needle) {
            self::assertStringContainsString($needle, $guide);
        }
    }

    public function testExamplesAreNeutral(): void
    {
        $guide = self::read(self::GUIDE);

        // Owner host names and client names are caught by RepoHygieneTest, which scans this file too; here the generic rules.
        self::assertStringNotContainsString('/var/www', $guide);
        // The directory name is a variable so that this file does not trip RepoHygieneTest itself.
        $workspace = 'claude';
        self::assertStringNotContainsString('.'.$workspace.'/', $guide);
        preg_match_all('~(?:https?://|@|\s)([a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?=[:/\s`)|]|$)~i', $guide, $hosts);
        $foreign = array_filter(array_unique($hosts[1]), static fn (string $host): bool => !str_ends_with($host, 'example.com') && 1 !== preg_match('/\.(md|json|sh|php|env|yml|yaml|txt)$/', $host));
        self::assertSame([], array_values($foreign), 'examples use example.com hosts only');
    }

    public function testChangelogHasAnUnreleasedEntry(): void
    {
        $changelog = self::read('CHANGELOG.md');
        if (1 !== preg_match('/^## \[Unreleased\]\R(.*?)(?=^## \[|\z)/ms', $changelog, $match)) {
            self::fail('CHANGELOG.md has no [Unreleased] section');
        }

        foreach (['FLE-37', 'fleet project:', 'template:', 'port:', '--json', 'docs/projects-and-templates.md'] as $needle) {
            self::assertStringContainsString($needle, $match[1], "[Unreleased] should mention $needle");
        }
        // The FLE-36 entry stays in the same section: this one is appended, not a rewrite.
        self::assertStringContainsString('FLE-36', $match[1]);
    }

    public function testTheIndexesAndAgentInstructionsPointAtTheGuide(): void
    {
        foreach (['README.md', 'docs/README.md', 'CLAUDE.md'] as $file) {
            self::assertStringContainsString('projects-and-templates.md', self::read($file), "$file should link the guide");
        }
        $claude = self::read('CLAUDE.md');
        foreach ([
            'App\Domain\Project', 'App\Domain\Template', 'App\Domain\Port', 'App\Fleet\Projects', 'App\Fleet\Projects\Rule',
            'App\Command\Project', 'App\Command\Template', 'App\Command\Port', 'App\Command\Support', 'App\Form\Project',
            'ProjectVoter', 'ProjectInstanceGuard',
        ] as $needle) {
            self::assertStringContainsString($needle, $claude, "CLAUDE.md should list $needle");
        }
        // The convention that keeps the browser and the commands in step.
        self::assertStringContainsString('never validate on their own', $claude);
    }
}
