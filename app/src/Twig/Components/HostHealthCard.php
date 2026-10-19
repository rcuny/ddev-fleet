<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\VisibleInstances;
use App\Health\HostHealth;
use App\Health\HostHealthReport;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The small "this machine" card on Home: memory, disk, whether a reboot is waiting and how many instances run.
 */
#[AsTwigComponent]
final class HostHealthCard
{
    public function __construct(
        private readonly HostHealth $health,
        private readonly VisibleInstances $instances,
    ) {
    }

    /**
     * @return array{running: int, total: int}|null the instances the signed-in user may operate; null while the
     *                                              database cannot be read (the Home page must still render)
     */
    public function instanceCounts(): ?array
    {
        try {
            $all = $this->instances->all();
        } catch (DBALException) {
            return null;
        }

        return [
            'running' => \count(array_filter($all, static fn ($instance): bool => InstanceState::Running === $instance->state())),
            'total' => \count($all),
        ];
    }

    public function report(): HostHealthReport
    {
        return $this->health->report();
    }
}
