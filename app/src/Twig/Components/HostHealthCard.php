<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Health\HostHealth;
use App\Health\HostHealthReport;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The small "this machine" card on Home: memory, disk and whether a reboot is waiting.
 */
#[AsTwigComponent]
final class HostHealthCard
{
    public function __construct(private readonly HostHealth $health)
    {
    }

    public function report(): HostHealthReport
    {
        return $this->health->report();
    }
}
