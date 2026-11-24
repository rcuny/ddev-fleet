<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Command\Support\AttachedJobs;
use App\Command\Support\CliRequester;
use App\Command\Support\FieldErrors;
use App\Command\Support\InstanceOptions;
use App\Command\Support\JsonOutput;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\NoInstanceMatches;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The body start and stop share. `--all` skips the instances that are being deployed or destroyed, and the failed ones (which it names and counts, FLE-375).
 */
final class StartStop
{
    public static function run(InputInterface $input, OutputInterface $output, string $op, InstanceService $instances, AttachedJobs $attached): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());
        $code = JsonOutput::OK;
        $status = $out->run(static function () use ($input, $output, $out, $op, $instances, $attached, &$code): void {
            $selector = InstanceOptions::selector($input);
            $skipped = [];
            if ($selector->all) {
                try {
                    $existing = $instances->resolve($selector);
                } catch (NoInstanceMatches) {
                    // A host with no instance at all (fresh install, FLE-195) is not an error for `--all`: the boot unit
                    // must not end up failed. Only a pure `--all` reaches here; ids, project and state keep failing.
                    $existing = [];
                }
                $ids = array_map(
                    static fn (Instance $i): string => $i->id(),
                    array_filter(
                        $existing,
                        static fn (Instance $i): bool => !\in_array($i->state(), [InstanceState::Deploying, InstanceState::Destroying, InstanceState::Failed], true),
                    ),
                );
                // FLE-375: a failed instance is never started by `--all` (a person decides), but it must not go unnoticed:
                // fleet-boot runs this, so the journal names each one and the summary counts them.
                $skipped = array_values(array_map(
                    static fn (Instance $i): string => $i->id(),
                    array_filter($existing, static fn (Instance $i): bool => InstanceState::Failed === $i->state()),
                ));
                if (!$out->isJson()) {
                    foreach ($skipped as $id) {
                        $output->writeln(\sprintf('skipped %s: state failed — run fleet instance:%s %s', $id, $op, $id), OutputInterface::OUTPUT_RAW);
                    }
                }
                if ([] === $ids) {
                    $out->emit(['jobs' => []] + ([] === $skipped ? [] : ['skipped' => $skipped]), static function (JsonOutput $out) use ($op, $skipped): void {
                        $out->success('nothing to '.$op.([] === $skipped ? '' : \sprintf(', %d skipped (failed)', \count($skipped))));
                    });

                    return;
                }
                $selector = new Selector(array_values($ids));
            }

            $options = new OperationOptions(
                InstanceOptions::seconds($input),
                $input->hasOption('retry-port-conflict') && true === $input->getOption('retry-port-conflict'),
                true === $input->getOption('sequential'),
            );
            $jobs = $instances->request($op, $selector, $options, JobTrigger::Cli, CliRequester::name());
            $code = $attached->handle($jobs, true === $input->getOption('detach'), $out, $output, null, [] === $skipped ? [] : ['skipped' => $skipped], \count($skipped));
        });

        return JsonOutput::OK !== $status ? $status : $code;
    }
}
