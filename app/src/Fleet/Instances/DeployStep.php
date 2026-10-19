<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Jobs\JobFailed;

/**
 * An extension point of a deploy (asset seeding, the push key, tmux sessions). The workflow runs every step of a phase
 * in tag-priority order (higher first). An implementation tags itself, with its priority, on its own class:
 * `#[AutoconfigureTag('fleet.deploy_step', ['priority' => 100])]`. The tag is not on this interface on purpose: an
 * interface tag has no priority and would register a prioritised step twice. DeployStepRegistrationTest fails for a
 * step that is not tagged. Order: AssetsSeedStep (BeforeStart, 100), PortMirrorSyncStep (BeforeStart, 50),
 * PortMirrorConvergeStep (AfterStart, 200, so the router is settled before the tmux window's commands run).
 */
interface DeployStep
{
    public function phase(): StepPhase;

    /**
     * @throws JobFailed to fail the deploy with a message the user may read
     */
    public function run(DeployContext $context): void;
}
