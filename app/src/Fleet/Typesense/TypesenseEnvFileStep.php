<?php

declare(strict_types=1);

namespace App\Fleet\Typesense;

use App\Fleet\Instances\DeployContext;
use App\Fleet\Instances\DeployStep;
use App\Fleet\Instances\EnvFile;
use App\Fleet\Instances\StepPhase;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\SecretRegistry;
use App\Fleet\Secrets\SecretOpenFailed;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Before `ddev start`, for a Typesense project: puts `TYPESENSE_API_KEY` in `.ddev/.env`, which the project's committed
 * `.ddev/docker-compose.typesense.yaml` interpolates to boot its Typesense container (the fleet installs no server).
 * The file is not world readable and kept out of the project's git status. Runs after the assets seed (100), so a
 * `.ddev/.env` from the assets is edited, not replaced; the other variables in it stay as they are.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => 40])]
final readonly class TypesenseEnvFileStep implements DeployStep
{
    public const string RELATIVE_PATH = '.ddev/.env';

    public function __construct(
        private TypesenseKeys $keys,
        private SecretRegistry $registry,
    ) {
    }

    public function phase(): StepPhase
    {
        return StepPhase::BeforeStart;
    }

    public function run(DeployContext $context): void
    {
        if (!$context->project->typesense()) {
            return;
        }

        try {
            $keys = $this->keys->ensure($context->project);
        } catch (SecretOpenFailed) {
            throw new JobFailed('typesense: a stored Typesense key could not be opened; nothing was written.');
        }
        $this->registry->register($keys->admin);
        $this->registry->register($keys->search);

        $ddevDir = $context->dir.'/.ddev';
        if (!is_dir($ddevDir) && !@mkdir($ddevDir, 0o750, true) && !is_dir($ddevDir)) {
            throw new JobFailed('typesense: cannot create the .ddev directory.');
        }
        $path = $context->dir.'/'.self::RELATIVE_PATH;
        EnvFile::setVar($path, TypesenseKeys::ADMIN, $keys->admin);
        @chmod($path, 0o640);
        $context->exclude('/'.self::RELATIVE_PATH);
        $context->log->line('typesense: admin key written to '.self::RELATIVE_PATH);
    }
}
