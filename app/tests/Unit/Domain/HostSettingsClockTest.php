<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The entity never reads the wall clock: every timestamp it stores is the one the caller passes in.
 */
final class HostSettingsClockTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        // Run under a non-UTC default zone so a fixture built in the machine zone is caught on UTC CI too.
        $this->defaultZone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZone);
    }

    public function testEveryMutatorStampsTheGivenTime(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-03-01 08:00:00', new \DateTimeZone('UTC')));
        $host = new HostSettings('fleet.example.com', $clock->now());
        self::assertEquals($clock->now(), $host->createdAt());
        self::assertEquals($clock->now(), $host->updatedAt());

        $mutations = [
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeDomain('other.example.com', $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeAcmeEmail('ops@example.com', $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeTlsMode(TlsMode::OvhDns, $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->storeSetupToken(str_repeat('a', 64), $t->modify('+1 hour'), $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->clearSetupToken($t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->markSetupComplete($t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->reopenSetup($t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeAssetUploadMaxBytes(1024, $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeBackupRetentionDays(7, $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changePortMirror(true, $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeGitIdentity('A', 'a@example.com', $t),
            static fn (HostSettings $h, \DateTimeImmutable $t) => $h->changeNotificationMail('smtp.example.com', 587, 'u', 'f@example.com', 't@example.com', $t),
        ];
        foreach ($mutations as $i => $mutate) {
            $clock->sleep(60);
            $mutate($host, $clock->now());
            self::assertEquals($clock->now(), $host->updatedAt(), 'mutator #'.$i);
        }
        self::assertEquals(new \DateTimeImmutable('2026-03-01 08:00:00', new \DateTimeZone('UTC')), $host->createdAt());
    }
}
