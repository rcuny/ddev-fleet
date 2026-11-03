<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Plan;

/**
 * A pre-flight check of an upgrade. It looks at the host and the Plan, changes nothing, and returns 0..n findings.
 *
 * Add a check by implementing this interface and tagging the class with its position:
 * `#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 100])]` (higher runs first; same pattern as
 * `fleet.deploy_step`, and for the same reason the tag is on the class, not on this interface: an interface tag has no
 * priority). UpgradeCheckRegistrationTest fails for a check that is not tagged. Every finding documents its id (a
 * class constant), severity, remedy and whether `--force=<id>` may waive it.
 *
 * Order: HostRootCheck (900), NetworkCheck (800), CheckoutCleanCheck (700), TargetTagCheck (600), DiskCheck (500),
 * ActiveJobsCheck (400), InstanceIdLengthCheck (300), SchemaAheadCheck (200), RolloutActionCheck (150),
 * InstallerPreflightCheck (100).
 */
interface UpgradeCheck
{
    /**
     * @return list<CheckResult>
     */
    public function check(Plan $plan): array;
}
