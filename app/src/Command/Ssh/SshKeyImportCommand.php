<?php

declare(strict_types=1);

namespace App\Command\Ssh;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Ssh\ManagedSshKeys;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports an existing unencrypted private key as an extra key (FLE-266). The key is never an argument or an option (it
 * would land in the shell history and the process list): a person pastes it at a hidden prompt, a script pipes it on
 * standard input.
 */
#[AsCommand(name: 'fleet:ssh:key:import', description: 'Import an existing private key as an extra SSH key (read from standard input)')]
final class SshKeyImportCommand extends Command
{
    public function __construct(private readonly ManagedSshKeys $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Key name: lower case letters, digits and single dashes');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['name']));

        return $out->run(function () use ($input, $output, $out): void {
            $name = (string) $input->getArgument('name');
            try {
                $key = $this->keys->import($name, $this->pem($input, $output), AuditLog::CLI_ACTOR);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationFailed(['name' => $e->getMessage()]);
            }
            $out->emit(
                ['imported' => $name, 'fingerprint' => $key->fingerprint(), 'public_key' => $key->publicKey()],
                static function (JsonOutput $out) use ($name, $key): void {
                    $out->success(\sprintf('SSH key "%s" imported (%s).', $name, $key->fingerprint()));
                },
            );
        });
    }

    private function pem(InputInterface $input, OutputInterface $output): string
    {
        if ($input->isInteractive()) {
            $answer = new SymfonyStyle($input, $output)->askHidden('Paste the private key as one line with \n for line breaks, or pipe the file on standard input instead (input is hidden)');

            return \is_string($answer) ? str_replace('\n', "\n", $answer) : '';
        }
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $content = stream_get_contents($stream ?? \STDIN, 16385 + 2);

        return false === $content ? '' : $content;
    }
}
