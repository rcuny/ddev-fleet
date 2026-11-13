<?php

declare(strict_types=1);

namespace App\Command\Host;

use App\Fleet\Isolation\IsolationStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-299: `fleet host:isolation:status`. Read-only. Exit 0; 1 when enforce is on and the live table does not match; 3 when docker
 * did not answer. The flows list is the dry run of the links a host would need before `enforce`.
 */
#[AsCommand(name: 'fleet:host:isolation:status', description: 'Show the instance network isolation: mode, table, links and the flows recorded (the links `enforce` would need)')]
final class HostIsolationStatusCommand extends Command
{
    public function __construct(private readonly IsolationStatus $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $doc = $this->status->build();
        $code = false === $doc['docker'] ? 3 : (true === $doc['ok'] ? Command::SUCCESS : Command::FAILURE);
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return $code;
        }
        /* @var array{mode: string, table: array{installed: bool, readable: bool}, routers: list<string>, extraHostPorts: list<int>, links: list<array<string, mixed>>, flows: list<array<string, mixed>>, hostFlows: list<array<string, mixed>>, problems: list<string>} $doc */
        $output->writeln('mode: '.$doc['mode']);
        $output->writeln('table: '.($doc['table']['readable'] ? ($doc['table']['installed'] ? 'installed' : 'not installed') : 'unreadable'));
        $output->writeln('routers: '.([] === $doc['routers'] ? '-' : implode(', ', $doc['routers'])));
        $output->writeln('extra host ports: '.([] === $doc['extraHostPorts'] ? '-' : implode(', ', $doc['extraHostPorts'])));
        foreach ($doc['links'] as $l) {
            $output->writeln(\sprintf('link %s -> %s/%s:%d: %s%s', $l['source'], $l['target'], $l['service'], $l['port'], $l['state'], null === $l['reason'] ? '' : ' ('.$l['reason'].')'));
        }
        foreach ($doc['flows'] as $f) {
            $output->writeln(\sprintf('flow %s -> %s:%d %s', $f['source'], $f['target'], $f['port'], true === $f['covered'] ? '(covered by a link)' : ('' !== (string) $f['suggestion'] ? 'would need: '.$f['suggestion'] : '(not between two instances)')));
        }
        foreach ($doc['hostFlows'] as $f) {
            $output->writeln(\sprintf('container -> host: %s -> port %d (enforce would drop it; allow it with the extra host ports)', $f['source'], $f['port']));
        }
        foreach ($doc['problems'] as $p) {
            $output->writeln('PROBLEM: '.$p);
        }
        $output->writeln('Roll back: fleet host:isolation:disable (or, as root, nft delete table inet fleet_isolation)');

        return $code;
    }
}
