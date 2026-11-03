<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\ConfigValue;
use App\Fleet\System\SystemRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces the OVH DNS-01 credentials after setup, without `fleet setup:reset`: stores the new values (sealed) and has
 * the root helper install the Caddyfile and the credential files, which reloads Caddy. The credentials are never an
 * argument or an option (shell history, process list): a person gets hidden prompts, a script pipes three lines.
 */
#[AsCommand(
    name: 'fleet:host:ovh-credentials',
    description: 'Replace the OVH application key, secret and consumer key of a DNS-mode host, then apply and reload Caddy',
    help: <<<'HELP'
        Needs a host in <info>ovh_dns</info> mode. Asks for the application key, the application secret and the consumer key
        with the input hidden; with no terminal it reads them from standard input, one per line in that order.
        <info>--endpoint=ovh-eu|ovh-ca|ovh-us</info> changes the API endpoint as well (default: the stored one).

        Nothing is stored unless all three values are valid. Once setup is complete the Caddyfile and the credential files
        are installed through the root helper (sudo) and Caddy reloads; before that the wizard's Finish step does it.
        If the install fails, the previous files are put back and the new values stay stored: fix the cause and run
        the command again.
        HELP,
)]
final class HostOvhCredentialsCommand extends Command
{
    /** Secret store name => prompt label, in the order the values are read. */
    private const array CREDENTIALS = [
        'ovh_application_key' => 'OVH application key',
        'ovh_application_secret' => 'OVH application secret',
        'ovh_consumer_key' => 'OVH consumer key',
    ];

    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SecretStore $secrets,
        private readonly SystemRenderer $system,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'OVH API endpoint: '.implode(', ', ConfigValue::OVH_ENDPOINTS).' (default: the stored one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();

        $host = $this->settings->get();
        if (null === $host) {
            $errors->error('The host is not initialised; run fleet:host:init first.');

            return Command::FAILURE;
        }
        if (TlsMode::OvhDns !== $host->tlsMode()) {
            $errors->error('This host does not use the OVH DNS challenge (TLS mode is '.$host->tlsMode()->value.').');

            return Command::FAILURE;
        }

        if (null === $input->getOption('endpoint') && !$this->secrets->hasFleetWide('ovh_endpoint')) {
            $errors->error('No OVH endpoint is stored; pass --endpoint='.implode('|', ConfigValue::OVH_ENDPOINTS).'.');

            return Command::FAILURE;
        }

        try {
            $values = $this->validated($input, $output);
        } catch (\InvalidArgumentException $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->secrets->putManyFleetWide($values, AuditLog::CLI_ACTOR);
        } catch (SecretConflict) {
            $errors->error('The secrets were changed at the same moment. Run the command again.');

            return Command::FAILURE;
        }
        $io->writeln('OVH credentials stored.');

        if (!$host->isSetupComplete()) {
            $io->writeln('Setup is not finished: the Finish step installs them.');

            return Command::SUCCESS;
        }

        try {
            $this->system->stageAndApply(ApplyTarget::Caddy, $host);
        } catch (\RuntimeException $e) {
            $errors->error('The new credentials are stored, but they could not be installed: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln('Applied: caddy (the credential files are installed and Caddy reloaded).');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string> secret name => value, endpoint included only when it is given
     *
     * @throws \InvalidArgumentException
     */
    private function validated(InputInterface $input, OutputInterface $output): array
    {
        $values = [];
        $endpoint = $input->getOption('endpoint');
        if (null !== $endpoint) {
            $values['ovh_endpoint'] = ConfigValue::ovhEndpoint((string) $endpoint);
        }
        foreach ($this->read($input, $output) as $name => $value) {
            $value = trim($value);
            if ('' === $value) {
                throw new \InvalidArgumentException(\sprintf('The %s is missing; all three values are needed.', self::CREDENTIALS[$name]));
            }
            $values[$name] = ConfigValue::ovhCredential($name, $value);
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function read(InputInterface $input, OutputInterface $output): array
    {
        $answers = [];
        if ($input->isInteractive()) {
            $io = new SymfonyStyle($input, $output);
            foreach (self::CREDENTIALS as $name => $label) {
                $answer = $io->askHidden($label.' (input is hidden)');
                $answers[$name] = \is_string($answer) ? $answer : '';
            }

            return $answers;
        }
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $lines = explode("\n", (string) stream_get_contents($stream ?? \STDIN, 4096));
        foreach (array_keys(self::CREDENTIALS) as $i => $name) {
            $answers[$name] = $lines[$i] ?? '';
        }

        return $answers;
    }
}
