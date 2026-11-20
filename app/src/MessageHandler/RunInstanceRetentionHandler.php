<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Job\JobTrigger;
use App\Fleet\Instances\InstanceRetention;
use App\Message\RunInstanceRetention;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunInstanceRetentionHandler
{
    public function __construct(private InstanceRetention $retention, private LoggerInterface $logger)
    {
    }

    public function __invoke(RunInstanceRetention $message): void
    {
        try {
            $result = $this->retention->run(null, InstanceRetention::ACTOR, JobTrigger::Schedule);
        } catch (\Throwable $e) {
            $this->logger->error('The CI retention pass failed.', ['exception' => $e]);

            return;
        }
        $this->logger->info('CI retention pass: {destroyed} destroyed, {skipped} skipped.', ['destroyed' => \count($result['destroyed']), 'skipped' => \count($result['skipped'])]);
    }
}
