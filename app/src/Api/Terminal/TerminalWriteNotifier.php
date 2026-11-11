<?php

declare(strict_types=1);

namespace App\Api\Terminal;

use App\Domain\ApiCall\ApiCall;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the owner, as it happens, that terminal input was sent over the API, or may have been (FLE-289): `outcome` is `sent`, or
 * `failed` when the send threw after the keys may already have been typed. A private Mercure update on
 * ALERT_TOPIC: only the ids and sizes, never the text (that stays in `api_call`, behind the audit view). The evidence and the audit
 * entry are the record; this is the wake-up, so a hub that is down is logged and ignored and never blocks or fails the call.
 */
final readonly class TerminalWriteNotifier
{
    public const string ALERT_TOPIC = '/alerts/terminal-write';

    public function __construct(private HubInterface $hub, private LoggerInterface $logger)
    {
    }

    public function written(ApiCall $call): void
    {
        try {
            $payload = [
                'call' => $call->id(),
                'instance' => $call->instance(),
                'pane' => $call->pane(),
                'actor' => $call->actor(),
                'length' => $call->length(),
                'outcome' => $call->outcome(),
                'at' => $call->createdAt()->format(\DATE_ATOM),
            ];
            $this->hub->publish(new Update(self::ALERT_TOPIC, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('The terminal write alert could not be published.', ['exception' => $e::class]);
        }
    }
}
