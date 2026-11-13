<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Domain\Host\IsolationMode;
use App\Fleet\Isolation\IsolationInput;
use App\Fleet\Isolation\IsolationRuleset;
use App\Fleet\Isolation\LinkIds;
use App\Fleet\Isolation\ResolvedLink;
use PHPUnit\Framework\TestCase;

/** FLE-299: the rendered nftables table, per mode, with and without links. */
final class IsolationRulesetTest extends TestCase
{
    private function link(): ResolvedLink
    {
        return new ResolvedLink('app--staging', 'app', 'cms--staging', 'cms', 'typesense', 8108, null, 'ddev-app--staging-web', 'ddev-cms--staging-typesense');
    }

    public function testOffRendersOnlyTheDeletionOfTheTable(): void
    {
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Off, 'br-0123456789ab'));

        self::assertSame("table inet fleet_isolation {}\ndelete table inet fleet_isolation\n", $text);
        self::assertSame($text, IsolationRuleset::disabled());
    }

    public function testReportAcceptsWhatEnforceDrops(): void
    {
        $report = IsolationRuleset::render(new IsolationInput(IsolationMode::Report, 'br-0123456789ab'));
        $enforce = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab'));

        self::assertStringContainsString('iifname "br-0123456789ab" oifname "br-0123456789ab" ct state new counter accept', $report);
        self::assertStringNotContainsString('counter drop', $report);
        self::assertStringContainsString('iifname "br-0123456789ab" oifname "br-0123456789ab" ct state new counter drop', $enforce);
        self::assertStringContainsString('iifname "br-*" ct state new counter drop', $enforce);
        // Both chains accept by default: an empty or removed table can never cut traffic by itself.
        self::assertSame(2, substr_count($enforce, 'policy accept'));
    }

    public function testTheRouterReachesEveryServiceAndInstancesReachOnlyItsHttpPorts(): void
    {
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', ['172.18.0.3'], [9108]));

        self::assertStringContainsString("elements = {\n      172.18.0.3\n    }", $text);
        self::assertStringContainsString('ip saddr @routers accept', $text);
        self::assertStringContainsString('ip daddr @routers tcp dport @router_ports accept', $text);
        self::assertStringContainsString("elements = {\n      80,\n      443,\n      8025,\n      8026,\n      8080,\n      8443,\n      9108\n    }", $text);
        self::assertStringNotContainsString('10999', $text);
        // The host: 80, 443 and the public catalogue ports only; ssh is never listed.
        self::assertStringContainsString("elements = {\n      80,\n      443,\n      9108\n    }", $text);
        self::assertStringNotContainsString(' 22', $text);
    }

    public function testExtraHostPortsAreAdded(): void
    {
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', [], [], [9003, 80]));

        self::assertStringContainsString("elements = {\n      80,\n      443,\n      9003\n    }", $text);
    }

    public function testALinkIsOneWayAtThePacketLevel(): void
    {
        $link = $this->link()->withAddresses('172.20.0.2', '172.20.0.3');
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', links: [$link]));

        $bridge = $link->bridge();
        self::assertMatchesRegularExpression('/^flk-[0-9a-f]{8}$/', $bridge);
        self::assertStringContainsString('# link app--staging -> cms--staging/typesense:8108', $text);
        // Only a NEW connection from the source address to the target address and port; the replies are "established".
        self::assertStringContainsString('iifname "'.$bridge.'" oifname "'.$bridge.'" ip saddr 172.20.0.2 ip daddr 172.20.0.3 tcp dport 8108 ct state new accept', $text);
        self::assertStringContainsString('iifname "'.$bridge.'" oifname "'.$bridge.'" ct state new drop', $text);
        self::assertLessThan(strpos($text, 'ct state new drop'), strpos($text, 'ct state new accept'), 'the accept comes before the drop');
        self::assertStringContainsString('ct state established,related accept', $text);
    }

    public function testReportModeNeverDropsAnything(): void
    {
        $link = $this->link()->withAddresses('172.20.0.2', '172.20.0.3');
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Report, 'br-0123456789ab', ['172.18.0.2'], links: [$link]));

        self::assertStringNotContainsString('drop', $text, 'a report ruleset has no drop at all');
        self::assertStringNotContainsString($link->bridge(), $text, 'link networks are untouched in report mode');
    }

    public function testICmpV6StaysAcceptedSoNeighbourDiscoveryIsNotCut(): void
    {
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab'));

        self::assertStringContainsString('meta l4proto ipv6-icmp accept', $text);
    }

    public function testRecordedFlowsSurviveAReapplyButDoNotChangeTheHash(): void
    {
        $base = new IsolationInput(IsolationMode::Report, 'br-0123456789ab', ['172.18.0.3']);
        $with = new IsolationInput(IsolationMode::Report, 'br-0123456789ab', ['172.18.0.3'], observedFlows: [['172.18.0.5', '172.18.0.6', 80]], observedHost: [['172.18.0.5', 22]]);

        self::assertStringContainsString("elements = {\n      172.18.0.5 . 172.18.0.6 . 80\n    }", IsolationRuleset::render($with));
        self::assertStringContainsString("elements = {\n      172.18.0.5 . 22\n    }", IsolationRuleset::render($with));
        self::assertSame(IsolationRuleset::hash($base), IsolationRuleset::hash($with));
        self::assertNotSame(IsolationRuleset::hash($base), IsolationRuleset::hash(new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', ['172.18.0.3'])));
    }

    public function testBadNamesAndAddressesAreRefused(): void
    {
        foreach ([
            new IsolationInput(IsolationMode::Enforce, 'br-0123456789abcdef'),
            new IsolationInput(IsolationMode::Enforce, 'BR;drop'),
            new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', ['1.2.3.4; flush ruleset']),
            new IsolationInput(IsolationMode::Enforce, 'br-0123456789ab', [], [70000]),
        ] as $input) {
            try {
                IsolationRuleset::render($input);
                self::fail('A bad value must be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLinkIdsAreStableAndTheBridgeFitsTheInterfaceLimit(): void
    {
        $id = LinkIds::id(str_repeat('a', 29).'--x', str_repeat('b', 29).'--y', 'typesense', 8108);

        self::assertSame(LinkIds::bridge($id), LinkIds::bridge($id));
        self::assertSame(12, \strlen(LinkIds::bridge($id)));
        self::assertStringStartsWith('fleet-link-', LinkIds::network($id));
        self::assertNotSame(LinkIds::bridge($id), LinkIds::bridge($id.'1'));
    }

    public function testManyRecordedFlowsRenderOneElementPerLineAndAreCapped(): void
    {
        $flows = [];
        for ($i = 0; $i < 3000; ++$i) {
            $flows[] = ['172.18.'.intdiv($i, 250).'.'.(1 + $i % 250), '172.18.99.1', 80];
        }
        $text = IsolationRuleset::render(new IsolationInput(IsolationMode::Report, 'br-0123456789ab', observedFlows: $flows));

        foreach (explode("\n", $text) as $line) {
            self::assertLessThan(140, \strlen($line));
        }
        self::assertSame(IsolationRuleset::MAX_RECORDED_FLOWS, substr_count($text, ' . 172.18.99.1 . 80'));
        self::assertLessThan(200_000, \strlen($text), 'well inside the helper\'s 256 KiB bound');
    }
}
