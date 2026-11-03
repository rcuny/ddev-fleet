<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Projects\FirewallPorts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:firewall', description: 'List the public ports to open in the host firewall')]
final class PortFirewallCommand extends Command
{
    public function __construct(private readonly FirewallPorts $firewall)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::port());

        return $out->run(function () use ($out, $output): void {
            $ports = $this->firewall->list();
            $out->emit(['ports' => $ports], static function (JsonOutput $out) use ($ports, $output): void {
                $out->table(
                    ['PORT', 'PURPOSE', 'PROJECTS', 'COMMAND'],
                    array_map(static fn (array $row): array => [$row['port'], $row['purpose'], implode(', ', $row['projects']) ?: '-', $row['ufw']], $ports),
                );
                $output->writeln('Note: the installer does not change the firewall. Run these commands yourself (or add the ports to your provider firewall).');
            });
        });
    }
}
