<?php

declare(strict_types=1);

namespace App\Command\Import;

use App\Command\Support\FieldErrors;
use App\Command\Support\ImportOutput;
use App\Command\Support\JsonOutput;
use App\Command\Support\PassphraseInput;
use App\Fleet\Export\ExportFormat;
use App\Fleet\Export\InvalidExport;
use App\Fleet\Export\ProjectImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:import:preview', description: 'Show what importing a project export file would create, update or skip (writes nothing)')]
final class ImportPreviewCommand extends Command
{
    public function __construct(private readonly ProjectImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The export file');
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
            ImportOutput::show($out, $output, $plan);
            if ($plan->hasErrors()) {
                $exit = JsonOutput::INVALID;
            }
        });

        return JsonOutput::OK !== $code ? $code : $exit;
    }
}
