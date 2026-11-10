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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates an extra SSH key (ed25519) for templates to name (FLE-266) and prints its public half, to register where the
 * key is needed. The private key stays on the host.
 */
#[AsCommand(name: 'fleet:ssh:key:create', description: 'Generate an extra SSH key for templates and print its public key')]
final class SshKeyCreateCommand extends Command
{
    public function __construct(private readonly ManagedSshKeys $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Key name: lower case letters, digits and single dashes, for example deploy-docs (not deploy or push)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['name']));

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            try {
                $key = $this->keys->create($name, AuditLog::CLI_ACTOR);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationFailed(['name' => $e->getMessage()]);
            }
            $out->emit(
                ['created' => $name, 'fingerprint' => $key->fingerprint(), 'public_key' => $key->publicKey()],
                static function (JsonOutput $out) use ($name, $key): void {
                    $out->success(\sprintf('SSH key "%s" created (%s). Register this public key where the key is needed:', $name, $key->fingerprint()));
                    $out->success($key->publicKey());
                },
            );
        });
    }
}
