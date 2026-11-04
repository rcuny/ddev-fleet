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
 * `fleet upgrade --check`: what an upgrade would do, without doing it. Read-only and rootless. Exit codes (ruling R13):
 * 0 checked (up to date or an update is available), 1 error, 2 usage (unknown --to), 3 blocked by a pre-flight Block,
 * 4 the release source could not be read (the answer is from the cache, or missing). Note: these are not the
 * project-command codes of JsonOutput; this command has its own, scripted by `push-product`.
 */
#[AsCommand(
    name: 'fleet:upgrade:check',
    description: 'Show the installed version, the next release, its changelog and the pre-flight results (changes nothing)',
)]
final class UpgradeCheckCommand extends Command
{
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;

    public function __construct(private readonly UpgradeAssessor $assessor, private readonly AssessmentPrinter $printer = new AssessmentPrinter())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Check this release (vX.Y.Z) instead of the latest')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print one JSON document (schema in docs/upgrading.md)')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Ask the release source now instead of using a list younger than 6 hours')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $to = $input->getOption('to');
        $to = \is_string($to) && '' !== $to ? $to : null;

        try {
            $assessment = $this->assessor->assess($to, (bool) $input->getOption('no-cache'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($input, $output, $json, $e->getMessage(), self::EXIT_USAGE);
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $json, 'The upgrade check failed: '.$e->getMessage(), self::EXIT_ERROR);
        }

        if ($json) {
            $output->writeln(json_encode($assessment->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            $this->printer->print($output, $assessment);
        }

        return $assessment->exitCode();
    }

    private function fail(InputInterface $input, OutputInterface $output, bool $json, string $message, int $code): int
    {
        if ($json) {
            $output->writeln(json_encode(['schema' => UpgradeAssessment::SCHEMA, 'error' => $message, 'exitCode' => $code], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $code;
        }
        ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output)
            ->writeln(preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $message) ?? $message, OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
