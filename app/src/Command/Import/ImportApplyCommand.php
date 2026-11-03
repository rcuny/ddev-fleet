<?php

declare(strict_types=1);

namespace App\Command\Import;

use App\Command\Support\FieldErrors;
use App\Command\Support\ImportOutput;
use App\Command\Support\JsonOutput;
use App\Command\Support\PassphraseInput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\ProjectImporter;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:import:apply', description: 'Import a project export file after showing the preview (all or nothing)')]
final class ImportApplyCommand extends Command
{
    public function __construct(private readonly ProjectImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The export file');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        $this->addOption(PassphraseInput::OPTION, null, InputOption::VALUE_NONE, 'Read the passphrase of the sealed secrets from the first line of standard input');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());
        $exit = JsonOutput::OK;

        $code = $out->run(function () use ($input, $output, $out, &$exit): void {
            try {
                $text = ExportFormat::readFile((string) $input->getArgument('file'));
            } catch (InvalidExport $e) {
                $exit = $out->fail($e->getMessage(), JsonOutput::INVALID);

                return;
            }
            $passphrase = $this->importer->hasSealedSecrets($text) ? PassphraseInput::read($input, $output, false, true, false) : null;
            $plan = $this->importer->preview($text, $passphrase);
            if ($plan->hasErrors()) {
                ImportOutput::show($out, $output, $plan);
                $exit = JsonOutput::INVALID;

                return;
            }
            if (!$plan->changes()) {
                ImportOutput::show($out, $output, $plan);

                return;
            }
            if (!$out->isJson()) {
                ImportOutput::show($out, $output, $plan);
            }
            if (!$out->confirmed($input, \sprintf('Import project "%s" as shown?', $plan->project))) {
                throw new Refused('Not imported: pass --yes to confirm.');
            }

            try {
                $done = $this->importer->apply($text, $passphrase, AuditLog::CLI_ACTOR);
            } catch (InvalidExport $e) {
                $exit = $out->fail($e->getMessage(), JsonOutput::INVALID);

                return;
            }
            if ($out->isJson()) {
                $out->document($done->toArray() + ['imported' => true]);

                return;
            }
            foreach ($done->warnings as $warning) {
                $out->withWarning([], $warning);
            }
            $out->success(\sprintf('Project "%s" imported.', $done->project));
        });

        return JsonOutput::OK !== $code ? $code : $exit;
    }
}
