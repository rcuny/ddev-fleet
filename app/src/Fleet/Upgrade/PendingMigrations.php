<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

interface PendingMigrations
{
    /** @throws \Throwable when the status cannot be read */
    public function count(): int;
}
