<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Fleet\FleetPaths;
use Psr\Log\LoggerInterface;

/**
 * Rewrites the Caddy sites of existing instances after something the site depends on changed (today: the named ports
 * assigned to a project). Only instances that already have a site are touched: a deploy writes its own, and a failed
 * or destroyed instance must not get one back.
 *
 * The change that triggered the refresh is already committed, so a failure is a warning, never a rollback: Caddy's
 * refusal rolls that one site back (InstanceSiteWriter), the rest continue, and the failures are logged and kept for the
 * caller to show (takeWarning()). `fleet port:refresh` retries.
 */
final class InstanceSiteRefresher
{
    private ?string $warning = null;
    /** @var list<string> */
    private array $skipped = [];

    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly InstanceSiteWriter $sites,
        private readonly FleetPaths $paths,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param Project|null $project only the instances of this project; null for every instance
     * @param bool         $reload  false writes the files only (see InstanceSiteWriter::write())
     */
    public function refresh(?Project $project = null, bool $reload = true): SiteRefresh
    {
        $rewritten = 0;
        $failed = [];
        $skipped = [];
        foreach (null === $project ? $this->instances->all() : $this->instances->byProject($project) as $instance) {
            if (!$this->hasSite($instance)) {
                continue;
            }
            if (!InstanceNaming::isInstanceId($instance->id())) {
                // Created before the 59 character cap (FLE-144): it cannot run (Docker refuses its host name), so its
                // site is left alone rather than failing the whole refresh and the host upgrade.
                $skipped[] = $instance->id();
                $this->logger?->warning('The Caddy site of an over-long instance id was not refreshed.', ['instance' => $instance->id()]);

                continue;
            }
            try {
                $this->sites->write($instance, $instance->project(), $reload);
                ++$rewritten;
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $failed[$instance->id()] = $e->getMessage();
                $this->logger?->warning('The Caddy site of an instance was not refreshed.', ['instance' => $instance->id(), 'exception' => $e]);
            }
        }

        $notice = [] === $skipped ? '' : \sprintf('Skipped over-long instance id(s) (more than 59 characters, they cannot run): %s. Destroy them with "fleet instance:destroy <id>".', implode(', ', $skipped));
        $this->skipped = $skipped;
        $this->warning = [] === $failed ? ([] === $skipped ? null : $notice) : \sprintf(
            'The change was saved, but the Caddy site of %d instance(s) was not updated (%s). Fix the cause and run "fleet port:refresh".',
            \count($failed),
            implode('; ', array_map(static fn (string $id, string $reason): string => $id.': '.$reason, array_keys($failed), $failed)),
        );

        return new SiteRefresh($rewritten, $failed);
    }

    /**
     * The warning left by the last refresh(), once.
     */
    /**
     * The over-long ids the last refresh() skipped.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function takeWarning(): ?string
    {
        $warning = $this->warning;
        $this->warning = null;

        return $warning;
    }

    private function hasSite(Instance $instance): bool
    {
        return InstanceState::Destroying !== $instance->state() && InstanceNaming::isExistingInstanceId($instance->id()) && is_file($this->paths->instanceSiteFile($instance->id()));
    }
}
