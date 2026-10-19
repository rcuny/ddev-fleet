<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:template:update', description: 'Change a template (only the options you give)')]
final class TemplateUpdateCommand extends Command
{
    public function __construct(private readonly TemplateService $templates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        $this->addArgument('name', InputArgument::REQUIRED, 'Template name');
        TemplateOptions::configure($this, true);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::template());

        return $out->run(function () use ($input, $out): void {
            $project = (string) $input->getArgument('project');
            $name = (string) $input->getArgument('name');
            $current = TemplateInput::fromTemplate($this->templates->get($project, $name));
            $template = $this->templates->update($project, $name, $current->with(TemplateOptions::changes($input, true)));
            $out->emit(Shapes::template($template), static function (JsonOutput $out) use ($template, $project): void {
                $out->success(\sprintf('Template "%s" of project "%s" updated.', $template->name(), $project));
            });
        });
    }
}
