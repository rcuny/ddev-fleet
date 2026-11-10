<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Drift;

use App\Fleet\Host\Drift\DriftMask;
use App\Fleet\Host\Drift\DriftReport;
use App\Fleet\Host\Drift\DriftReportInvalid;
use PHPUnit\Framework\TestCase;

/** FLE-261: the drift report parsed from the playbook callback's output, masked and capped. */
final class DriftReportTest extends TestCase
{
    private const array ROLES = [
        ['role' => 'packages', 'changed' => [], 'drift' => [], 'failed' => []],
        ['role' => 'caddy', 'changed' => [], 'drift' => ['the custom Caddy would be refreshed to 2.11.9 (installed 2.11.8)'], 'failed' => []],
        ['role' => 'units', 'changed' => [['task' => 'Install the unit', 'diff' => "--- a\n+++ b\n-x\n+y"]], 'drift' => [], 'failed' => []],
        ['role' => 'docker', 'changed' => [], 'drift' => [], 'failed' => [['task' => 'Read the pool', 'msg' => 'boom']]],
    ];

    /**
     * @param list<array<string, mixed>> $roles
     */
    private function playbookOutput(array $roles = self::ROLES, string $before = "some noise\n"): string
    {
        return $before.DriftReport::BEGIN."\n".json_encode(['schema' => 1, 'roles' => $roles])."\n".DriftReport::END."\n";
    }

    private function parse(string $stdout): DriftReport
    {
        return DriftReport::fromRunnerOutput($stdout, 'v1.7.0', new \DateTimeImmutable('2026-11-10 12:00:00 UTC'));
    }

    public function testStatusIsDerivedPerRole(): void
    {
        $report = $this->parse($this->playbookOutput());

        self::assertSame(['unchanged', 'changed', 'changed', 'failed'], array_column($report->roles, 'status'));
        self::assertTrue($report->hasDrift());
        self::assertTrue($report->hasFailures());
        // A DRIFT: message counts as drift although no task changed (FLE-256: the Caddy refresh).
        self::assertSame('caddy', $report->withStatus('changed')[0]['role']);
    }

    public function testACleanHostHasNoDrift(): void
    {
        $report = $this->parse($this->playbookOutput([self::ROLES[0]]));

        self::assertFalse($report->hasDrift());
        self::assertFalse($report->hasFailures());
        self::assertFalse($report->toArray()['drift']);
        self::assertSame(DriftReport::LIMITS, $report->toArray()['limits']);
    }

    public function testRoundTripsThroughTheStoredShape(): void
    {
        $report = $this->parse($this->playbookOutput());

        self::assertEquals($report, DriftReport::fromArray(json_decode(json_encode($report->toArray(), \JSON_THROW_ON_ERROR), true)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusable(): iterable
    {
        yield 'no markers' => ["PLAY RECAP\n"];
        yield 'no end marker' => [DriftReport::BEGIN."\n{}\n"];
        yield 'not json' => [DriftReport::BEGIN."\nnope\n".DriftReport::END."\n"];
        yield 'wrong schema' => [DriftReport::BEGIN."\n".'{"schema":2,"roles":[]}'."\n".DriftReport::END."\n"];
        yield 'no roles' => [DriftReport::BEGIN."\n".'{"schema":1}'."\n".DriftReport::END."\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function testOutputWithoutAReportIsRefused(string $stdout): void
    {
        $this->expectException(DriftReportInvalid::class);

        $this->parse($stdout);
    }

    public function testSecretsInDiffsAndMessagesAreMaskedAndLongDiffsAreCapped(): void
    {
        $diff = "--- a\n+++ b\n-fleet_proxy_secret: oldvalue\n+fleet_proxy_secret: newvalue\n+  \"password\": \"hunter2\"\n+Authorization: Bearer abcdefghijkl1234\n+safe: line\n".str_repeat("+filler\n", 3000);
        $report = $this->parse($this->playbookOutput([['role' => 'caddy', 'changed' => [['task' => 't', 'diff' => $diff]], 'drift' => ['token=abc123 would change'], 'failed' => [['task' => 'x', 'msg' => 'api_key: AKIA1234']]]]));
        $text = json_encode($report->toArray(), \JSON_THROW_ON_ERROR);

        foreach (['oldvalue', 'newvalue', 'hunter2', 'abcdefghijkl1234', 'abc123', 'AKIA1234'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
        self::assertStringContainsString('+safe: line', $report->roles[0]['changed'][0]['diff']);
        self::assertStringContainsString('(truncated)', $report->roles[0]['changed'][0]['diff']);
        self::assertLessThan(8100, mb_strlen($report->roles[0]['changed'][0]['diff']));
    }

    public function testControlCharactersNeverReachTheReport(): void
    {
        $report = $this->parse($this->playbookOutput([['role' => "ca\x1bddy", 'changed' => [], 'drift' => ["a\x00b\x07c"], 'failed' => []]]));

        self::assertSame('caddy', $report->roles[0]['role']);
        self::assertSame('abc', $report->roles[0]['drift'][0]);
    }

    public function testMaskIsIdempotentAndKeepsPlainText(): void
    {
        self::assertSame('password: ***', DriftMask::text('password: hunter2'));
        self::assertSame('password: ***', DriftMask::text(DriftMask::text('password: hunter2')));
        self::assertSame("ssh-ed25519 AAAAC3 deploy\nplain text", DriftMask::text("ssh-ed25519 AAAAC3 deploy\nplain text"));
        self::assertStringNotContainsString('MIIE', DriftMask::text("x\n-----BEGIN PRIVATE KEY-----\nMIIEvQ\n-----END PRIVATE KEY-----\ny"));
    }

    public function testMasksBareKeyNames(): void
    {
        self::assertSame('OVH_CONSUMER_KEY=***', DriftMask::text('OVH_CONSUMER_KEY=abc123'));
        self::assertSame('  ovh_application_key: ***', DriftMask::text('  ovh_application_key: abc123'));
        self::assertSame('"access_key": ***', DriftMask::text('"access_key": "abc"'));
        self::assertSame('consumerKey: ***', DriftMask::text('consumerKey: abc123'));
        self::assertSame('  applicationKey = ***', DriftMask::text('  applicationKey = abc123'));
        self::assertSame('"apiKey": ***', DriftMask::text('"apiKey": "abc"'));
    }

    public function testMasksYamlBlockScalarValues(): void
    {
        $in = "+secret_key: |\n+  line1\n+  line2\n+\n+next: kept\n unrelated: kept";
        $out = DriftMask::text($in);

        self::assertStringNotContainsString('line1', $out);
        self::assertStringNotContainsString('line2', $out);
        self::assertStringContainsString("+next: kept\n unrelated: kept", $out);
        self::assertSame($out, DriftMask::text($out));
        self::assertSame("api_token: ***\n  ***\n  ***\nname: x", DriftMask::text("api_token: >-\n  abc\n  def\nname: x"));
    }
}
