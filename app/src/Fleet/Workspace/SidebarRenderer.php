<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Instance\InstanceRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Projects\Refused;

/**
 * The text of an instance window's sidebar pane: its state and URL from the database, and the live branches of its checkout.
 * Plain text, so the pane (and a test) shows exactly what is returned.
 */
final readonly class SidebarRenderer
{
    public function __construct(
        private InstanceRepository $instances,
        private InstanceHostnames $hostnames,
        private GitClient $git,
        private FleetPaths $paths,
    ) {
    }

    /**
     * @throws WorkspaceException when the id is not an instance id
     */
    public function render(string $instanceId): string
    {
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $instanceId)) {
            throw new WorkspaceException('That is not an instance id.');
        }
        $instance = $this->instances->find($instanceId);
        if (null === $instance) {
            return \sprintf("%s\nThis instance no longer exists.\n", $instanceId);
        }

        try {
            $url = $this->hostnames->siteUrl($instanceId);
        } catch (Refused) {
            $url = '(the host is not set up yet)';
        }
        $head = $instance->headSha();
        $lines = [
            $instance->id(),
            \sprintf('state    %s', $instance->state()->value),
            \sprintf('url      %s', $url),
            \sprintf('project  %s', $instance->project()->name()),
            \sprintf('branch   %s%s', $instance->branch(), null === $head || '' === $head ? '' : ' @ '.$head),
            '',
            'branches',
        ];
        $branches = $this->git->localBranches($this->paths->instanceDir($instanceId));
        if ([] === $branches) {
            $lines[] = '  (none readable)';
        }
        foreach ($branches as $branch) {
            $lines[] = ($branch['current'] ? '* ' : '  ').$branch['name'];
        }

        return implode("\n", $lines)."\n";
    }
}
