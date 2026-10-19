<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Project\Project;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;

/**
 * The host names of an instance: `<id>.<domain>`, plus `<hostname>-<id>.<domain>` for each additional hostname of its
 * project (each a single DNS label, so at most 63 characters).
 */
final readonly class InstanceHostnames
{
    public function __construct(private HostSettingsRepository $host)
    {
    }

    public function primary(string $id): string
    {
        return $id.'.'.$this->domain();
    }

    /**
     * @return list<string>
     *
     * @throws ValidationFailed when a label would be longer than 63 characters
     */
    public function aliases(Project $project, string $id): array
    {
        $aliases = [];
        foreach ($project->additionalHostnames() as $hostname) {
            $label = $hostname.'-'.$id;
            if (\strlen($label) > InstanceNaming::MAX) {
                throw new ValidationFailed(['label' => \sprintf('The host name label "<hostname>-<id>" would be %d characters; the limit is %d. Use a shorter label.', \strlen($label), InstanceNaming::MAX)]);
            }
            $aliases[] = $label.'.'.$this->domain();
        }

        return $aliases;
    }

    /**
     * Validates the alias labels of an instance when the host has a domain already (before the setup wizard has run
     * there is nothing to resolve against, and no deploy can happen either).
     *
     * @throws ValidationFailed
     */
    public function aliasesIfSetUp(Project $project, string $id): void
    {
        if (null !== $this->host->get()) {
            $this->aliases($project, $id);
        }
    }

    public function siteUrl(string $id): string
    {
        return 'https://'.$this->primary($id);
    }

    /**
     * @throws Refused when the host has no domain yet
     */
    public function domain(): string
    {
        return $this->host->get()?->domain() ?? throw new Refused('The host is not set up yet: there is no domain.');
    }
}
