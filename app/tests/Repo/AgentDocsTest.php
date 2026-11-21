<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * FLE-371: FLEET_INSTANCE_HOST is the documented "I run on Fleet" marker. The instance configuration writer must keep
 * putting it in `web_environment` (so it reaches `ddev exec` processes), and docs/guides/12-run-agents-on-fleet.md must
 * state it, the agent flag, and that `claude -p` is not available. A refactor that drops the variable fails here.
 */
final class AgentDocsTest extends TestCase
{
    private const string GUIDE = 'docs/guides/12-run-agents-on-fleet.md';

    private static function read(string $relative): string
    {
        $file = \dirname(__DIR__, 3).'/'.$relative;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    public function testWriterExportsTheMarkerInWebEnvironment(): void
    {
        $source = self::read('app/src/Fleet/Instances/InstanceConfigWriter.php');

        self::assertMatchesRegularExpression("/\\\$environment\s*=\s*\[[^\]]*'FLEET_INSTANCE_HOST='\.\\\$primary/s", $source);
        self::assertStringContainsString("'web_environment' => \$environment", $source);
    }

    public function testAgentFlagIsOnlySetForAgentTemplates(): void
    {
        self::assertStringContainsString("'FLEET_AGENT=1'", self::read('app/src/Fleet/Instances/InstanceIsolation.php'));
    }

    public function testGuideDocumentsTheContract(): void
    {
        $guide = self::read(self::GUIDE);

        foreach (['FLEET_INSTANCE_HOST', 'FLEET_AGENT=1', 'web_environment', '`ddev exec`', '`claude -p`', 'jira-dev', '-workers', 'orchestrate', 'dispatch in waves'] as $needle) {
            self::assertStringContainsString($needle, $guide, $needle);
        }
        self::assertStringContainsString('not available', $guide);
    }

    public function testGuideIsLinkedFromTheIndexes(): void
    {
        self::assertStringContainsString('12-run-agents-on-fleet.md', self::read('docs/README.md'));
        self::assertStringContainsString('12-run-agents-on-fleet.md', self::read('docs/guides/README.md'));
    }

    public function testDocsUseJiraDevAsTemplateExample(): void
    {
        foreach (glob(\dirname(__DIR__, 3).'/docs/{*.md,*/*.md}', \GLOB_BRACE) ?: [] as $file) {
            self::assertStringNotContainsString('jira-work', (string) file_get_contents($file), $file);
        }
    }
}
