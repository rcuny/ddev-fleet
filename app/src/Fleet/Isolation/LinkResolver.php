<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Project\ProjectLink;
use App\Domain\Project\ProjectLinkRepository;
use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevUnavailable;

/**
 * FLE-299: turns the declared links into instance pairs. For every link and every instance of the source project the target
 * instance is `<target>--<source's label>` (label "same") or `<target>--<fixed label>`. A pair that cannot be live yet is
 * returned with the reason; it is never an error.
 */
final readonly class LinkResolver
{
    public function __construct(
        private ProjectLinkRepository $links,
        private InstanceRepository $instances,
        private Ddev $ddev,
    ) {
    }

    /**
     * @return list<ResolvedLink>
     *
     * @throws DdevUnavailable
     */
    public function resolve(): array
    {
        $links = $this->links->all();
        if ([] === $links) {
            return [];
        }
        $byId = [];
        foreach ($this->instances->all() as $instance) {
            $byId[$instance->id()] = $instance;
        }
        $containers = [];
        $resolved = [];
        foreach ($links as $link) {
            foreach ($byId as $source) {
                if ($source->project()->name() !== $link->source()->name()) {
                    continue;
                }
                $label = $link->isSameLabel() ? $source->label() : $link->targetLabel();
                $targetId = $link->target()->name().'--'.$label;
                $resolved[] = $this->resolveOne($link, $source, $byId[$targetId] ?? null, $targetId, $containers);
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, array<string, string>> $containers instance => service => container name (filled lazily)
     */
    private function resolveOne(ProjectLink $link, Instance $source, ?Instance $target, string $targetId, array &$containers): ResolvedLink
    {
        $make = static fn (?string $pending, ?string $src = null, ?string $dst = null): ResolvedLink => new ResolvedLink(
            $source->id(),
            $link->source()->name(),
            $targetId,
            $link->target()->name(),
            $link->service(),
            $link->port(),
            $pending,
            $src,
            $dst,
        );
        if (null === $target) {
            return $make('target instance '.$targetId.' does not exist');
        }
        $sourceContainers = $containers[$source->id()] ??= $this->services($source->id());
        $targetContainers = $containers[$target->id()] ??= $this->services($target->id());
        if (!isset($sourceContainers['web'])) {
            return $make('source instance '.$source->id().' has no running web container');
        }
        if (!isset($targetContainers[$link->service()])) {
            return $make('target has no running service '.$link->service());
        }

        return $make(null, $sourceContainers['web'], $targetContainers[$link->service()]);
    }

    /**
     * @return array<string, string> service => container name
     *
     * @throws DdevUnavailable
     */
    private function services(string $instanceId): array
    {
        $map = [];
        foreach ($this->ddev->projectContainers($instanceId) as $container) {
            $map[$container['service']] = $container['name'];
        }

        return $map;
    }
}
