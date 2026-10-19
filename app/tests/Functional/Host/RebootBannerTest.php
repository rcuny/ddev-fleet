<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Tests\Support\HostPageTestCase;

/**
 * The shell banner "this host needs a reboot": administrators only, driven by the (test-only) flag files.
 */
final class RebootBannerTest extends HostPageTestCase
{
    private string $flag = '';
    private string $packages = '';

    protected function setUp(): void
    {
        parent::setUp();
        $flag = (string) self::getContainer()->getParameter('fleet.reboot_flag_file');
        $packages = (string) self::getContainer()->getParameter('fleet.reboot_packages_file');
        // The test environment never points at the real /run files.
        self::assertStringStartsNotWith('/run/', $flag);
        [$this->flag, $this->packages] = [$flag, $packages];
    }

    public function testNoBannerWhenNothingIsPending(): void
    {
        foreach (['/', '/admin/host', '/instances'] as $path) {
            $crawler = $this->open($path);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertCount(0, $crawler->filter('[data-testid=reboot-banner]'), $path);
        }
    }

    public function testAdminSeesTheBannerWithThePackagesOnEveryShellPage(): void
    {
        file_put_contents($this->flag, "*** System restart required ***\n");
        file_put_contents($this->packages, "linux-image-6.12.0-amd64\nlibc6\n<script>alert(1)</script>\nlibc6\n");

        foreach (['/', '/admin/host', '/instances', '/admin/design-system'] as $path) {
            $crawler = $this->open($path);
            self::assertResponseStatusCodeSame(200, $path);
            $banner = $crawler->filter('[data-testid=reboot-banner]');
            self::assertCount(1, $banner, $path);
            self::assertStringContainsString('This host needs a reboot', $banner->text());
            self::assertSame(['linux-image-6.12.0-amd64', 'libc6'], $banner->filter('[data-testid=reboot-packages] li')->each(static fn ($li): string => trim($li->text())), $path);
        }
        self::assertStringNotContainsString('<script>alert', $this->body());
    }

    public function testTheFlagWithoutAPackageListStillShowsTheBanner(): void
    {
        file_put_contents($this->flag, '');

        $crawler = $this->open('/');
        self::assertCount(1, $crawler->filter('[data-testid=reboot-banner]'));
        self::assertCount(0, $crawler->filter('[data-testid=reboot-packages]'));
    }

    public function testAMemberNeverSeesTheBannerOrThePackageNames(): void
    {
        file_put_contents($this->flag, '');
        file_put_contents($this->packages, "linux-image-6.12.0-amd64\n");
        $this->as('member');

        $crawler = $this->open('/');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=reboot-banner]'));
        self::assertStringNotContainsString('linux-image', $this->body());
        self::assertStringNotContainsString('needs a reboot', $this->body());

        $this->open('/admin/host');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('linux-image', $this->body());
    }

    protected function tearDown(): void
    {
        @unlink($this->flag);
        @unlink($this->packages);
        parent::tearDown();
    }
}
