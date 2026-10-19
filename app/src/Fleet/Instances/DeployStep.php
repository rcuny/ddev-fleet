<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Jobs\JobFailed;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An extension point of a deploy (asset seeding, the push key, tmux sessions). None is registered by M0.04; later
 * milestones add implementations and the workflow runs every one of the phase, in service order.
 */
#[AutoconfigureTag('fleet.deploy_step')]
interface DeployStep
{
    public function phase(): StepPhase;

    /**
     * @throws JobFailed to fail the deploy with a message the user may read
     */
    public function run(DeployContext $context): void;
}
