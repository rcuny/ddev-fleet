<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host;

use App\Fleet\Host\RebootSignal;
use PHPUnit\Framework\TestCase;

final class RebootSignalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-reboot-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function signal(): RebootSignal
    {
        return new RebootSignal($this->dir.'/reboot-required', $this->dir.'/reboot-required.pkgs');
    }

    public function testNoFlagMeansNotRequiredEvenWithAStalePackageList(): void
    {
        file_put_contents($this->dir.'/reboot-required.pkgs', "libc6\n");

        $status = $this->signal()->status();

        self::assertFalse($status->required);
        self::assertSame([], $status->packages);
    }

    public function testFlagAndPackages(): void
    {
        file_put_contents($this->dir.'/reboot-required', "*** System restart required ***\n");
        file_put_contents($this->dir.'/reboot-required.pkgs', "linux-image-6.12.0-amd64\nlibc6\nlibssl3:amd64\ng++-14\n");

        $status = $this->signal()->status();

        self::assertTrue($status->required);
        self::assertSame(['linux-image-6.12.0-amd64', 'libc6', 'libssl3:amd64', 'g++-14'], $status->packages);
        self::assertFalse($status->truncated);
    }

    public function testFlagWithoutOrWithUnreadablePackageListIsStillRequired(): void
    {
        file_put_contents($this->dir.'/reboot-required', '');
        self::assertEquals([true, []], [$this->signal()->status()->required, $this->signal()->status()->packages]);

        // A directory where the list should be: reading it fails, the notice still shows.
        mkdir($this->dir.'/reboot-required.pkgs');
        self::assertEquals([true, []], [$this->signal()->status()->required, $this->signal()->status()->packages]);
        rmdir($this->dir.'/reboot-required.pkgs');
    }

    public function testAFlagThatIsADirectoryOrMissingParentIsNotRequired(): void
    {
        mkdir($this->dir.'/reboot-required');
        self::assertFalse($this->signal()->status()->required);
        rmdir($this->dir.'/reboot-required');

        self::assertFalse(new RebootSignal('/nonexistent-dir/reboot-required', '/nonexistent-dir/x.pkgs')->status()->required);
    }

    public function testLinesAreSanitisedDeduplicatedAndNeverRepaired(): void
    {
        file_put_contents($this->dir.'/reboot-required', '');
        file_put_contents($this->dir.'/reboot-required.pkgs', implode("\r\n", [
            '  libc6  ',
            'libc6',
            '<script>alert(1)</script>',
            'name with spaces',
            "evil\x1b[31mred",
            '-leading-dash',
            '',
            str_repeat('a', 101),
            'ok.pkg+1~rc1_x',
        ]));

        self::assertSame(['libc6', 'ok.pkg+1~rc1_x'], $this->signal()->status()->packages);
    }

    public function testThePackageListIsCapped(): void
    {
        file_put_contents($this->dir.'/reboot-required', '');
        file_put_contents($this->dir.'/reboot-required.pkgs', implode("\n", array_map(static fn (int $i): string => 'pkg'.$i, range(1, 80))));

        $status = $this->signal()->status();

        self::assertCount(RebootSignal::MAX_PACKAGES, $status->packages);
        self::assertSame('pkg1', $status->packages[0]);
        self::assertTrue($status->truncated);
    }

    public function testAHugeFileIsReadOnlyUpToTheLimitAndMarkedTruncated(): void
    {
        file_put_contents($this->dir.'/reboot-required', '');
        file_put_contents($this->dir.'/reboot-required.pkgs', str_repeat("a\n", 40000));

        $status = $this->signal()->status();

        self::assertTrue($status->required);
        self::assertSame(['a'], $status->packages, 'duplicates collapse');
        self::assertTrue($status->truncated);
    }
}
