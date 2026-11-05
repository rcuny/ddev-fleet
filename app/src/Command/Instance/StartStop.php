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
 * The body start and stop share. `--all` skips the instances that are being deployed or destroyed, and the failed ones.
 */
final class StartStop
{
    public static function run(InputInterface $input, OutputInterface $output, string $op, InstanceService $instances, AttachedJobs $attached): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::instance());
        $code = JsonOutput::OK;
        $status = $out->run(static function () use ($input, $output, $out, $op, $instances, $attached, &$code): void {
            $selector = InstanceOptions::selector($input);
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
                if ([] === $ids) {
                    $out->emit(['jobs' => []], static function (JsonOutput $out) use ($op): void {
                        $out->success('nothing to '.$op);
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
            $code = $attached->handle($jobs, true === $input->getOption('detach'), $out, $output);
        });

        return JsonOutput::OK !== $status ? $status : $code;
    }
}
