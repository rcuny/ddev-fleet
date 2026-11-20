<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * FLE-349: the locked database root account (FLE-324) is documented, and Fleet's own shipped code never connects as root over the network.
 */
final class RootLockDocsTest extends TestCase
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

    public function testHardeningGuideExplainsTheLockAndTheAlternatives(): void
    {
        $guide = self::read('docs/reference/07-hardening.md');

        self::assertStringContainsString('### The database `root` account is locked', $guide);
        self::assertStringContainsString('ERROR 4151', $guide);
        self::assertStringContainsString('test_%', $guide);
        self::assertStringContainsString('-uroot', $guide);
    }

    public function testTroubleshootingLinksToTheGuideAnchor(): void
    {
        $page = self::read('docs/reference/13-troubleshooting.md');

        self::assertStringContainsString('ERROR 4151', $page);
        self::assertStringContainsString('07-hardening.md#the-database-root-account-is-locked', $page);
    }

    public function testChangelogFragmentExists(): void
    {
        self::assertStringContainsString('FLE-349', self::read('changelog.d/FLE-349.md'));
    }

    /**
     * Only the unix-socket form inside the db container (`docker exec ... mysql -uroot`) is allowed in the shipped code.
     */
    public function testShippedCodeHasNoNetworkRootCaller(): void
    {
        $pattern = '/(--user=root|root:root@|mysql:\/\/root|-proot|--host=|-h\s*\S+\s+-u ?root)/';
        $hits = [];
        foreach (['app/src', 'ansible', 'ci', 'install'] as $dir) {
            $path = self::root().'/'.$dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || str_contains($file->getPathname(), '/node_modules/')) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (str_contains($source, 'root') && 1 === preg_match($pattern, $source)
                    && 1 === preg_match('/mysql|mariadb/i', $source)) {
                    $hits[] = substr($file->getPathname(), \strlen(self::root()) + 1);
                }
            }
        }
        sort($hits);

        self::assertSame([], $hits, 'A network connection as database root fails since FLE-324 (account locked).');
    }
}
