<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Domain\Host\HostSettingsRepository;
use App\Tests\Support\HostPageTestCase;

/** FLE-299: the Isolation tab shows the status, never changes the mode, and saves the extra host ports. */
final class HostIsolationTabTest extends HostPageTestCase
{
    /** @return list<int> */
    private function ports(): array
    {
        $this->em()->clear();

        return new HostSettingsRepository($this->em())->get()?->isolationHostPorts() ?? [];
    }

    public function testTheTabRendersTheModeTheCommandsAndNoModeButton(): void
    {
        $crawler = $this->open('/admin/host?tab=isolation');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('off', $crawler->filter('[data-testid=isolation-mode]')->attr('data-mode'));
        $cli = $crawler->filter('[data-testid=isolation-cli]')->text();
        self::assertStringContainsString('fleet host:isolation:enable --mode=report', $cli);
        self::assertStringContainsString('fleet host:isolation:enable --mode=enforce --yes', $cli);
        self::assertStringContainsString('fleet host:isolation:disable', $cli);
        self::assertCount(1, $crawler->filter('[data-testid=host-isolation-ports-form] button[type=submit]'));
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-isolation] button[type=submit]'), 'only the ports form submits');
        self::assertCount(1, $crawler->filter('[data-testid=host-tabs] a[data-tab=isolation]'));
    }

    public function testSavingExtraPortsStoresThemSortedAndUnique(): void
    {
        $this->submit('isolation', 'Save ports', ['host_isolation_ports[ports]' => '9200, 9100 9200']);

        self::assertResponseRedirects('/admin/host?tab=isolation', 303);
        self::assertSame([9100, 9200], $this->ports());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('9100, 9200', $crawler->filter('[data-testid=isolation-host-ports]')->text());
    }

    public function testABadPortIsRefusedAndNothingIsStored(): void
    {
        foreach (['0', '70000', 'abc', '80,x'] as $bad) {
            $this->submit('isolation', 'Save ports', ['host_isolation_ports[ports]' => $bad]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Enter TCP ports from 1 to 65535', $this->body());
            self::assertSame([], $this->ports());
        }
    }

    public function testAMemberCannotOpenOrSaveTheTab(): void
    {
        $this->as('member');
        $this->open('/admin/host?tab=isolation');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/host/isolation', ['host_isolation_ports' => ['ports' => '9100']]);
        self::assertResponseStatusCodeSame(403);
        $this->as('admin');
        self::assertSame([], $this->ports());
    }
}
