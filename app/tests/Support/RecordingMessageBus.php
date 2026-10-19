<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * A bus that only remembers what was dispatched (message, stamps).
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $sent = [];
    public ?\Throwable $failWith = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }

        return $this->sent[] = Envelope::wrap($message, $stamps);
    }

    /**
     * @return list<int> the delay in milliseconds of each dispatched message (0 when none)
     */
    public function delays(): array
    {
        return array_map(
            static fn (Envelope $e): int => $e->last(DelayStamp::class)?->getDelay() ?? 0,
            $this->sent,
        );
    }
}
