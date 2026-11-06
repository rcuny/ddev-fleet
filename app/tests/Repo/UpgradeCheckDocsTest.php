<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * docs/reference/upgrade.md against the code of `fleet upgrade --check`: every option, exit code, status and JSON key the
 * command has is documented.
 */
final class UpgradeCheckDocsTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function doc(): string
    {
        return (string) file_get_contents(self::root().'/docs/reference/upgrade.md');
    }

    public function testEveryOptionOfTheCheckCommandIsDocumented(): void
    {
        $source = (string) file_get_contents(self::root().'/app/src/Command/Upgrade/UpgradeCheckCommand.php');
        preg_match_all("/addOption\\('([a-z-]+)'/", $source, $all);
        self::assertContains('to', $all[1]);
        foreach ($all[1] as $option) {
            self::assertStringContainsString('`--'.$option, self::doc(), 'undocumented option --'.$option);
        }
    }

    public function testEveryStatusAndJsonKeyIsDocumented(): void
    {
        $assessment = (string) file_get_contents(self::root().'/app/src/Fleet/Upgrade/UpgradeAssessment.php');
        preg_match_all("/public const string [A-Z_]+ = '([a-z_]+)';/", $assessment, $statuses);
        self::assertNotSame([], $statuses[1]);
        foreach ($statuses[1] as $status) {
            self::assertStringContainsString('`'.$status.'`', self::doc(), 'undocumented status '.$status);
        }

        $parts = explode('public function toArray(): array', $assessment);
        self::assertCount(2, $parts);
        preg_match_all("/^            '([A-Za-z]+)' =>/m", $parts[1], $keys);
        self::assertNotSame([], $keys[1]);
        foreach ($keys[1] as $key) {
            self::assertMatchesRegularExpression('/^\\|[^|\\n]*`'.preg_quote($key, '/').'`[^|\\n]*\\|/m', self::doc(), 'undocumented JSON key '.$key);
        }
    }

    public function testTheExitCodesAreDocumented(): void
    {
        $doc = self::doc();
        foreach ([0, 1, 2, 3, 4] as $code) {
            self::assertStringContainsString('| '.$code.' |', $doc);
        }
        $assessment = (string) file_get_contents(self::root().'/app/src/Fleet/Upgrade/UpgradeAssessment.php');
        self::assertStringContainsString('EXIT_BLOCKED = 3', $assessment);
        self::assertStringContainsString('EXIT_OFFLINE = 4', $assessment);
    }
}
