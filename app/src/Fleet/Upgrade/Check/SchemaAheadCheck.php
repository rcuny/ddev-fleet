<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Doctrine migrations only go forward. If the database has migrations the target does not ship (a downgrade, or a
 * target cut before them), the old code would boot over a newer schema and fail; the database has to come back from the
 * pre-upgrade backup (ruling R8, the rollback path).
 *
 * Findings: `schema.ahead` (Warn, not overridable, forces the rollback path): N applied migrations the target lacks.
 * Remedy: the database is restored from the backup of the upgrade being undone; data written since is lost.
 * `schema.unknown` (Info): the target's migrations could not be read, so nothing is claimed.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 200])]
final readonly class SchemaAheadCheck implements UpgradeCheck
{
    public const string AHEAD = 'schema.ahead';
    public const string UNKNOWN = 'schema.unknown';

    public function __construct(private AppliedMigrations $applied, private TargetMigrations $target)
    {
    }

    public function check(Plan $plan): array
    {
        if (null === $plan->target) {
            return [];
        }
        $shipped = $this->target->at($plan->target);
        if (null === $shipped) {
            return [CheckResult::info(self::UNKNOWN, \sprintf('The migrations of %s could not be read here, so the schema was not compared.', $plan->target->tag()))];
        }
        $ahead = array_values(array_diff($this->applied->names(), $shipped));
        if ([] === $ahead) {
            return [];
        }
        sort($ahead);

        return [CheckResult::warn(
            self::AHEAD,
            \sprintf('The database has %d %s %s does not have: %s.', \count($ahead), 1 === \count($ahead) ? 'migration' : 'migrations', $plan->target->tag(), implode(', ', \array_slice($ahead, 0, 5)).(\count($ahead) > 5 ? ', ...' : '')),
            'The rollback path applies: the database is restored from the backup taken before the upgrade being undone, and data written since is lost.',
            false,
        )];
    }
}
