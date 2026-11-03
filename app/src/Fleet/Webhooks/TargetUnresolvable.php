<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * A rule matched but the instance to deploy cannot be named (a branch that slugifies to nothing). The sender gets 422
 * and no delivery id is consumed, so its retry can succeed once the rule or the branch is fixed.
 */
final class TargetUnresolvable extends \RuntimeException
{
}
