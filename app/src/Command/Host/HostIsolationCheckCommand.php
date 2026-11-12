<?php

declare(strict_types=1);

namespace App\Command\Host;

use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Isolation\IsolationCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-299: `fleet host:isolation:check [--json]`, the live probe. Exit 0 when every probe answered as the mode expects, 1 on any
 * unexpected reachability, 3 when docker did not answer. Run it after every rollout step and after each restart kind.
 */
#[AsCommand(name: 'fleet:host:isolation:check', description: 'Probe from inside running instances what is reachable and compare it with what the mode expects')]
final class HostIsolationCheckCommand extends Command
{
    public function __construct(private readonly IsolationCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $rows = $this->check->run();
        } catch (DdevUnavailable $e) {
            $output->writeln('<error>docker did not answer: '.$e->getMessage().'</error>');

            return 3;
        }
        $failed = array_filter($rows, static fn (array $r): bool => !$r['ok']);
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['ok' => [] === $failed, 'probes' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return [] === $failed ? Command::SUCCESS : Command::FAILURE;
        }
        foreach ($rows as $r) {
            $output->writeln(\sprintf('%s %s -> %s:%d expected %s, got %s', $r['ok'] ? 'ok  ' : 'FAIL', $r['from'], $r['to'], $r['port'], $r['expected'], $r['actual']));
        }
        $output->writeln([] === $failed ? 'All probes behaved as expected.' : \sprintf('%d probe(s) behaved unexpectedly.', \count($failed)));

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
