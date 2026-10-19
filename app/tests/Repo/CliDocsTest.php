<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/projects-and-templates.md and docs/instances-and-jobs.md must describe their commands as they are: every command
 * and option is documented, and the guide names nothing the code does not have. The commands live in sub-directories of
 * App\Command (so that InstallDocsTest, which covers the flat ones in docs/installation.md, stays out of it).
 */
final class CliDocsTest extends TestCase
{
    /** guide => the App\Command sub-directories it covers (the groups are the same names, lower case). */
    private const array GUIDES = [
        'docs/projects-and-templates.md' => ['Project', 'Template', 'Port'],
        'docs/instances-and-jobs.md' => ['Instance', 'Job'],
    ];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function guides(): iterable
    {
        foreach (self::GUIDES as $guide => $directories) {
            yield $guide => [$guide, $directories];
        }
    }

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
     * @param list<string> $directories
     *
     * @return array{commands: list<string>, options: list<string>, arguments: list<string>}
     */
    private static function cli(array $directories): array
    {
        $files = [];
        foreach ([...$directories, 'Support'] as $directory) {
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
        self::assertGreaterThanOrEqual(\count($directories) * 2, \count($commands), 'every command has an AsCommand name');
        self::assertContains('--json', $options);

        return [
            'commands' => array_values(array_unique($commands)),
            'options' => array_values(array_unique($options)),
            'arguments' => array_values(array_unique($arguments)),
        ];
    }

    /**
     * @param list<string> $directories
     */
    #[DataProvider('guides')]
    public function testEveryCommandAndOptionIsDocumented(string $file, array $directories): void
    {
        $guide = self::read($file);
        $cli = self::cli($directories);

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

    /**
     * @param list<string> $directories
     */
    #[DataProvider('guides')]
    public function testTheGuideNamesNothingThatDoesNotExist(string $file, array $directories): void
    {
        $guide = self::read($file);
        $cli = self::cli($directories);

        // Flags of other programs the guide mentions: none today.
        preg_match_all('/(?<![\w-])(--[a-z][a-z0-9-]*)/', $guide, $flags);
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), $cli['options'])), 'the guide names options nothing implements');

        $known = array_map(static fn (string $command): string => substr($command, \strlen('fleet:')), $cli['commands']);
        $groups = implode('|', array_map(strtolower(...), $directories));
        preg_match_all('/\bfleet:(?:'.$groups.'):[a-z-]+/', $guide, $full);
        self::assertSame([], array_values(array_diff(array_unique($full[0]), $cli['commands'])), 'the guide names commands that do not exist');
        preg_match_all('/\bfleet (?:'.$groups.')[: ]([a-z-]+)/', $guide, $short, \PREG_SET_ORDER);
        foreach ($short as $match) {
            $group = preg_replace('/^fleet ('.$groups.').*$/', '$1', $match[0]);
            self::assertContains($group.':'.$match[1], $known, "the guide shows `{$match[0]}`, which is not a command");
        }
        // The guide's own <argument> placeholders must be arguments or the documented values of an option.
        preg_match_all('/`fleet (?:'.$groups.'):[a-z-]+((?: <[a-z-]+>| \[<[a-z-]+>\])*)/', $guide, $usages);
        foreach ($usages[1] as $placeholders) {
            preg_match_all('/<([a-z-]+)>/', $placeholders, $names);
            foreach ($names[1] as $name) {
                self::assertContains($name, $cli['arguments'], "<$name> is not an argument of any command");
            }
        }
    }

    /**
     * @param list<string> $directories
     */
    #[DataProvider('guides')]
    public function testEveryExitCodeAndFormatIsDocumented(string $file, array $directories): void
    {
        $guide = self::read($file);

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

    /**
     * @param list<string> $directories
     */
    #[DataProvider('guides')]
    public function testExamplesAreNeutral(string $file, array $directories): void
    {
        $guide = self::read($file);

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
        $unreleased = UnreleasedChangelog::text(self::root());

        foreach (['FLE-37', 'fleet project:', 'template:', 'port:', '--json', 'docs/projects-and-templates.md'] as $needle) {
            self::assertStringContainsString($needle, $unreleased, "[Unreleased] should mention $needle");
        }
        // The FLE-36 entry stays in the same section: this one is appended, not a rewrite.
        self::assertStringContainsString('FLE-36', $unreleased);
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

    public function testTheChangelogDescribesTheInstancesAndJobs(): void
    {
        $unreleased = UnreleasedChangelog::text(self::root());

        foreach (['FLE-38', 'fleet instance:', 'job:', 'docs/instances-and-jobs.md', 'Mercure'] as $needle) {
            self::assertStringContainsString($needle, $unreleased, "[Unreleased] should mention $needle");
        }
    }

    public function testTheIndexesAndAgentInstructionsPointAtTheInstancesGuide(): void
    {
        foreach (['README.md', 'docs/README.md', 'CLAUDE.md'] as $file) {
            self::assertStringContainsString('instances-and-jobs.md', self::read($file), "$file should link the guide");
        }
        $claude = self::read('CLAUDE.md');
        foreach ([
            'App\Domain\Instance', 'App\Domain\Job', 'App\Fleet\Jobs', 'App\Fleet\Instances', 'App\Fleet\Git', 'App\Fleet\Ddev',
            'App\Command\Instance', 'App\Command\Job', 'App\Message', 'InstanceService', 'JobExecutor', 'sync://',
        ] as $needle) {
            self::assertStringContainsString($needle, $claude, "CLAUDE.md should list $needle");
        }
    }
}
