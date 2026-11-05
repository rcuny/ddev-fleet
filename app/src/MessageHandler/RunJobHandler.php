<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Fleet\Jobs\ConcurrentJobRunner;
use App\Message\RunJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunJobHandler
{
    public function __construct(private ConcurrentJobRunner $runner)
    {
    }

    public function __invoke(RunJob $message): void
    {
        $this->runner->handle($message->jobId, $message->eligibleSince);
    }
}
