<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\JsonOutput;
use App\Fleet\Projects\ProjectLinkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:link:delete', description: 'Remove a declared link (its network is removed by the next isolation pass)')]
final class ProjectLinkDeleteCommand extends Command
{
    public function __construct(private readonly ProjectLinkService $links)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        ProjectLinkCommands::addTarget($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, ProjectLinkCommands::fields());

        return $out->run(function () use ($input, $out): void {
            $this->links->delete(
                (string) $input->getArgument('source'),
                (string) $input->getArgument('target'),
                (string) $input->getOption('service'),
                ProjectLinkCommands::port($input),
                (string) $input->getOption('label'),
                ProjectLinkCommands::actor(),
            );
            $out->emit(['deleted' => true], static function (JsonOutput $out): void {
                $out->success('Link deleted.');
            });
        });
    }
}
