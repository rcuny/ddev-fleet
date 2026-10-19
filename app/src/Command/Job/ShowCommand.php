<?php

declare(strict_types=1);

namespace App\Command\Job;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Job\JobRepository;
use App\Fleet\Projects\NotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:job:show', description: 'Show one job')]
final class ShowCommand extends Command
{
    public function __construct(private readonly JobRepository $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Job number');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, new FieldErrors(['id']));

        return $out->run(function () use ($input, $out): void {
            $number = (string) $input->getArgument('id');
            $job = 1 === preg_match('/^[1-9][0-9]{0,9}$/D', $number) ? $this->jobs->find((int) $number) : null;
            if (null === $job) {
                throw NotFound::job((int) $number);
            }
            $row = ListCommand::row($job);
            $out->emit(['job' => $row], static function (JsonOutput $out) use ($row): void {
                $out->pairs($row);
            });
        });
    }
}
