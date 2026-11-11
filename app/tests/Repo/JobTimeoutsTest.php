<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\Host\Drift\DriftCheck;
use App\Fleet\Jobs\Type\ProvisionJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLE-280: the job queue must not give up on (and redeliver) a message while the longest job is still running.
 */
final class JobTimeoutsTest extends TestCase
{
    public function testTheJobsTransportRedeliversOnlyAfterTheLongestJob(): void
    {
        $config = Yaml::parse((string) file_get_contents(\dirname(__DIR__, 2).'/config/packages/messenger.yaml'));
        $dsn = $config['framework']['messenger']['transports']['jobs']['dsn'];
        parse_str((string) parse_url($dsn, \PHP_URL_QUERY), $query);

        self::assertArrayHasKey('redeliver_timeout', $query, 'Doctrine\'s default (3600 s) is shorter than a drift check or provision');
        self::assertGreaterThanOrEqual(max(DriftCheck::TIMEOUT, ProvisionJob::TIMEOUT), (float) $query['redeliver_timeout']);
    }

    public function testTheWorkerStopsOnlyBetweenMessages(): void
    {
        $unit = (string) file_get_contents(\dirname(__DIR__, 3).'/install/etc/fleet-worker@.service');

        // --time-limit is checked between messages, so it never cuts a running job short.
        self::assertStringContainsString('--time-limit=3600', $unit);
        self::assertStringNotContainsString('--limit=', $unit);
    }
}
