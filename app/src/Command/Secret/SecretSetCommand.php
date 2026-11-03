<?php

declare(strict_types=1);

namespace App\Command\Secret;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The value is never an argument or an option (it would land in the shell history and the process list): a person is
 * asked for it with the input hidden, a script pipes it on standard input.
 */
#[AsCommand(name: 'fleet:secret:set', description: 'Add a secret or replace its value (the value is read from a hidden prompt or standard input)')]
final class SecretSetCommand extends Command
{
    public function __construct(private readonly SecretService $secrets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Secret name: capital letters, digits and underscores, for example JIRA_TOKEN');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Set a secret of this project instead of a fleet-wide one');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::secret());

        return $out->run(function () use ($input, $output, $out): void {
            $name = (string) $input->getArgument('name');
            $project = SecretOptions::project($input);
            $value = $this->value($input, $output);

            try {
                $this->secrets->put($project, $name, $value, AuditLog::CLI_ACTOR);
            } catch (SecretConflict) {
                throw new Refused('The secret was changed at the same moment. Run the command again.');
            }
            $out->emit(['saved' => $name, 'project' => $project], static function (JsonOutput $out) use ($name, $project): void {
                $out->success(null === $project ? \sprintf('Secret "%s" saved.', $name) : \sprintf('Secret "%s" of project "%s" saved.', $name, $project));
            });
        });
    }

    private function value(InputInterface $input, OutputInterface $output): string
    {
        if ($input->isInteractive()) {
            $answer = new SymfonyStyle($input, $output)->askHidden('Value (input is hidden)');

            return \is_string($answer) ? $answer : '';
        }
        $stream = $input instanceof \Symfony\Component\Console\Input\StreamableInputInterface ? $input->getStream() : null;
        $content = stream_get_contents($stream ?? \STDIN, 65537 + 2);

        return false === $content ? '' : $content;
    }
}
