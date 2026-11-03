<?php

declare(strict_types=1);

namespace App\Command\Support;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command that can run its jobs in this process: --detach only queues them, and SIGINT ends the running job as
 * interrupted (exit 130).
 */
abstract class AttachedCommand extends Command implements SignalableCommandInterface
{
    public function __construct(protected readonly AttachedJobs $attached)
    {
        parent::__construct();
    }

    protected static function addDetach(Command $command): void
    {
        $command->addOption('detach', null, InputOption::VALUE_NONE, 'Only queue the job(s) and return');
    }

    /**
     * Runs an action that returns its own exit code, with the service exceptions turned into theirs.
     *
     * @param \Closure(): int $action
     */
    protected static function guarded(JsonOutput $out, \Closure $action): int
    {
        $code = JsonOutput::OK;
        $status = $out->run(static function () use ($action, &$code): void {
            $code = $action();
        });

        return JsonOutput::OK !== $status ? $status : $code;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->attached->interrupt();

        return 130;
    }
}
