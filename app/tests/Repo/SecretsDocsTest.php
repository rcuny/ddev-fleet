<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * docs/secrets.md must describe the secret commands as they are: every command, option and argument is documented, and
 * the guide names nothing the code does not have. The indexes, the CHANGELOG and CLAUDE.md point at it.
 */
final class SecretsDocsTest extends TestCase
{
    private const string GUIDE = 'docs/secrets.md';

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
        $files = glob(self::root().'/app/src/Command/Secret/*.php') ?: [];
        self::assertNotEmpty($files);

        $commands = $options = $arguments = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (1 === preg_match("/name: '(fleet:[a-z:-]+)'/", $source, $name)) {
                $commands[] = $name[1];
            }
            preg_match_all("/addOption\('([a-z][a-z0-9-]*)',\s*(?:null|'[a-z]')/", $source, $found);
            foreach ($found[1] as $option) {
                $options[] = '--'.$option;
            }
            preg_match_all("/addArgument\('([a-z][a-z-]*)'/", $source, $found);
            array_push($arguments, ...$found[1]);
        }
        // --format and --json come from JsonOutput::addOptions(), which every command calls.
        array_push($options, '--format', '--json');
        self::assertCount(3, array_unique($commands), 'list, set and unset');

        return [
            'commands' => array_values(array_unique($commands)),
            'options' => array_values(array_unique($options)),
            'arguments' => array_values(array_unique($arguments)),
        ];
    }

    public function testEveryCommandOptionAndArgumentIsDocumented(): void
    {
        $guide = self::read(self::GUIDE);
        $cli = self::cli();

        foreach ($cli['commands'] as $command) {
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

        preg_match_all('/(?<![\w-])(--[a-z][a-z0-9-]*)/', $guide, $flags);
        self::assertSame([], array_values(array_diff(array_unique($flags[1]), $cli['options'])), 'the guide names options nothing implements');

        $known = array_map(static fn (string $command): string => substr($command, \strlen('fleet:')), $cli['commands']);
        preg_match_all('/\bfleet:secret:[a-z-]+/', $guide, $full);
        self::assertSame([], array_values(array_diff(array_unique($full[0]), $cli['commands'])), 'the guide names commands that do not exist');
        preg_match_all('/\bfleet secret:([a-z-]+)/', $guide, $short);
        foreach (array_unique($short[1]) as $verb) {
            self::assertContains('secret:'.$verb, $known, "the guide shows `fleet secret:$verb`, which is not a command");
        }
    }

    public function testNoCommandTakesTheValueOnTheCommandLine(): void
    {
        foreach (glob(self::root().'/app/src/Command/Secret/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression("/addArgument\\('value'|addOption\\('value'/", $source, basename($file).' takes the value on the command line');
        }
        self::assertStringContainsString('never an argument or an option', self::read(self::GUIDE));
    }

    public function testEveryExitCodeIsDocumented(): void
    {
        $guide = self::read(self::GUIDE);

        $source = (string) file_get_contents(self::root().'/app/src/Command/Support/JsonOutput.php');
        preg_match_all('/public const int ([A-Z_]+) = (\d);/', $source, $codes, \PREG_SET_ORDER);
        foreach ($codes as [, , $code]) {
            self::assertMatchesRegularExpression('/^\| `'.$code.'` \|/m', $guide, "exit code $code is not in the exit-code table");
        }
        foreach (['--format=table|json', '"fields"', '"referenced"'] as $needle) {
            self::assertStringContainsString($needle, $guide);
        }
    }

    public function testTheGuideStatesTheLimitsTheCodeEnforces(): void
    {
        $guide = self::read(self::GUIDE);
        $store = (string) file_get_contents(self::root().'/app/src/Fleet/Secrets/SecretStore.php');

        self::assertStringContainsString("'/^[A-Z][A-Z0-9_]{0,63}\$/D'", $store);
        self::assertStringContainsString('at most 64 characters', $guide);
        self::assertStringContainsString('MAX_VALUE_BYTES = 65536', $store);
        self::assertStringContainsString('64 KiB', $guide);
        self::assertStringContainsString('1 MiB', $guide, 'the scanner limit');
        self::assertStringContainsString('5000 files', $guide);
        self::assertSame(1048576, \App\Fleet\Secrets\PlaceholderScanner::MAX_FILE_BYTES);
        self::assertSame(5000, \App\Fleet\Secrets\PlaceholderScanner::MAX_FILES);
        foreach (\App\Fleet\Secrets\SecretName::BUILT_IN_TOKENS as $token) {
            self::assertStringContainsString('[['.$token.']]', $guide, "the built-in [[$token]] is not named in the guide");
        }
        foreach (['secret.put', 'secret.delete', 'secret.reveal'] as $action) {
            self::assertStringContainsString($action, $guide);
        }
    }

    public function testExamplesAreNeutral(): void
    {
        $guide = self::read(self::GUIDE);

        self::assertStringNotContainsString('/var/www', $guide);
        $workspace = 'claude';
        self::assertStringNotContainsString('.'.$workspace.'/', $guide);
        preg_match_all('~(?:https?://|@|\s)([a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?=[:/\s`)|]|$)~i', $guide, $hosts);
        $foreign = array_filter(array_unique($hosts[1]), static fn (string $host): bool => !str_ends_with($host, 'example.com') && 1 !== preg_match('/\.(md|json|sh|php|env|yml|yaml|txt)$/', $host));
        self::assertSame([], array_values($foreign), 'examples use example.com hosts only');
    }

    public function testTheChangelogHasAnUnreleasedEntry(): void
    {
        $unreleased = UnreleasedChangelog::text(self::root());

        foreach (['FLE-39', 'fleet secret:', '[[token]]', 'audit log', 'docs/secrets.md'] as $needle) {
            self::assertStringContainsString($needle, $unreleased, "[Unreleased] should mention $needle");
        }
        // The earlier entries stay in the same section: this one is added, not a rewrite.
        self::assertStringContainsString('FLE-37', $unreleased);
        self::assertStringContainsString('FLE-36', $unreleased);
    }

    public function testTheIndexesAndAgentInstructionsPointAtTheGuide(): void
    {
        foreach (['README.md', 'docs/README.md', 'CLAUDE.md', 'docs/projects-and-templates.md'] as $file) {
            self::assertStringContainsString('secrets.md', self::read($file), "$file should link the guide");
        }
        $claude = self::read('CLAUDE.md');
        foreach (['App\Domain\Audit', 'App\Fleet\Audit', 'App\Command\Secret', 'SecretService', 'TokenRenderer', 'PlaceholderScanner', 'SecretName', 'CurrentActor', 'AuditController'] as $needle) {
            self::assertStringContainsString($needle, $claude, "CLAUDE.md should list $needle");
        }
    }
}
