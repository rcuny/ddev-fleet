<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\TemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:template:delete', description: 'Delete a template (refused for the default template of the project)')]
final class TemplateDeleteCommand extends Command
{
    public function __construct(private readonly TemplateService $templates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        $this->addArgument('name', InputArgument::REQUIRED, 'Template name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::template());

        return $out->run(function () use ($input, $out): void {
            $project = (string) $input->getArgument('project');
            $name = (string) $input->getArgument('name');
            $this->templates->get($project, $name);
            if (!$out->confirmed($input, \sprintf('Delete template "%s" of project "%s"?', $name, $project))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }
            $this->templates->delete($project, $name);
            $out->emit(['deleted' => $name], static function (JsonOutput $out) use ($name, $project): void {
                $out->success(\sprintf('Template "%s" of project "%s" deleted.', $name, $project));
            });
        });
    }
}
