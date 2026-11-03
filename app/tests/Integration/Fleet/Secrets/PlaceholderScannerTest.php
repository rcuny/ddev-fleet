<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Secrets;

use App\Fleet\FleetPaths;
use App\Fleet\Secrets\PlaceholderScanner;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceholderScannerTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private PlaceholderScanner $scanner;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->paths = self::getContainer()->get(FleetPaths::class);
        $this->scanner = new PlaceholderScanner($this->paths);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function write(string $relative, string $content): string
    {
        $file = $this->paths->assetsDir('blog').'/'.$relative;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o750, true);
        }
        file_put_contents($file, $content);

        return $file;
    }

    public function testAMissingAssetsDirectoryIsNotAnError(): void
    {
        $scan = $this->scanner->scan('blog');

        self::assertFalse($scan->assetsDirExists);
        self::assertSame(0, $scan->filesScanned);
        self::assertFalse($scan->references('anything'));
        self::assertTrue($scan->complete);
    }

    public function testAnEmptyDirectory(): void
    {
        mkdir($this->paths->assetsDir('blog'), 0o750, true);

        $scan = $this->scanner->scan('blog');

        self::assertTrue($scan->assetsDirExists);
        self::assertSame(0, $scan->filesScanned);
    }

    public function testFindsPlaceholdersInFilesAndSubdirectories(): void
    {
        $this->write('.env', "A=\"[[jira-token]]\"\nB=[[other]]\n");
        $this->write('config/settings.local.php', "<?php \$x = '[[nested-key]]';\n");

        $scan = $this->scanner->scan('blog');

        self::assertSame(2, $scan->filesScanned);
        foreach (['jira-token', 'other', 'nested-key'] as $token) {
            self::assertTrue($scan->references($token), $token);
        }
        self::assertFalse($scan->references('missing'));
    }

    public function testIgnoresWhatIsNotAPlaceholder(): void
    {
        $this->write('a.txt', "[token] [[ spaced ]] [[UPPER]] [[under_score]] {{twig}}\n");

        $scan = $this->scanner->scan('blog');

        self::assertSame(1, $scan->filesScanned);
        foreach (['token', 'spaced', 'UPPER', 'upper', 'under_score', 'twig'] as $token) {
            self::assertFalse($scan->references($token), $token);
        }
    }

    public function testSkipsBinaryOversizedNonUtf8AndSymlinkedFiles(): void
    {
        $this->write('binary.bin', "\0\1[[in-binary]]");
        $this->write('big.txt', '[[in-big]]'.str_repeat('x', PlaceholderScanner::MAX_FILE_BYTES));
        $this->write('latin1.txt', "caf\xE9 [[in-latin]]");
        $target = $this->write('..-target.txt', '[[in-link]]');
        symlink($target, $this->paths->assetsDir('blog').'/link.txt');
        $this->write('ok.txt', '[[in-ok]]');

        $scan = $this->scanner->scan('blog');

        self::assertTrue($scan->references('in-ok'));
        // The symlink's target is a regular file of the directory itself: it is scanned once, as a file.
        self::assertTrue($scan->references('in-link'));
        foreach (['in-binary', 'in-big', 'in-latin'] as $token) {
            self::assertFalse($scan->references($token), $token);
        }
        self::assertSame(2, $scan->filesScanned);
    }

    public function testASymlinkToAFileOutsideTheDirectoryIsNotFollowed(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'fleet-scan');
        self::assertIsString($outside);
        file_put_contents($outside, '[[outside]]');
        mkdir($this->paths->assetsDir('blog'), 0o750, true);
        symlink($outside, $this->paths->assetsDir('blog').'/link.txt');

        try {
            $scan = $this->scanner->scan('blog');

            self::assertFalse($scan->references('outside'));
            self::assertSame(0, $scan->filesScanned);
        } finally {
            unlink($outside);
        }
    }

    public function testAFileWithinTheSizeLimitIsScanned(): void
    {
        $this->write('exact.txt', '[[exact]]'.str_repeat(' ', PlaceholderScanner::MAX_FILE_BYTES - 9));

        self::assertTrue($this->scanner->scan('blog')->references('exact'));
    }

    public function testTheScanStopsAtTheFileLimitAndSaysSo(): void
    {
        mkdir($this->paths->assetsDir('blog'), 0o750, true);
        for ($i = 0; $i < PlaceholderScanner::MAX_FILES + 3; ++$i) {
            file_put_contents($this->paths->assetsDir('blog').'/f'.$i.'.txt', 'x');
        }

        $scan = $this->scanner->scan('blog');

        self::assertFalse($scan->complete);
        self::assertSame(PlaceholderScanner::MAX_FILES, $scan->filesScanned);
    }
}
