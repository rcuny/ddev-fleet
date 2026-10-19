<?php

declare(strict_types=1);

namespace App\Domain\Instance;

enum InstanceState: string
{
    case Deploying = 'deploying';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Destroying = 'destroying';
    case Unknown = 'unknown';
}
