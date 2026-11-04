<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Upgrade\Check\CheckRunner;
use Psr\Clock\ClockInterface;

/**
 * The read side of an upgrade in one call: what is installed, what the release source offers (cache first, one refresh
 * when it is stale or --no-cache), the changelog between the two, and the pre-flight checks. Changes nothing. It never
 * throws for an unreachable source: that is an offline UpgradeAssessment (the command exits 4).
 */
final readonly class UpgradeAssessor
{
    public function __construct(
        private InstalledVersion $installed,
        private ReleaseCatalog $catalog,
        private UpgradePlanner $planner,
        private ChangelogReader $changelogs,
        private CheckRunner $checks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null  $to    an explicit target (`--to`), in any spelling Version accepts; null means the latest stable
     * @param list<string> $force check ids to waive (overridable Blocks only)
     *
     * @throws \InvalidArgumentException when $to is not a version, or (UnknownRelease) not a published release
     */
    public function assess(?string $to = null, bool $noCache = false, array $force = []): UpgradeAssessment
    {
        $refreshed = false;
        $state = $this->catalog->state();
        if ($noCache || !$this->catalog->isFresh()) {
            $state = $this->catalog->refresh();
            $refreshed = true;
        }

        $target = null;
        if (null !== $to) {
            $target = Version::tryParse($to) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a version (vX.Y.Z); available: %s', $to, $this->tagList($state)));
        }

        $raw = $this->installed->raw();
        $now = $this->clock->now();
        $latest = $state->latest();

        // A stale catalog that does not know the requested release cannot say it does not exist.
        if (null !== $target && $state->hasFailed() && null === $state->find($target)) {
            return $this->offline($raw, $latest, $state, $refreshed, $now);
        }
        $plan = $this->planner->plan($raw, $state, $target);

        if ($state->hasFailed() || !$plan->hasTarget()) {
            return new UpgradeAssessment($plan, $latest, $state, $refreshed, $now, [], null, null, [$this->skipReason($state)], $plan->releases);
        }

        $sections = [];
        $changelogError = null;
        if (Direction::Up === $plan->direction || Direction::Unknown === $plan->direction) {
            try {
                \assert(null !== $plan->target);
                $sections = $this->changelogs->between($plan->fromVersion, $plan->target->version);
            } catch (UpgradeSourceUnavailable $e) {
                $changelogError = $e->getMessage();
            }
        }
        if (null !== $changelogError) {
            return new UpgradeAssessment($plan, $latest, $state, $refreshed, $now, [], $changelogError, null, ['the changelog of the target could not be read'], $plan->releases);
        }

        if ($plan->isUpToDate()) {
            return new UpgradeAssessment($plan, $latest, $state, $refreshed, $now, $sections, null, null, ['nothing to upgrade'], $plan->releases);
        }

        return new UpgradeAssessment($plan, $latest, $state, $refreshed, $now, $sections, null, $this->checks->run($plan, $force), [], $plan->releases);
    }

    private function offline(string $raw, ?Release $latest, CatalogState $state, bool $refreshed, \DateTimeImmutable $now): UpgradeAssessment
    {
        $plan = new Plan($raw, Version::tryParse($raw), null, Direction::Unknown, []);

        return new UpgradeAssessment($plan, $latest, $state, $refreshed, $now, [], null, null, [$this->skipReason($state)]);
    }

    private function skipReason(CatalogState $state): string
    {
        return $state->hasFailed() ? 'the release source could not be read' : 'no release is known yet';
    }

    private function tagList(CatalogState $state): string
    {
        $tags = array_map(static fn (Release $r): string => $r->tag(), $state->stable());

        return [] === $tags ? 'none known (run a check first)' : implode(', ', $tags);
    }
}
