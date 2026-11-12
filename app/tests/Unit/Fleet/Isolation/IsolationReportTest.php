<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Fleet\Isolation\IsolationReport;
use PHPUnit\Framework\TestCase;

final class IsolationReportTest extends TestCase
{
    public function testItReadsTheSetsOfNftsJson(): void
    {
        $json = json_encode(['nftables' => [
            ['metainfo' => ['version' => '1.1.3']],
            ['table' => ['family' => 'inet', 'name' => 'fleet_isolation']],
            ['set' => ['name' => 'routers', 'elem' => ['172.18.0.3']]],
            ['set' => ['name' => 'observed', 'elem' => [
                ['elem' => ['val' => ['concat' => ['172.18.0.5', '172.18.0.6', 80]], 'expires' => 100]],
                ['concat' => ['172.18.0.7', '172.18.0.8', 8025]],
            ]]],
            ['set' => ['name' => 'observed_host', 'elem' => [['concat' => ['172.18.0.5', 22]]]]],
            ['set' => ['name' => 'host_ports', 'elem' => [80, 443]]],
        ]], \JSON_THROW_ON_ERROR);

        $report = IsolationReport::parse($json);

        self::assertNotNull($report);
        self::assertTrue($report['installed']);
        self::assertSame(['172.18.0.3'], $report['routers']);
        self::assertSame([['172.18.0.5', '172.18.0.6', 80], ['172.18.0.7', '172.18.0.8', 8025]], $report['flows']);
        self::assertSame([['172.18.0.5', 22]], $report['host']);
    }

    public function testAMissingTableIsNotInstalledAndGarbageIsNull(): void
    {
        self::assertSame(['installed' => false, 'flows' => [], 'host' => [], 'routers' => []], IsolationReport::parse('{"nftables":[]}'));
        self::assertNull(IsolationReport::parse('not json'));
        self::assertNull(IsolationReport::parse('{}'));
    }
}
