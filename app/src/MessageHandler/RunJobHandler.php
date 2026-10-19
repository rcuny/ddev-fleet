<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Fleet\Jobs\JobExecutor;
use App\Message\RunJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunJobHandler
{
    public function __construct(private JobExecutor $executor)
    {
    }

    public function __invoke(RunJob $message): void
    {
        $this->executor->run($message->jobId);
    }
}
