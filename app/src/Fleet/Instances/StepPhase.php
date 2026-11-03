<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * Where in a deploy a DeployStep runs: after the checkout is written and before ddev starts, or once it is up.
 */
enum StepPhase: string
{
    case BeforeStart = 'before_start';
    case AfterStart = 'after_start';
}
