<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Tls;

use App\Fleet\Tls\AcmeLogParser;
use PHPUnit\Framework\TestCase;

final class AcmeLogParserTest extends TestCase
{
    private const string RATE_LIMITED = 'HTTP 429 urn:ietf:params:acme:error:rateLimited - too many certificates (50) already issued for this exact set of identifiers in the last 168h0m0s, retry after 2026-11-05 09:30:12 UTC: see https://letsencrypt.org/docs/rate-limits/#new-certificates-per-exact-set-of-identifiers';

    /**
     * A journal line whose MESSAGE is the JSON document Caddy logged.
     *
     * @param array<string, mixed> $log
     */
    private static function caddy(array $log, int $seconds = 1_793_000_000): string
    {
        return json_encode(['MESSAGE' => json_encode($log), '__REALTIME_TIMESTAMP' => (string) ($seconds * 1_000_000)], \JSON_THROW_ON_ERROR);
    }

    public function testTheRealRateLimitedLineGivesCountRetryTimeAndName(): void
    {
        $line = self::caddy(['level' => 'error', 'ts' => 1_793_000_100.5, 'logger' => 'tls.obtain', 'msg' => 'will retry', 'error' => 'obtaining certificate: '.self::RATE_LIMITED, 'identifier' => 'Demo--Main.Fleet.Example.com']);

        $events = new AcmeLogParser()->parse([$line, $line]);

        self::assertSame(2, $events->rateLimited);
        self::assertSame('2026-11-05 09:30:12', $events->retryAfter?->format('Y-m-d H:i:s'));
        self::assertSame(['demo--main.fleet.example.com'], $events->rateLimitedNames);
        self::assertSame(1_793_000_100, $events->rateLimitedSince?->getTimestamp());
        self::assertSame(0, $events->failedSolves);
        self::assertCount(1, $events->errors, 'the same error is listed once');
    }

    public function testPlainTextMessagesAreMatchedToo(): void
    {
        $line = json_encode(['MESSAGE' => 'caddy: acme: error: 429 :: new-order :: too many certificates already issued, retry after 2026-11-05 10:00:00 UTC'], \JSON_THROW_ON_ERROR);

        $events = new AcmeLogParser()->parse([$line]);

        self::assertSame(1, $events->rateLimited);
        self::assertSame('2026-11-05 10:00:00', $events->retryAfter?->format('Y-m-d H:i:s'));
    }

    public function testFailedSolvesAreCountedApartFromRateLimits(): void
    {
        $solve = self::caddy(['level' => 'error', 'logger' => 'tls.obtain', 'msg' => 'could not get certificate from issuer', 'identifier' => 'a.example.com', 'error' => 'HTTP 400 urn:ietf:params:acme:error:dns - solving challenges: a.example.com: DNS problem: NXDOMAIN (ca=https://acme-v02.api.letsencrypt.org/directory)']);
        $cleanup = self::caddy(['level' => 'error', 'logger' => 'tls.issuance.acme.acme_client', 'msg' => 'cleaning up solver', 'identifier' => 'b.example.com', 'error' => 'no memory of presenting a DNS record']);

        $events = new AcmeLogParser()->parse([$solve, $cleanup]);

        self::assertSame(0, $events->rateLimited);
        self::assertSame(2, $events->failedSolves);
        self::assertSame(['a.example.com', 'b.example.com'], $events->failedSolveNames);
    }

    public function testObtainedCertificatesAreCountedAndInfoLinesNeverRaiseAnError(): void
    {
        $ok = self::caddy(['level' => 'info', 'logger' => 'tls.obtain', 'msg' => 'certificate obtained successfully', 'identifier' => 'a.example.com']);
        $info = self::caddy(['level' => 'info', 'logger' => 'tls', 'msg' => 'cleaning up solver', 'identifier' => 'a.example.com']);

        $events = new AcmeLogParser()->parse([$ok, $ok, $info]);

        self::assertSame(2, $events->obtained);
        self::assertSame(0, $events->failedSolves);
        self::assertSame(0, $events->rateLimited);
    }

    public function testGarbageAndHostileNamesAreIgnored(): void
    {
        $evil = self::caddy(['level' => 'error', 'msg' => 'rateLimited', 'identifier' => '<script>alert(1)</script>', 'identifiers' => ['ok.example.com', 'x y']]);

        $events = new AcmeLogParser()->parse(['', 'not json', '{"MESSAGE":42}', $evil]);

        self::assertSame(1, $events->rateLimited);
        self::assertSame(['ok.example.com'], $events->rateLimitedNames);
    }

    public function testErrorExcerptsAreShortAndSingleLine(): void
    {
        $line = self::caddy(['level' => 'error', 'msg' => 'rateLimited', 'error' => "line one\nline two ".str_repeat('x', 1000)]);

        $errors = new AcmeLogParser()->parse([$line])->errors;

        self::assertCount(1, $errors);
        self::assertLessThanOrEqual(300, mb_strlen($errors[0]));
        self::assertStringNotContainsString("\n", $errors[0]);
    }
}
