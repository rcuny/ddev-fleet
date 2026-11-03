<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The instances the current user may operate: an admin sees all, a member those of the projects they belong to.
 * Pages and controllers read instances through this class, so an instance of a foreign project is simply absent
 * (callers answer 404, never 403: its existence is not disclosed).
 *
 * The per-project decisions are remembered for one request or one Messenger message only: reset() clears them, so a
 * long-lived worker never reuses a stale authorization decision.
 */
final class VisibleInstances implements ResetInterface
{
    /** @var array<int, bool> project id => may operate, decided once per project per request */
    private array $operable = [];

    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    /**
     * @return list<Instance> ordered by id
     */
    public function all(): array
    {
        return array_values(array_filter($this->instances->all(), $this->mayOperate(...)));
    }

    /**
     * Null when the instance does not exist or the user may not operate it.
     */
    public function find(string $id): ?Instance
    {
        $instance = $this->instances->find($id);

        return null !== $instance && $this->mayOperate($instance) ? $instance : null;
    }

    public function reset(): void
    {
        $this->operable = [];
    }

    private function mayOperate(Instance $instance): bool
    {
        $project = $instance->project();
        $key = $project->id() ?? 0;

        return $this->operable[$key] ??= $this->auth->isGranted(ProjectVoter::OPERATE, $project);
    }
}
