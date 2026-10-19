<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Fleet\Projects\NamedPortInput;
use App\Fleet\Projects\NamedPortService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:create', description: 'Add a named port to the catalogue')]
final class PortCreateCommand extends Command
{
    public function __construct(private readonly NamedPortService $ports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Port name, for example typesense');
        $this->addOption('public', null, InputOption::VALUE_REQUIRED, 'Port Caddy listens on (required)');
        $this->addOption('router', null, InputOption::VALUE_REQUIRED, 'Port of the DDEV router the traffic is forwarded to (required)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::port());

        return $out->run(function () use ($input, $out): void {
            $port = $this->ports->create(new NamedPortInput(
                (string) $input->getArgument('name'),
                (string) $input->getOption('public'),
                (string) $input->getOption('router'),
            ));
            $out->emit(Shapes::port($port, []), static function (JsonOutput $out) use ($port): void {
                $out->success(\sprintf('Named port "%s" created (public %d, router %d).', $port->name(), $port->publicPort(), $port->routerPort()));
            });
        });
    }
}
