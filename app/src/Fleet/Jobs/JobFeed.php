<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells browsers that a job or an instance changed. Mercure is a wake-up, never the log: the payload carries ids and
 * sizes, the text comes from the log endpoint. A hub that is down is logged and ignored; it never fails a job.
 */
final readonly class JobFeed
{
    public function __construct(private HubInterface $hub, private LoggerInterface $logger)
    {
    }

    public function jobChanged(Job $job, int $size): void
    {
        $this->publish(
            '/jobs/'.$job->id(),
            ['id' => $job->id(), 'status' => $job->status()->value, 'size' => $size],
        );
    }

    public function instanceChanged(string $id): void
    {
        $this->publish('/instances', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed.', ['topic' => $topic, 'exception' => $e]);
        }
    }
}
