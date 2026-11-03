<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/** How many jobs are queued or running. A seam so ActiveJobsCheck is testable without a database. */
interface ActiveJobCounter
{
    public function count(): int;
}
