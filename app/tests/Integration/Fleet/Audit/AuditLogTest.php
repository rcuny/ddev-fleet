<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Audit;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class AuditLogTest extends KernelTestCase
{
    use FleetDatabase;

    private AuditEntryRepository $entries;
    private MockClock $clock;
    private AuditLog $log;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->entries = new AuditEntryRepository($em);
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->log = new AuditLog($this->entries, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testRecordsWhoWhatWhenAndTheSubject(): void
    {
        $this->log->record('alice', AuditLog::SECRET_PUT, 'fleet/API_KEY');

        $entries = $this->log->latest();
        self::assertCount(1, $entries);
        self::assertSame('alice', $entries[0]->actor());
        self::assertSame('secret.put', $entries[0]->action());
        self::assertSame('fleet/API_KEY', $entries[0]->subject());
        self::assertSame('2026-10-19 10:00:00', $entries[0]->createdAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertNotNull($entries[0]->id());
    }

    public function testNewestFirstWithALimitAndAnActionFilter(): void
    {
        foreach (['one' => AuditLog::SECRET_PUT, 'two' => AuditLog::SECRET_REVEAL, 'three' => AuditLog::SECRET_PUT] as $subject => $action) {
            $this->clock->modify('+1 minute');
            $this->log->record('alice', $action, $subject);
        }

        self::assertSame(['three', 'two', 'one'], array_map(static fn (AuditEntry $e): string => $e->subject(), $this->log->latest()));
        self::assertSame(['three', 'two'], array_map(static fn (AuditEntry $e): string => $e->subject(), $this->log->latest(2)));
        self::assertSame(['three', 'one'], array_map(static fn (AuditEntry $e): string => $e->subject(), $this->log->latest(200, AuditLog::SECRET_PUT)));
        self::assertSame([], $this->log->latest(200, 'nothing.here'));
    }

    public function testActionsAreDistinctAndSorted(): void
    {
        $this->log->record('a', AuditLog::SECRET_REVEAL, 's');
        $this->log->record('a', AuditLog::SECRET_PUT, 's');
        $this->log->record('a', AuditLog::SECRET_PUT, 's');

        self::assertSame(['secret.put', 'secret.reveal'], $this->log->actions());
    }

    public function testOverlongFieldsAreCutNotRejected(): void
    {
        $this->log->record(str_repeat('é', 100), str_repeat('a', 100), str_repeat('s', 400));

        $entry = $this->log->latest()[0];
        self::assertLessThanOrEqual(AuditEntry::ACTOR_MAX, \strlen($entry->actor()));
        self::assertTrue(mb_check_encoding($entry->actor(), 'UTF-8'), 'cut on a character boundary');
        self::assertSame(AuditEntry::ACTION_MAX, \strlen($entry->action()));
        self::assertSame(AuditEntry::SUBJECT_MAX, \strlen($entry->subject()));
    }

    public function testATruncationEndsInAnEllipsisOnACharacterBoundary(): void
    {
        // 3-byte characters never divide 252 evenly with a 255 limit unless the cut backs up to a boundary.
        $this->log->record('alice', 'secret.reveal', str_repeat('日', 100).'tail');

        $subject = $this->log->latest()[0]->subject();
        self::assertStringEndsWith('…', $subject);
        self::assertLessThanOrEqual(AuditEntry::SUBJECT_MAX, \strlen($subject));
        self::assertTrue(mb_check_encoding($subject, 'UTF-8'));
        self::assertSame(str_repeat('日', 84).'…', $subject);
        self::assertStringNotContainsString('tail', $subject);
    }

    public function testATextThatFitsIsStoredUntouched(): void
    {
        $exact = str_repeat('s', AuditEntry::SUBJECT_MAX);
        $this->log->record('alice', 'secret.reveal', $exact);

        self::assertSame($exact, $this->log->latest()[0]->subject());
    }
}
