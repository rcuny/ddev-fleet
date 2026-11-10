<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

/**
 * What an API token may do. There is no wildcard scope and no scope implies another one: a token holds exactly the
 * scopes it was issued with. `lifecycle:destroy` is a flag on top of `lifecycle`, not part of it.
 */
enum ApiScope: string
{
    case Read = 'read';
    case Deploy = 'deploy';
    case Lifecycle = 'lifecycle';
    case LifecycleDestroy = 'lifecycle:destroy';
    case TerminalRead = 'terminal:read';
    case TerminalWrite = 'terminal:write';
}
