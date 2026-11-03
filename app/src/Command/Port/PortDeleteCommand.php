<?php

declare(strict_types=1);

namespace App\Command\Port;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Projects\NamedPortService;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:port:delete', description: 'Remove a named port from the catalogue (refused while a project uses it)')]
final class PortDeleteCommand extends Command
{
    public function __construct(private readonly NamedPortService $ports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Port name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::port());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $port = $this->ports->get($name);
            // Refused while a project uses it: checked before asking, so nobody confirms something that cannot happen.
            if ([] === $this->ports->usedBy($port) && !$out->confirmed($input, \sprintf('Remove named port "%s"?', $name))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }
            $this->ports->delete($name);
            $out->emit(['deleted' => $name], static function (JsonOutput $out) use ($name): void {
                $out->success(\sprintf('Named port "%s" removed.', $name));
            });
        });
    }
}
