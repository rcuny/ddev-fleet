<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Tls;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Tls\AcmeLogParser;
use App\Fleet\Tls\CertificateExpiry;
use App\Fleet\Tls\CertificateProbe;
use App\Fleet\Tls\TlsChecker;
use App\Fleet\Tls\TlsLevel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TlsCheckerTest extends TestCase
{
    private const string NOW = '2026-11-04 12:00:00 UTC';

    /**
     * @param array<string, mixed> $log
     */
    private static function line(array $log): string
    {
        return json_encode(['MESSAGE' => json_encode($log), '__REALTIME_TIMESTAMP' => '1793000000000000'], \JSON_THROW_ON_ERROR)."\n";
    }

    private function checker(RecordingCommandRunner $runner, ?CertificateExpiry $cert): TlsChecker
    {
        $probe = new class($cert) implements CertificateProbe {
            public function __construct(private readonly ?CertificateExpiry $cert)
            {
            }

            public function probe(string $name): ?CertificateExpiry
            {
                return $this->cert;
            }
        };

        return new TlsChecker($runner, new AcmeLogParser(), $probe, new MockClock(self::NOW));
    }

    public function testHealthyWhenTheJournalHasNothingAndTheCertificateIsFresh(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['journalctl'], new CommandResult(1))->expect(['journalctl'], new CommandResult(1));

        $status = $this->checker($runner, null)->check('fleet.example.com');

        self::assertSame(TlsLevel::Ok, $status->level);
        self::assertSame([], $status->headlines);
        self::assertSame(0, $status->issuedLastWeek);
    }

    public function testAnActiveRateLimitIsAnError(): void
    {
        $limited = self::line(['level' => 'error', 'msg' => 'will retry', 'error' => 'urn:ietf:params:acme:error:rateLimited too many certificates (50), retry after 2026-11-05 09:30:12 UTC', 'identifier' => 'a.example.com']);
        $runner = new RecordingCommandRunner();
        $runner->expect(['journalctl'], new CommandResult(0, $limited))
            ->expect(['journalctl'], new CommandResult(0, str_repeat(self::line(['level' => 'info', 'msg' => 'certificate obtained successfully']), 3)));

        $status = $this->checker($runner, null)->check('fleet.example.com');

        self::assertSame(TlsLevel::Error, $status->level);
        self::assertStringContainsString('refusing new certificates', $status->headlines[0]);
        self::assertStringContainsString('until 2026-11-05 09:30 UTC', $status->headlines[0]);
        self::assertSame(3, $status->issuedLastWeek);
        self::assertSame(['a.example.com'], $status->affectedNames);
        $call = $runner->calls()[0];
        self::assertSame(['journalctl', '-u', 'caddy'], \array_slice($call->argv, 0, 3));
    }

    public function testAnExpiredRetryTimeIsOnlyAWarning(): void
    {
        $limited = self::line(['level' => 'error', 'msg' => 'rateLimited, retry after 2026-11-04 08:00:00 UTC']);
        $runner = new RecordingCommandRunner();
        $runner->expect(['journalctl'], new CommandResult(0, $limited))->expect(['journalctl'], new CommandResult(1));

        self::assertSame(TlsLevel::Warning, $this->checker($runner, null)->check('fleet.example.com')->level);
    }

    public function testAnUnreadableJournalIsAWarning(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['journalctl'], new CommandResult(1, '', 'Hint: You are currently not seeing messages from other users'))
            ->expect(['journalctl'], new CommandResult(1, '', 'denied'));

        $status = $this->checker($runner, null)->check('fleet.example.com');

        self::assertSame(TlsLevel::Warning, $status->level);
        self::assertNull($status->issuedLastWeek);
    }

    public function testCertificateExpiry(): void
    {
        foreach ([[40, TlsLevel::Ok], [9, TlsLevel::Warning], [-2, TlsLevel::Error]] as [$days, $expected]) {
            $runner = new RecordingCommandRunner();
            $runner->expect(['journalctl'], new CommandResult(1))->expect(['journalctl'], new CommandResult(1));
            $expires = new \DateTimeImmutable(self::NOW)->modify(($days * 24 + 1).' hours');

            $status = $this->checker($runner, new CertificateExpiry('fleet.example.com', $expires))->check('fleet.example.com');

            self::assertSame($expected, $status->level, (string) $days);
        }
    }

    public function testNoUsableCertificateIsAWarning(): void
    {
        $runner = new RecordingCommandRunner();
        $runner->expect(['journalctl'], new CommandResult(1))->expect(['journalctl'], new CommandResult(1));

        self::assertSame(TlsLevel::Warning, $this->checker($runner, new CertificateExpiry('fleet.example.com', null))->check('fleet.example.com')->level);
    }
}
