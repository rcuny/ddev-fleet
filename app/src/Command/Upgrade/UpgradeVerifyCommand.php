<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\UpgradeRunner;
use App\Fleet\Upgrade\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `fleet upgrade verify`: is this host healthy on version `--to`? Run by `sudo fleet upgrade` after the installer; also
 * usable by hand. Prints one line per step. Exit codes: 0 verified, 2 usage, 5 verification failed.
 */
#[AsCommand(
    name: 'fleet:upgrade:verify',
    description: 'Verify the host after an upgrade: health, Authelia, installed version, migrations, workers, audit row',
)]
final class UpgradeVerifyCommand extends Command
{
    public function __construct(private readonly UpgradeRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The version the host should run now (vX.Y.Z)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The version it ran before (for the audit row and the rollback hint)', 'unknown')
            ->addOption('sha', null, InputOption::VALUE_REQUIRED, 'The commit the plan resolved for --to (the checkout must be at it)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who ran the upgrade (unix user, for the audit row)', 'cli')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = $input->getOption('to');
        $version = \is_string($to) ? Version::tryParse($to) : null;
        if (null === $version) {
            $output->writeln('<error>--to vX.Y.Z is required</error>');

            return 2;
        }
        $sha = $input->getOption('sha');
        $from = self::label($input->getOption('from'), 'unknown');
        $report = $this->runner->verify($from, $version->tag(), \is_string($sha) && 1 === preg_match('/^[0-9a-f]{40}$/D', $sha) ? $sha : null, self::label($input->getOption('actor'), 'cli'));

        foreach ($report->results as $result) {
            $output->writeln(\sprintf('%-4s %-10s %s', $result->ok ? 'ok' : ($result->critical ? 'FAIL' : 'warn'), $result->name, $result->detail), OutputInterface::OUTPUT_RAW);
        }
        if (!$report->passed()) {
            $output->writeln(\sprintf('Verification failed. To go back: sudo fleet upgrade --to %s', $from), OutputInterface::OUTPUT_RAW);

            return UpgradeRunner::EXIT_VERIFY_FAILED;
        }

        return Command::SUCCESS;
    }

    private static function label(mixed $value, string $default): string
    {
        return \is_string($value) && 1 === preg_match('/^[A-Za-z0-9._\/@-]{1,100}$/D', $value) ? $value : $default;
    }
}
