<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/** Is the host healthy on the target version? (a seam: the runner is tested with a fake) */
interface UpgradeVerification
{
    public function verify(string $from, string $targetTag, ?string $targetSha, string $actor): VerifyReport;
}
