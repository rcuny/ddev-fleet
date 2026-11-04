<?php

declare(strict_types=1);

namespace App\Command\Ssh;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Ssh\KnownHostConflict;
use App\Fleet\Ssh\KnownHostEntry;
use App\Fleet\Ssh\KnownHostScanner;
use App\Fleet\Ssh\KnownHostScannerUnavailable;
use App\Fleet\Ssh\KnownHostsFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds the host keys of a server to ssh/known_hosts (FLE-190), the file every instance's `.ddev/fleet_known_hosts` is
 * copied from, so an instance can reach that host with an extra SSH key. The keys are read with ssh-keyscan and are
 * trusted only after the person compared the fingerprints: a yes at the prompt, or --fingerprint for a script (then only
 * the keys with a listed fingerprint are added). Nothing is trusted blindly.
 */
#[AsCommand(name: 'fleet:ssh:trust', description: 'Add the host keys of a server to the fleet known hosts after you confirmed their fingerprints')]
final class SshTrustCommand extends Command
{
    public function __construct(
        private readonly KnownHostScanner $scanner,
        private readonly KnownHostsFile $knownHosts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('host', InputArgument::REQUIRED, 'Host name or address, or [host]:port for a non-standard port');
        $this->addOption('fingerprint', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Trust only the offered key with this SHA256 fingerprint, without a prompt (repeat for several)');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Trust every offered key without a prompt (only when you verified the fingerprints some other way; --fingerprint is safer)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['host']));

        return $out->run(function () use ($input, $out): void {
            try {
                $host = KnownHostEntry::normalizeHost((string) $input->getArgument('host'));
            } catch (\InvalidArgumentException) {
                throw new ValidationFailed(['host' => 'Not a valid SSH host name.']);
            }
            try {
                $offered = $this->scanner->scan($host);
            } catch (KnownHostScannerUnavailable) {
                throw new Refused('ssh-keyscan is not available on this server, so host keys cannot be read here.');
            }
            if ([] === $offered) {
                throw new Refused(\sprintf('"%s" offered no usable host key (unreachable, or no ed25519, ecdsa or rsa key).', $host));
            }

            $listed = array_map(static fn (mixed $f): string => (string) $f, (array) $input->getOption('fingerprint'));
            if ([] === $listed) {
                if (true !== $input->getOption('yes') && ($out->isJson() || !$input->isInteractive())) {
                    throw new Refused('Pass --fingerprint with the SHA256 fingerprint(s) you verified; the offered keys are: '.implode(', ', array_map(static fn (KnownHostEntry $e): string => $e->fingerprint(), $offered)));
                }
                if (true !== $input->getOption('yes')) {
                    $out->table(['Host', 'Type', 'Fingerprint'], array_map(static fn (KnownHostEntry $e): array => [$e->host, $e->type, $e->fingerprint()], $offered));
                }
                if (!$out->confirmed($input, 'Do these fingerprints match the ones you expect? Trust them?')) {
                    throw new Refused('Nothing was trusted.');
                }
                $trusted = $offered;
            } else {
                $trusted = array_values(array_filter($offered, static fn (KnownHostEntry $e): bool => \in_array($e->fingerprint(), $listed, true)));
                if ([] === $trusted) {
                    throw new Refused(\sprintf('"%s" offers no key with the fingerprint(s) you gave. Compare them again.', $host));
                }
            }

            try {
                $this->knownHosts->addAll($trusted);
            } catch (KnownHostConflict $e) {
                throw new Refused($e->getMessage());
            }

            $payload = ['host' => $host, 'trusted' => array_map(static fn (KnownHostEntry $e): array => ['type' => $e->type, 'fingerprint' => $e->fingerprint()], $trusted)];
            $out->emit($payload, static function (JsonOutput $out) use ($host, $trusted): void {
                $out->success(\sprintf('Trusted %d host key(s) for %s. Instances pick them up at their next deploy.', \count($trusted), $host));
            });
        });
    }
}
