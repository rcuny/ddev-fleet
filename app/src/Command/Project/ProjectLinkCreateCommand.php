<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\JsonOutput;
use App\Fleet\Projects\ProjectLinkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:link:create', description: 'Allow the web containers of one project to reach a service port of another (one way, applied by host isolation)')]
final class ProjectLinkCreateCommand extends Command
{
    public function __construct(private readonly ProjectLinkService $links)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        ProjectLinkCommands::addTarget($this);
        $this->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why this link exists (shown on both projects)', '');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, ProjectLinkCommands::fields());

        return $out->run(function () use ($input, $out): void {
            $link = $this->links->create(
                (string) $input->getArgument('source'),
                (string) $input->getArgument('target'),
                (string) $input->getOption('service'),
                ProjectLinkCommands::port($input),
                (string) $input->getOption('label'),
                (string) $input->getOption('note'),
                ProjectLinkCommands::actor(),
            );
            $out->emit(ProjectLinkCommands::toArray($link), static function (JsonOutput $out) use ($link): void {
                $out->success(\sprintf('Link created: %s.', ProjectLinkService::describe($link)));
            });
        });
    }
}
