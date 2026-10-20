<?php

declare(strict_types=1);

namespace App\Command\Export;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\PassphraseInput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\ProjectExporter;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:export:project', description: 'Export one project as a versioned file (stdout, or --output)')]
final class ExportProjectCommand extends Command
{
    public function __construct(private readonly ProjectExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Project name (its slug)');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the file here (mode 0600, refused when it exists) instead of stdout');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace the --output file when it exists');
        $this->addOption('with-secrets', null, InputOption::VALUE_NONE, 'Add the project\'s secrets, sealed with a passphrase (prompt, '.PassphraseInput::ENV.' or --'.PassphraseInput::OPTION.')');
        $this->addOption(PassphraseInput::OPTION, null, InputOption::VALUE_NONE, 'Read the passphrase from the first line of standard input');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());
        $exit = JsonOutput::OK;

        $code = $out->run(function () use ($input, $output, $out, &$exit): void {
            $name = (string) $input->getArgument('slug');
            $file = $input->getOption('output');
            $withSecrets = true === $input->getOption('with-secrets');
            if (!$withSecrets && true === $input->getOption(PassphraseInput::OPTION)) {
                throw new Refused('--'.PassphraseInput::OPTION.' only applies together with --with-secrets.');
            }
            $passphrase = $withSecrets ? PassphraseInput::read($input, $output, true, false) : null;

            try {
                $text = $this->exporter->export($name, $passphrase, AuditLog::CLI_ACTOR);
            } catch (InvalidExport $e) {
                $exit = $out->fail($e->getMessage(), JsonOutput::INVALID);

                return;
            }

            if (!\is_string($file) || '' === $file) {
                $output->write($text, false, OutputInterface::OUTPUT_RAW);

                return;
            }
            self::write($file, $text, true === $input->getOption('force'));
            $out->emit(['exported' => $name, 'file' => $file, 'secrets' => $withSecrets], static function (JsonOutput $out) use ($name, $file, $withSecrets): void {
                $out->success(\sprintf('Project "%s" exported to %s%s.', $name, $file, $withSecrets ? ' (secrets sealed)' : ' (no secrets)'));
            });
        });

        return JsonOutput::OK !== $code ? $code : $exit;
    }

    /**
     * Writes next to the target and renames, so a reader never sees half a file; the mode is 0600 before any byte lands.
     */
    private static function write(string $file, string $text, bool $force): void
    {
        if (!$force && file_exists($file)) {
            throw new Refused('The output file exists: pass --force to replace it.');
        }
        $directory = \dirname($file);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new Refused('The output directory does not exist or is not writable.');
        }
        $temporary = tempnam($directory, '.export-');
        if (false === $temporary) {
            throw new Refused('The output file could not be created.');
        }
        try {
            chmod($temporary, 0o600);
            if (false === file_put_contents($temporary, $text) || !rename($temporary, $file)) {
                throw new Refused('The output file could not be written.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
