<?php

declare(strict_types=1);

namespace App\Command\Ssh;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Ssh\ManagedSshKeys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes an extra key Fleet manages (FLE-266), only while no template names it. The private key is gone afterwards.
 */
#[AsCommand(name: 'fleet:ssh:key:delete', description: 'Delete an extra SSH key Fleet manages (refused while a template uses it)')]
final class SshKeyDeleteCommand extends Command
{
    public function __construct(private readonly ManagedSshKeys $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Key name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['name']));

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            if (!$out->confirmed($input, \sprintf('Delete the SSH key "%s"? Its private key is removed from this host.', $name))) {
                throw new Refused('Nothing was deleted.');
            }
            $this->keys->delete($name, AuditLog::CLI_ACTOR);
            $out->emit(['deleted' => $name], static function (JsonOutput $out) use ($name): void {
                $out->success(\sprintf('SSH key "%s" deleted.', $name));
            });
        });
    }
}
