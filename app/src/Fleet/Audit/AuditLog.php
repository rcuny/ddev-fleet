<?php

declare(strict_types=1);

namespace App\Fleet\Audit;

use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditEntryRepository;
use Psr\Clock\ClockInterface;

/**
 * Records who did what. The subject is a label, never a value: callers pass names of things, not their contents.
 */
final readonly class AuditLog
{
    public const string SECRET_PUT = 'secret.put';
    public const string SECRET_DELETE = 'secret.delete';
    public const string SECRET_REVEAL = 'secret.reveal';

    public const string SCHEDULE_CREATE = 'schedule.create';
    public const string SCHEDULE_UPDATE = 'schedule.update';
    public const string SCHEDULE_DELETE = 'schedule.delete';
    public const string SCHEDULE_PAUSE = 'schedule.pause';
    public const string SCHEDULE_RESUME = 'schedule.resume';
    public const string SCHEDULE_RUN = 'schedule.run';

    /** The actor of changes made on the command line. */
    public const string CLI_ACTOR = 'cli';

    public function __construct(private AuditEntryRepository $entries, private ClockInterface $clock)
    {
    }

    public function record(string $actor, string $action, string $subject): void
    {
        $this->entries->save(new AuditEntry(
            self::fit($actor, AuditEntry::ACTOR_MAX),
            self::fit($action, AuditEntry::ACTION_MAX),
            self::fit($subject, AuditEntry::SUBJECT_MAX),
            $this->clock->now(),
        ));
    }

    /**
     * @return list<AuditEntry>
     */
    public function latest(int $limit = 200, ?string $action = null): array
    {
        return $this->entries->latest($limit, $action);
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->entries->actions();
    }

    private static function fit(string $text, int $max): string
    {
        return mb_strcut($text, 0, $max);
    }
}
