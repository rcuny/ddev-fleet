<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Command\HostTlsStatusCommand;
use App\Fleet\Tls\TlsLevel;
use App\Fleet\Tls\TlsStatus;
use App\Fleet\Tls\TlsStatusFile;
use App\Tests\Support\HostPageTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The certificate banner, the Certificates tab and `fleet host:tls-status`, driven by the status file.
 */
final class TlsStatusTest extends HostPageTestCase
{
    /**
     * @param list<string> $headlines
     */
    private function write(TlsLevel $level, array $headlines = [], ?\DateTimeImmutable $at = null, ?\DateTimeImmutable $retryAfter = null): void
    {
        self::getContainer()->get(TlsStatusFile::class)->write(new TlsStatus(
            $level,
            $at ?? new \DateTimeImmutable(),
            'fleet.example.com',
            $headlines,
            retryAfter: $retryAfter,
            rateLimitedCount: TlsLevel::Error === $level ? 12 : 0,
            affectedNames: ['demo--main.fleet.example.com'],
            errors: ['rateLimited <b>too many</b> certificates'],
            certificates: [['name' => 'fleet.example.com', 'expiresAt' => null, 'daysLeft' => 40]],
            issuedLastWeek: 47,
        ));
    }

    public function testNoBannerWithoutAStatusOrWhenHealthy(): void
    {
        self::assertCount(0, $this->open('/admin/host')->filter('[data-testid=tls-banner]'));

        $this->write(TlsLevel::Ok);
        foreach (['/', '/admin/host', '/instances'] as $path) {
            self::assertCount(0, $this->open($path)->filter('[data-testid=tls-banner]'), $path);
        }
    }

    public function testAnErrorShowsARedBannerOnEveryShellPageAndEscapesText(): void
    {
        $this->write(TlsLevel::Error, ['Let\'s Encrypt is refusing new certificates for fleet.example.com (rate limited): new instances will have no HTTPS until 2026-11-05 09:30 UTC.']);

        foreach (['/', '/admin/host', '/instances'] as $path) {
            $banner = $this->open($path)->filter('[data-testid=tls-banner]');
            self::assertCount(1, $banner, $path);
            self::assertSame('error', $banner->attr('data-level'));
            self::assertStringContainsString('refusing new certificates for fleet.example.com', $banner->text());
            self::assertStringContainsString('until 2026-11-05 09:30 UTC', $banner->text());
            self::assertStringContainsString('tab=certificates', (string) $banner->filter('a')->attr('href'));
        }
    }

    public function testAWarningShowsAnAmberBanner(): void
    {
        $this->write(TlsLevel::Warning, ['The certificate of fleet.example.com expires in 9 days.']);

        $banner = $this->open('/')->filter('[data-testid=tls-banner]');
        self::assertSame('warning', $banner->attr('data-level'));
        self::assertStringContainsString('expires in 9 days', $banner->text());
    }

    public function testAMemberNeverSeesTheBanner(): void
    {
        $this->write(TlsLevel::Error, ['Let\'s Encrypt is refusing new certificates for fleet.example.com (rate limited)']);
        $this->as('member');

        $this->open('/');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=tls-banner]'));
        self::assertStringNotContainsString('refusing', $this->body());
    }

    public function testTheCertificatesTabListsTheDetail(): void
    {
        $this->write(TlsLevel::Error, ['Let\'s Encrypt is refusing new certificates.'], retryAfter: new \DateTimeImmutable('2026-11-05 09:30:00 UTC'));

        $tab = $this->open('/admin/host?tab=certificates')->filter('[data-testid=host-tab-certificates]');
        self::assertCount(1, $tab);
        self::assertStringContainsString('47 of 50', $tab->filter('[data-testid=tls-issued]')->text());
        self::assertStringContainsString('retry after 2026-11-05 09:30 UTC', $tab->filter('[data-testid=tls-ratelimited]')->text());
        self::assertStringContainsString('demo--main.fleet.example.com', $tab->filter('[data-testid=tls-affected]')->text());
        self::assertStringContainsString('40 days left', $tab->filter('[data-testid=tls-certificate]')->text());
        self::assertStringContainsString('<b>too many</b>', $tab->filter('[data-testid=tls-errors]')->text());
        self::assertStringNotContainsString('<b>too many</b>', $this->body(), 'escaped in the HTML');
    }

    public function testTheCertificatesTabWithoutAStatusSaysSo(): void
    {
        self::assertCount(1, $this->open('/admin/host?tab=certificates')->filter('[data-testid=tls-none]'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function cli(array $input = []): CommandTester
    {
        $tester = new CommandTester(new HostTlsStatusCommand(self::getContainer()->get(TlsStatusFile::class), new Clock()));
        $tester->execute($input);

        return $tester;
    }

    public function testStatusCommandExitCodes(): void
    {
        self::assertSame(1, $this->cli()->getStatusCode(), 'no status yet');

        $this->write(TlsLevel::Ok);
        self::assertSame(0, $this->cli()->getStatusCode());

        $this->write(TlsLevel::Warning, ['Something.']);
        self::assertSame(1, $this->cli()->getStatusCode());

        $this->write(TlsLevel::Error, ['Let\'s Encrypt is refusing new certificates.']);
        $tester = $this->cli(['--json' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertSame('error', json_decode($tester->getDisplay(), true)['level']);

        $this->write(TlsLevel::Ok, at: new \DateTimeImmutable('-3 hours'));
        $tester = $this->cli();
        self::assertSame(1, $tester->getStatusCode(), 'an old healthy status is a warning');
        self::assertStringContainsString('more than an hour old', $tester->getDisplay());
    }
}
