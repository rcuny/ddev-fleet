<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Domain\Port\NamedPort;
use App\Fleet\Projects\NamedPortService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:list', description: 'List the named-port catalogue')]
final class PortListCommand extends Command
{
    public function __construct(private readonly NamedPortService $ports)
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

        return $out->run(function () use ($out): void {
            $ports = array_map(fn (NamedPort $port): array => Shapes::port($port, $this->ports->usedBy($port)), $this->ports->list());
            $out->emit($ports, static fn (JsonOutput $out) => $out->table(
                ['NAME', 'PUBLIC', 'ROUTER', 'PROJECTS'],
                array_map(static fn (array $port): array => [$port['name'], $port['public'], $port['router'], implode(', ', $port['projects']) ?: '-'], $ports),
            ));
        });
    }
}
