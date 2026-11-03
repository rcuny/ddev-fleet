<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

/**
 * Who sends the event. The value is the path segment of the endpoint (`/hooks/<value>/<project>`).
 */
enum WebhookSource: string
{
    case Jira = 'jira';
    case Bitbucket = 'bitbucket';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Bitbucket => 'Bitbucket',
        };
    }
}
