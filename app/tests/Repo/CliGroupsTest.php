<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The `fleet` wrapper prefixes `fleet:` only to the command groups on its list (so `fleet doctrine:...` keeps its
 * name). A new command group must be added to that list, or `fleet <group> <verb>` silently reaches the console
 * unprefixed.
 */
final class CliGroupsTest extends TestCase
{
    /** Helpers of the commands, not command groups. */
    private const array NOT_GROUPS = ['Support'];

    private static function appDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function wrapperGroups(): array
    {
        $wrapper = (string) file_get_contents(\dirname(__DIR__, 3).'/install/bin/fleet');
        if (1 !== preg_match('/^FLEET_GROUPS=\(\R(.*?)^\)/ms', $wrapper, $block)) {
            self::fail('The FLEET_GROUPS array was not found in install/bin/fleet.');
        }
        $groups = [];
        foreach (preg_split('/\R/', rtrim($block[1])) ?: [] as $line) {
            self::assertMatchesRegularExpression('/^  [a-z]+$/', $line, 'FLEET_GROUPS takes one group per line, indented by two spaces.');
            $groups[] = trim($line);
        }
        self::assertSame($groups, array_values(array_unique($groups)), 'FLEET_GROUPS names a group twice.');

        return $groups;
    }

    public function testTheWrapperListsItsGroupsOnceAndSorted(): void
    {
        $groups = self::wrapperGroups();
        $sorted = $groups;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $groups, 'Keep FLEET_GROUPS sorted.');

        $wrapper = (string) file_get_contents(\dirname(__DIR__, 3).'/install/bin/fleet');
        self::assertSame(0, preg_match('/\^\([a-z|]+\)/', $wrapper), 'A regex in install/bin/fleet spells the groups out again; use $groups.');
    }

    /**
     * @return list<string> the group of every command class: the second word of its fleet:<group>:<verb> name
     */
    private static function commandGroups(): array
    {
        $groups = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::appDir().'/src/Command', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            if (1 === preg_match("/#\\[AsCommand\\(\\s*name: 'fleet:([a-z]+):/", (string) file_get_contents($file->getPathname()), $m)) {
                $groups[] = $m[1];
            }
        }

        return array_values(array_unique($groups));
    }

    public function testEverySubdirectoryOfCommandIsAGroupOfTheWrapper(): void
    {
        $directories = array_map(basename(...), glob(self::appDir().'/src/Command/*', \GLOB_ONLYDIR) ?: []);
        $groups = [];
        foreach ($directories as $directory) {
            if (!\in_array($directory, self::NOT_GROUPS, true)) {
                $groups[] = strtolower($directory);
            }
        }

        self::assertNotSame([], $groups);
        self::assertSame([], array_values(array_diff($groups, self::wrapperGroups())), 'Add the group to FLEET_GROUPS in install/bin/fleet.');
    }

    public function testTheSupportDirectoryHoldsNoCommands(): void
    {
        $commands = [];
        foreach (glob(self::appDir().'/src/Command/Support/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), '#[AsCommand')) {
                $commands[] = basename($file);
            }
        }

        self::assertSame([], $commands, 'A command in Support/ would never get a wrapper group.');
    }

    public function testEveryCommandGroupIsAGroupOfTheWrapper(): void
    {
        self::assertSame([], array_values(array_diff(self::commandGroups(), self::wrapperGroups())));
    }

    public function testTheWrapperNamesNoGroupWithoutCommands(): void
    {
        self::assertSame([], array_values(array_diff(self::wrapperGroups(), self::commandGroups())));
    }
}
