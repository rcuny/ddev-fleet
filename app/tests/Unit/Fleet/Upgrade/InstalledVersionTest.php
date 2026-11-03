<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\InstalledVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstalledVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/installed-version-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/checkout/.git', 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testTheVersionFileWins(): void
    {
        file_put_contents($this->dir.'/installed-version', "v1.2.0\n");
        $installed = new InstalledVersion(new RecordingCommandRunner(), $this->dir.'/installed-version', $this->dir.'/checkout');

        self::assertSame('v1.2.0', $installed->raw());
        self::assertSame('1.2.0', (string) $installed->version());
    }

    public function testABranchInstallIsRawButNotAVersion(): void
    {
        file_put_contents($this->dir.'/installed-version', 'main@abc1234');
        $installed = new InstalledVersion(new RecordingCommandRunner(), $this->dir.'/installed-version', $this->dir.'/checkout');

        self::assertSame('main@abc1234', $installed->raw());
        self::assertNull($installed->version());
    }

    public function testWithoutTheFileTheCheckoutsExactTagIsUsed(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['git', '-C', $this->dir.'/checkout', 'describe', '--tags', '--exact-match'], new CommandResult(0, "v1.1.0\n"));
        $installed = new InstalledVersion($runner, $this->dir.'/missing', $this->dir.'/checkout');

        self::assertSame('v1.1.0', $installed->raw());
    }

    public function testNoTagAndNoFileIsDev(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['git', '-C', $this->dir.'/checkout', 'describe', '--tags', '--exact-match'], new CommandResult(128, '', 'fatal: no tag exactly matches'));

        self::assertSame('dev', new InstalledVersion($runner, $this->dir.'/missing', $this->dir.'/checkout')->raw());
        self::assertSame('dev', new InstalledVersion(new RecordingCommandRunner(), $this->dir.'/missing', $this->dir.'/no-checkout')->raw());
    }

    public function testAFileWithGarbageIsIgnored(): void
    {
        file_put_contents($this->dir.'/installed-version', "v1.2.0; rm -rf /\n");
        $installed = new InstalledVersion(new RecordingCommandRunner(), $this->dir.'/installed-version', $this->dir.'/no-checkout');

        self::assertSame('dev', $installed->raw());
    }
}
