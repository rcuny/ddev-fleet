<?php

declare(strict_types=1);

namespace App\Command\Ssh;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Ssh\SshKeyCatalog;
use App\Fleet\Ssh\SshKeyEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every SSH key of the host in one list (FLE-266): deploy, push and the extra keys, with purpose, fingerprint, public half
 * and where each is used. Public data only.
 */
#[AsCommand(name: 'fleet:ssh:key:list', description: 'List the host\'s SSH keys (deploy, push, extras) with fingerprint, public key and where each is used')]
final class SshKeyListCommand extends Command
{
    public function __construct(private readonly SshKeyCatalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors([]));

        return $out->run(function () use ($out): void {
            $entries = $this->catalog->entries();
            $out->emit(
                ['keys' => array_map(static fn (SshKeyEntry $e): array => $e->toArray(), $entries)],
                static function (JsonOutput $out) use ($entries): void {
                    $out->table(['Name', 'Kind', 'Origin', 'Fingerprint', 'Used by', 'Created'], array_map(static fn (SshKeyEntry $e): array => [
                        $e->id,
                        $e->kind,
                        $e->origin,
                        $e->fingerprint ?? '-',
                        [] === $e->usedBy ? '-' : implode(', ', $e->usedBy),
                        $e->createdAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') ?? '-',
                    ], $entries));
                    foreach ($entries as $e) {
                        if (null !== $e->problem) {
                            $out->success(\sprintf('%s: %s', $e->id, $e->problem));
                        }
                        if (null !== $e->publicKey) {
                            $out->success(\sprintf('%s public key: %s', $e->id, $e->publicKey));
                        }
                    }
                },
            );
        });
    }
}
