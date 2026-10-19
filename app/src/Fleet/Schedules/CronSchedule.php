<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use Cron\CronExpression;

/**
 * A 5-field cron expression read in an IANA timezone (the 0.x semantics). Occurrences are returned as UTC instants.
 * Around a DST change the wall clock rules: a time skipped by the spring-forward fires once, shifted past the gap, and
 * a time repeated by the fall-back fires at its first pass only when the expression names its hours ("30 2 * * *").
 * An expression that runs every hour (hour field "*" or a step) keeps firing through the repeated hour.
 */
final readonly class CronSchedule
{
    private function __construct(private CronExpression $expression, private \DateTimeZone $zone, private bool $fixedHours)
    {
    }

    /**
     * @return array<string, string> field => message, empty when the pair is valid
     */
    public static function errors(mixed $cron, mixed $timezone): array
    {
        $errors = [];
        if (!\is_string($cron) || '' === trim($cron)) {
            $errors['cron'] = 'Enter a cron expression with five fields, for example "0 3 * * *".';
        } elseif (\strlen($cron) > 100 || 5 !== \count(preg_split('/\s+/', trim($cron)) ?: []) || str_contains($cron, '@') || !CronExpression::isValidExpression($cron)) {
            $errors['cron'] = 'Not a valid cron expression: use five fields (minute hour day-of-month month day-of-week), for example "0 3 * * *".';
        }
        if (!\is_string($timezone) || !\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Enter an IANA timezone such as "Europe/Brussels" or "UTC".';
        }

        return $errors;
    }

    /**
     * @throws \InvalidArgumentException when the pair is not valid (check with errors() first)
     */
    public static function of(string $cron, string $timezone): self
    {
        if ([] !== self::errors($cron, $timezone)) {
            throw new \InvalidArgumentException('Invalid cron expression or timezone.');
        }

        $hours = preg_split('/\s+/', trim($cron))[1] ?? '*';

        return new self(new CronExpression(trim($cron)), new \DateTimeZone($timezone), !str_contains($hours, '*') && !str_contains($hours, '/'));
    }

    /**
     * The first occurrence strictly after the instant.
     */
    public function next(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $at = $after;
        do {
            $next = $this->utc($this->expression->getNextRunDate($at->setTimezone($this->zone), 0, false, $this->zone->getName()));
            $at = $next;
        } while ($this->isRepeat($next));

        return $next;
    }

    /**
     * The latest occurrence at or before the instant, or null when there is none.
     */
    public function latest(\DateTimeImmutable $at): ?\DateTimeImmutable
    {
        $allowCurrent = true;
        try {
            do {
                $latest = $this->utc($this->expression->getPreviousRunDate($at->setTimezone($this->zone), 0, $allowCurrent, $this->zone->getName()));
                $at = $latest;
                $allowCurrent = false;
            } while ($this->isRepeat($latest));
        } catch (\RuntimeException) {
            return null;
        }

        return $latest;
    }

    /**
     * The second pass over a wall-clock time that the fall-back repeats (only for expressions with fixed hours).
     */
    private function isRepeat(\DateTimeImmutable $occurrence): bool
    {
        if (!$this->fixedHours) {
            return false;
        }
        try {
            $before = $this->utc($this->expression->getPreviousRunDate($occurrence->setTimezone($this->zone), 0, false, $this->zone->getName()));
        } catch (\RuntimeException) {
            return false;
        }
        $wall = static fn (\DateTimeImmutable $at, \DateTimeZone $zone): string => $at->setTimezone($zone)->format('Y-m-d H:i');

        return $wall($before, $this->zone) === $wall($occurrence, $this->zone);
    }

    private function utc(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
    }
}
