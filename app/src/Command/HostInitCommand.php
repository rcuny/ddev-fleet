<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Ssh\KnownHostsFile;
use App\Fleet\System\ConfigValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepares this host for the setup wizard: the state directories, the secret key, the seeded known_hosts and the
 * host settings row. Safe to run again (the installer does on every upgrade); it never creates or changes the setup
 * token, the users or the SSH keys.
 */
#[AsCommand(
    name: 'fleet:host:init',
    description: 'Create the state directories, the secret key and the host settings (idempotent)',
)]
final class HostInitCommand extends Command
{
    public function __construct(
        private readonly FleetPaths $paths,
        private readonly SecretKeyFile $secretKey,
        private readonly KnownHostsFile $knownHosts,
        private readonly HostSettingsRepository $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'The fully qualified domain name the fleet is served on, e.g. fleet.example.com')
            ->addOption('acme-email', null, InputOption::VALUE_REQUIRED, 'Contact address for Let\'s Encrypt (optional)')
            ->addOption('tls-mode', null, InputOption::VALUE_REQUIRED, 'How certificates are obtained: http01 (default for a new host) or ovh_dns')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow changing the domain or the TLS mode after setup is complete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $domain = $this->optionString($input, 'domain');
            $domain = null === $domain ? null : ConfigValue::domain(strtolower(trim($domain)));
            $emailOption = $this->optionString($input, 'acme-email');
            $email = ConfigValue::acmeEmail($emailOption);
            $modeOption = $this->optionString($input, 'tls-mode');
            $mode = null === $modeOption ? null : (TlsMode::tryFrom($modeOption) ?? throw new \InvalidArgumentException('--tls-mode must be http01 or ovh_dns.'));
        } catch (\InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $host = $this->settings->get();
        if (null === $host && null === $domain) {
            $io->getErrorStyle()->error('--domain is required the first time.');

            return Command::FAILURE;
        }
        if (null !== $host && $host->isSetupComplete() && !$input->getOption('force')) {
            if (null !== $domain && $domain !== $host->domain()) {
                $io->getErrorStyle()->error('Setup is complete; changing the domain needs --force (and a fleet:host:render afterwards).');

                return Command::FAILURE;
            }
            if (null !== $mode && $mode !== $host->tlsMode()) {
                $io->getErrorStyle()->error('Setup is complete; changing the TLS mode needs --force (and a fleet:host:render afterwards).');

                return Command::FAILURE;
            }
        }

        try {
            $this->paths->ensureLayout();
            $this->secretKey->ensure();
            $this->knownHosts->seed();
        } catch (\RuntimeException $e) {
            $io->getErrorStyle()->error('The state directories could not be prepared: '.$e->getMessage());

            return Command::FAILURE;
        }

        $host ??= $this->settings->getOrCreate((string) $domain);
        if (null !== $domain) {
            $host->changeDomain($domain);
        }
        if (null !== $emailOption) {
            $host->changeAcmeEmail($email);
        }
        if (null !== $mode) {
            $host->changeTlsMode($mode);
        }
        $this->settings->save($host);

        $io->writeln(\sprintf('Host initialised: %s (%s).', $host->domain(), $host->tlsMode()->value));
        $io->writeln($host->isSetupComplete() ? 'Setup is complete.' : 'Setup is not complete; fleet:setup:token prints the one-time URL.');

        return Command::SUCCESS;
    }

    private function optionString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }
}
