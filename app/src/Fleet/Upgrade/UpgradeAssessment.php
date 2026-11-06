<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Upgrade\Check\CheckReport;

/**
 * Everything `fleet upgrade --check` reports, as data: the same object feeds the text output, `--json` and the internal
 * `fleet:upgrade:plan` document. toArray() is the documented JSON schema (docs/reference/upgrade.md), versioned by `schema`.
 */
final readonly class UpgradeAssessment
{
    public const int SCHEMA = 1;

    public const string UP_TO_DATE = 'up_to_date';
    public const string UPDATE_AVAILABLE = 'update_available';
    public const string DOWNGRADE = 'downgrade';
    /** The host runs a branch or a development tree: there is nothing to compare with. */
    public const string NOT_A_RELEASE = 'not_a_release';
    public const string BLOCKED = 'blocked';
    /** The release source could not be read (or knows no release): the answer is from the cache, or missing. */
    public const string OFFLINE = 'offline';

    /** `fleet upgrade --check` exit codes (ruling R13). 1 (error) and 2 (usage) are produced by the command itself. */
    public const int EXIT_OK = 0;
    public const int EXIT_BLOCKED = 3;
    public const int EXIT_OFFLINE = 4;

    /**
     * @param list<ChangelogSection> $changelog     the sections passed through, newest first
     * @param list<Release>          $releases      the releases the plan passes through, newest first
     * @param list<string>           $checksSkipped why the pre-flight did not run (empty when it ran)
     */
    public function __construct(
        public Plan $plan,
        public ?Release $latest,
        public CatalogState $catalog,
        public bool $refreshed,
        public ?\DateTimeImmutable $now,
        public array $changelog,
        public ?string $changelogError,
        public ?CheckReport $checks,
        public array $checksSkipped,
        public array $releases = [],
    ) {
    }

    public function status(): string
    {
        if ($this->isOffline()) {
            return self::OFFLINE;
        }
        if (null !== $this->checks && $this->checks->isBlocked()) {
            return self::BLOCKED;
        }

        return match ($this->plan->direction) {
            Direction::Same => self::UP_TO_DATE,
            Direction::Up => self::UPDATE_AVAILABLE,
            Direction::Down => self::DOWNGRADE,
            Direction::Unknown => self::NOT_A_RELEASE,
        };
    }

    public function isOffline(): bool
    {
        return $this->catalog->hasFailed() || null !== $this->changelogError || (!$this->plan->hasTarget());
    }

    public function exitCode(): int
    {
        return match ($this->status()) {
            self::OFFLINE => self::EXIT_OFFLINE,
            self::BLOCKED => self::EXIT_BLOCKED,
            default => self::EXIT_OK,
        };
    }

    public function ageSeconds(): ?int
    {
        $listed = $this->catalog->listedAt ?? ($this->catalog->hasFailed() ? null : $this->catalog->checkedAt);
        if (null === $listed || null === $this->now) {
            return null;
        }

        return max(0, $this->now->getTimestamp() - $listed->getTimestamp());
    }

    /**
     * @return list<string> every rollout note of the sections passed through, newest section first
     */
    public function rolloutNotes(): array
    {
        $notes = [];
        foreach ($this->changelog as $section) {
            foreach ($section->rollout->notes as $note) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * @return list<string> the ACTION notes, without the prefix
     */
    public function rolloutActions(): array
    {
        $actions = [];
        foreach ($this->changelog as $section) {
            array_push($actions, ...$section->rollout->actions());
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plan = $this->plan;

        return [
            'schema' => self::SCHEMA,
            'status' => $this->status(),
            'exitCode' => $this->exitCode(),
            'installed' => ['raw' => $plan->from, 'version' => $plan->fromVersion?->tag()],
            'target' => null === $plan->target ? null : $plan->target->toArray(),
            'latest' => $this->latest?->toArray(),
            'direction' => $plan->direction->value,
            'releases' => array_map(static fn (Release $r): string => $r->tag(), $this->releases),
            'catalog' => [
                'checkedAt' => $this->catalog->checkedAt?->format(\DATE_ATOM),
                'listedAt' => $this->catalog->listedAt?->format(\DATE_ATOM),
                'ageSeconds' => $this->ageSeconds(),
                'error' => $this->catalog->error,
                'refreshed' => $this->refreshed,
            ],
            'changelog' => array_map(static fn (ChangelogSection $s): array => [
                'version' => $s->version->tag(),
                'date' => $s->date,
                'body' => $s->body,
                'rollout' => $s->rollout->notes,
            ], $this->changelog),
            'changelogError' => $this->changelogError,
            'rollout' => ['notes' => $this->rolloutNotes(), 'actions' => $this->rolloutActions()],
            'checks' => null === $this->checks ? [] : array_map(static fn ($r): array => $r->toArray(), $this->checks->results),
            'checksSkipped' => $this->checksSkipped,
        ];
    }
}
