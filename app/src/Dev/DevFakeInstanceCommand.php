<?php

declare(strict_types=1);

namespace App\Dev;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\ProjectRepository;
use App\Fleet\FleetPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development/test only: records a running instance (a row and an empty checkout directory) so the browser specs can
 * exercise what needs one before the deploy pages exist. Not registered in prod.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'fleet:dev:fake-instance', description: 'Dev/test only: record a running instance of a project')]
final class DevFakeInstanceCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly InstanceRepository $instances,
        private readonly FleetPaths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::REQUIRED, 'Project name')
            ->addArgument('label', InputArgument::OPTIONAL, 'Instance label', 'main')
            ->addArgument('state', InputArgument::OPTIONAL, 'Instance state', InstanceState::Running->value)
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the instance row and its checkout instead (so the project can be deleted)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->projects->findByName((string) $input->getArgument('project'));
        $state = InstanceState::tryFrom((string) $input->getArgument('state'));
        if (null === $project || null === $state) {
            $output->writeln('Unknown project or state.');

            return Command::FAILURE;
        }

        if ($input->getOption('remove')) {
            $existing = $this->instances->find(\sprintf('%s--%s', $project->name(), (string) $input->getArgument('label')));
            if (null !== $existing) {
                $this->instances->remove($existing);
            }
            @rmdir($this->paths->instanceDir(\sprintf('%s--%s', $project->name(), (string) $input->getArgument('label'))));

            return Command::SUCCESS;
        }

        $instance = new Instance($project, (string) $input->getArgument('label'), 'full', 'develop', 'dev');
        $instance->changeState($state);
        $this->instances->createMany([$instance]);
        $dir = $this->paths->instanceDir($instance->id());
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true)) {
            $output->writeln('The checkout directory could not be created.');

            return Command::FAILURE;
        }
        $output->writeln($instance->id());

        return Command::SUCCESS;
    }
}
