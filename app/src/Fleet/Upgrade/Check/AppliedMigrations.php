<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/** The Doctrine migrations already applied to the host's database. */
interface AppliedMigrations
{
    /**
     * @return list<string> short class names (`Version20261024090000`)
     */
    public function names(): array;
}
