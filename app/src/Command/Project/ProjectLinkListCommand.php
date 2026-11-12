<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Project\ProjectLink;
use App\Fleet\Projects\ProjectLinkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:link:list', description: 'List the declared links between projects (of one project, outgoing and incoming)')]
final class ProjectLinkListCommand extends Command
{
    public function __construct(private readonly ProjectLinkService $links)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::OPTIONAL, 'Only the links of this project');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['project']));

        return $out->run(function () use ($input, $out): void {
            $project = $input->getArgument('project');
            $links = $this->links->list(\is_string($project) ? $project : null);
            $out->emit(
                array_map(ProjectLinkCommands::toArray(...), $links),
                static fn (JsonOutput $out) => $out->table(
                    ['SOURCE', 'TARGET', 'SERVICE', 'PORT', 'LABEL', 'NOTE'],
                    array_map(static fn (ProjectLink $l): array => [$l->source()->name(), $l->target()->name(), $l->service(), $l->port(), $l->targetLabel(), $l->note()], $links),
                ),
            );
        });
    }
}
