<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\StepPhase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Before `ddev start`: puts the mirror files in place, so the start that follows merges them into the router's static
 * configuration. Never fails a deploy: a problem is a WARNING line in the job log.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 50])]
final readonly class PortMirrorSyncStep implements DeployStep
{
    public function __construct(private PortMirror $mirror)
    {
    }

    public function phase(): StepPhase
    {
        return StepPhase::BeforeStart;
    }

    public function run(DeployContext $context): void
    {
        try {
            $result = $this->mirror->sync();
        } catch (\Throwable $e) {
            $context->log->line('WARNING: the in-network port mirror files were not updated: '.$e->getMessage());

            return;
        }
        foreach ($result->skipped as $reason) {
            $context->log->line('port mirror: '.$reason);
        }
        if ($result->changed) {
            $context->log->line('port mirror files updated ('.([] === $result->mirrored ? 'none mirrored' : 'public ports '.implode(', ', $result->mirrored)).')');
        }
    }
}
