<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/** The ids of the instances that exist. A seam so InstanceIdLengthCheck is testable without a database. */
interface InstanceIdSource
{
    /**
     * @return list<string>
     */
    public function ids(): array;
}
