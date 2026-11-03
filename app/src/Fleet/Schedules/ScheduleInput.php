<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

/**
 * The values of the create and edit forms and of the command line, as typed.
 */
final readonly class ScheduleInput
{
    public function __construct(
        public string $name,
        public string $cron,
        public string $timezone,
        public string $action,
        public ?string $instance = null,
    ) {
    }

    public function normalised(): self
    {
        $instance = null === $this->instance ? null : trim($this->instance);

        return new self(trim($this->name), trim($this->cron), trim($this->timezone), trim($this->action), '' === $instance ? null : $instance);
    }
}
