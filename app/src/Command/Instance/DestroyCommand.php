<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedCommand;
use App\Command\Support\AttachedJobs;
use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\InstanceOptions;
use App\Command\Support\JsonOutput;
use App\Domain\Instance\Instance;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:destroy', description: 'Destroy instances: their containers, database and checkout (a protected instance is refused)')]
final class DestroyCommand extends AttachedCommand
{
    public function __construct(AttachedJobs $attached, private readonly InstanceService $instances)
    {
        parent::__construct($attached);
    }

    protected function configure(): void
    {
        InstanceOptions::addSelection($this);
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirm a selector or several ids');
        self::addDetach($this);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());

        return self::guarded($out, function () use ($input, $output, $out): int {
            $selector = InstanceOptions::selector($input);
            $resolved = $this->instances->resolve($selector);

            // FLE-363: a protected instance is refused and reported by name; the others still go ahead. There is no CLI unlock.
            $locked = array_values(array_filter($resolved, static fn (Instance $i): bool => $i->isProtected()));
            $allowed = array_values(array_filter($resolved, static fn (Instance $i): bool => !$i->isProtected()));
            $refusedRows = array_map(static fn (Instance $i): array => ['id' => $i->id(), 'reason' => 'protected'], $locked);
            if ([] !== $locked) {
                $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                foreach ($locked as $instance) {
                    if (!$out->isJson()) {
                        $stderr->writeln(\sprintf('refused: %s is protected; remove the protection on its page first.', $instance->id()), OutputInterface::OUTPUT_RAW);
                    }
                }
                if ([] === $allowed) {
                    if ($out->isJson()) {
                        try {
                            InstanceService::refuseIfProtected($locked);
                        } catch (Refused $refused) {
                            $out->document(['error' => $refused->getMessage(), 'fields' => (object) [], 'refused' => $refusedRows]);

                            return JsonOutput::REFUSED;
                        }
                    }
                    InstanceService::refuseIfProtected($locked);
                }
            }

            InstanceOptions::confirm($input, $output, $out, $selector, \count($allowed));
            $ids = array_map(static fn (Instance $i): string => $i->id(), $allowed);
            $jobs = $this->instances->request('destroy', new Selector(ids: $ids), new OperationOptions(), JobTrigger::Cli, CliRequester::name());
            $code = $this->attached->handle($jobs, true === $input->getOption('detach'), $out, $output, null, [] === $refusedRows ? [] : ['refused' => $refusedRows]);

            return [] !== $locked && JsonOutput::OK === $code ? JsonOutput::REFUSED : $code;
        });
    }
}
