<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\UpgradeAssessment;
use App\Fleet\Upgrade\UpgradeAssessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal: the same assessment as `fleet:upgrade:check`, always as JSON, plus `--force=<id>` (repeatable) to waive
 * overridable pre-flight Blocks. The root flow of `fleet upgrade` (a later ticket) reads this document. Exit codes as
 * fleet:upgrade:check.
 */
#[AsCommand(
    name: 'fleet:upgrade:plan',
    description: 'Internal: the upgrade plan and pre-flight results as JSON',
    hidden: true,
)]
final class UpgradePlanCommand extends Command
{
    public function __construct(private readonly UpgradeAssessor $assessor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Plan this release (vX.Y.Z) instead of the latest')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Ask the release source now')
            ->addOption('force', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check id to waive (overridable Blocks only)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        $force = array_values(array_filter((array) $input->getOption('force'), 'is_string'));
        try {
            $assessment = $this->assessor->assess(\is_string($to) && '' !== $to ? $to : null, (bool) $input->getOption('no-cache'), $force);
            $document = $assessment->toArray();
            $code = $assessment->exitCode();
        } catch (\InvalidArgumentException $e) {
            [$document, $code] = [['schema' => UpgradeAssessment::SCHEMA, 'error' => $e->getMessage(), 'exitCode' => UpgradeCheckCommand::EXIT_USAGE], UpgradeCheckCommand::EXIT_USAGE];
        } catch (\Throwable $e) {
            [$document, $code] = [['schema' => UpgradeAssessment::SCHEMA, 'error' => $e->getMessage(), 'exitCode' => UpgradeCheckCommand::EXIT_ERROR], UpgradeCheckCommand::EXIT_ERROR];
        }
        $output->writeln(json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
