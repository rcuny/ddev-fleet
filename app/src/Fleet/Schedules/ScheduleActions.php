<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The small registry of what a schedule can do: action name -> how to build the job.
 */
final class ScheduleActions
{
    /** @var array<string, ScheduleAction> */
    private array $actions = [];

    /**
     * @param iterable<ScheduleAction> $actions
     */
    public function __construct(#[AutowireIterator('fleet.schedule_action')] iterable $actions)
    {
        foreach ($actions as $action) {
            if (isset($this->actions[$action->name()])) {
                throw new \LogicException(\sprintf('The schedule action "%s" is registered twice.', $action->name()));
            }
            $this->actions[$action->name()] = $action;
        }
    }

    public function get(string $name): ?ScheduleAction
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * @return list<string> sorted
     */
    public function names(): array
    {
        $names = array_keys($this->actions);
        sort($names);

        return $names;
    }
}
