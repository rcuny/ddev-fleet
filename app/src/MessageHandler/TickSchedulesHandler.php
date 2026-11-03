<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Fleet\Schedules\ScheduleTicker;
use App\Message\TickSchedules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TickSchedulesHandler
{
    public function __construct(private ScheduleTicker $ticker)
    {
    }

    public function __invoke(TickSchedules $message): void
    {
        $this->ticker->tick();
    }
}
