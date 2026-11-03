<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Domain\Webhook\WebhookSource;
use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The --source option the webhook commands share.
 */
final class WebhookSourceOption
{
    public static function add(Command $command): void
    {
        $command->addOption('source', null, InputOption::VALUE_REQUIRED, 'Who sends the events: jira or bitbucket', WebhookSource::Jira->value);
    }

    /**
     * @throws ValidationFailed
     */
    public static function read(InputInterface $input): WebhookSource
    {
        return WebhookSource::tryFrom((string) $input->getOption('source'))
            ?? throw new ValidationFailed(['source' => 'Use jira or bitbucket.']);
    }
}
