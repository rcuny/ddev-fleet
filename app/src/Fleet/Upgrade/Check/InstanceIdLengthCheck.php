<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\FleetPaths;
use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An instance id longer than the limit of FleetPaths::INSTANCE_ID_PATTERN (read from the pattern, so the limit has one
 * home) cannot be handled by code that enforces it: an overlong id left over from a release that allowed it breaks
 * hostnames and tmux sessions after the upgrade. This is the check that would have caught it before the upgrade.
 *
 * Findings: `instances.id-length` (Block, overridable): lists the overlong ids. Remedy: destroy or recreate them with a
 * shorter label, or pass `--force=instances.id-length` when they are to be left alone.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 300])]
final readonly class InstanceIdLengthCheck implements UpgradeCheck
{
    public const string ID_LENGTH = 'instances.id-length';

    public function __construct(private InstanceIdSource $instances)
    {
    }

    /** The longest instance id the pattern accepts. */
    public static function limit(): int
    {
        return 1 === preg_match('/\{\d+,(\d+)\}/', FleetPaths::INSTANCE_ID_PATTERN, $m) ? (int) $m[1] : 59;
    }

    public function check(Plan $plan): array
    {
        $limit = self::limit();
        $long = array_values(array_filter($this->instances->ids(), static fn (string $id): bool => \strlen($id) > $limit));
        if ([] === $long) {
            return [];
        }

        return [CheckResult::block(
            self::ID_LENGTH,
            \sprintf('%d %s longer than %d characters: %s.', \count($long), 1 === \count($long) ? 'instance id is' : 'instance ids are', $limit, implode(', ', \array_slice($long, 0, 5)).(\count($long) > 5 ? ', ...' : '')),
            'Destroy or recreate them with a shorter label, or pass --force='.self::ID_LENGTH.' to leave them as they are.',
            true,
        )];
    }
}
