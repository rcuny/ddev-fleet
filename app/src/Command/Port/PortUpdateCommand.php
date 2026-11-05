<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Fleet\Projects\NamedPortService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:update', description: 'Change who may reach a named port (gated or public); run fleet:port:refresh afterwards')]
final class PortUpdateCommand extends Command
{
    public function __construct(private readonly NamedPortService $ports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Port name');
        $this->addOption('access', null, InputOption::VALUE_REQUIRED, 'gated (behind the login) or public (no login) (required)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::port());

        return $out->run(function () use ($input, $out): void {
            $port = $this->ports->setAccess((string) $input->getArgument('name'), (string) $input->getOption('access'));
            $out->emit(Shapes::port($port, $this->ports->usedBy($port)), static function (JsonOutput $out) use ($port): void {
                $out->success(\sprintf('Named port "%s" is now %s. Run fleet:port:refresh to rewrite the Caddy sites.', $port->name(), $port->access()));
            });
        });
    }
}
