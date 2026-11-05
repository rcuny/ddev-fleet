<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Projects\ValidationFailed;

/**
 * A selector (all instances, a project and/or a state) matched no instance. `start --all` and `stop --all` treat it as
 * "nothing to do" on an empty host (FLE-195); everything else keeps it as a validation failure on `selector`.
 */
final class NoInstanceMatches extends ValidationFailed
{
    public const MESSAGE = 'No instance matches.';

    public function __construct()
    {
        parent::__construct(['selector' => self::MESSAGE]);
    }
}
