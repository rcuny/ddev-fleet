<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum JobTrigger: string
{
    case Ui = 'ui';
    case Cli = 'cli';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
}
