<?php

declare(strict_types=1);

namespace App\Command\Docs;

use App\Fleet\Docs\CliReference;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes docs/reference/cli.md from the live command definitions (deterministic output). `composer run docs:cli` runs it;
 * CliReferenceTest fails when the committed page is stale.
 */
#[AsCommand(name: 'fleet:docs:cli', description: 'Write docs/reference/cli.md from the console definition (for contributors)')]
final class DocsCliCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the page instead of writing docs/reference/cli.md');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        \assert(null !== $application);
        $page = new CliReference()->render($application);

        if ((bool) $input->getOption('stdout')) {
            $output->write($page);

            return Command::SUCCESS;
        }

        $file = \dirname($this->projectDir).'/docs/reference/cli.md';
        if (false === file_put_contents($file, $page)) {
            $output->writeln('<error>Cannot write '.$file.'</error>');

            return Command::FAILURE;
        }
        $output->writeln('Wrote '.$file);

        return Command::SUCCESS;
    }
}
