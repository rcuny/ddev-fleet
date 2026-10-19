<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Once ddev is up: restarts ddev-router if (and only if) its live entrypoints differ from the mirror fleet wants
 * (RouterConverger). Never fails a deploy: a mirror that cannot be applied is removed again and reported as a WARNING
 * line in the job log.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 200])]
final readonly class PortMirrorConvergeStep implements DeployStep
{
    public function __construct(private RouterConverger $converger)
    {
    }

    public function phase(): StepPhase
    {
        return StepPhase::AfterStart;
    }

    public function run(DeployContext $context): void
    {
        try {
            $message = $this->converger->converge($context->dir, $context->log->output(...));
        } catch (PortMirrorFailed $e) {
            $context->log->line('WARNING: '.$e->getMessage());

            return;
        } catch (\Throwable $e) {
            $context->log->line('WARNING: the in-network port mirror could not be checked: '.$e->getMessage());

            return;
        }
        if ('' !== $message) {
            $context->log->line('port mirror: '.$message);
        }
    }
}
