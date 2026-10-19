<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:template:list', description: 'List the templates of one project, or of all projects')]
final class TemplateListCommand extends Command
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly TemplateService $templates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::OPTIONAL, 'Project name (all projects when left out)');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::template());

        return $out->run(function () use ($input, $out): void {
            $only = $input->getArgument('project');
            $names = null === $only ? array_map(static fn (Project $project): string => $project->name(), $this->projects->list()) : [(string) $only];
            $templates = [];
            foreach ($names as $name) {
                array_push($templates, ...$this->templates->list($name));
            }
            $out->emit(
                array_map(static fn (Template $template): array => Shapes::template($template), $templates),
                static fn (JsonOutput $out) => $out->table(
                    ['PROJECT', 'NAME', 'DRUPAL ENV', 'POST-DEPLOY', 'TTY1', 'TTY2'],
                    array_map(static fn (Template $template): array => [
                        $template->project()->name(),
                        $template->name(),
                        $template->drupalEnv() ?? '-',
                        \count($template->postDeploy()),
                        \count($template->tty1()),
                        \count($template->tty2()),
                    ], $templates),
                ),
            );
        });
    }
}
