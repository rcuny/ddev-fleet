<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\UpgradeSourceUnavailable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Releases flag operator work as `ACTION:` bullets in their Rollout notes (ruling R5). Each one is a warning the
 * operator must acknowledge with `--yes`; none blocks. Only an upgrade has notes to act on.
 *
 * Findings: `rollout.action` (Warn, not overridable: confirmation is `--yes`, not `--force`), one per ACTION line, the
 * message naming the release. `rollout.unreadable` (Info): the changelog could not be read, so notes were not checked.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 150])]
final readonly class RolloutActionCheck implements UpgradeCheck
{
    public const string ACTION = 'rollout.action';
    public const string UNREADABLE = 'rollout.unreadable';

    public function __construct(private ChangelogReader $changelog)
    {
    }

    public function check(Plan $plan): array
    {
        if (Direction::Up !== $plan->direction || null === $plan->target) {
            return [];
        }
        try {
            $sections = $this->changelog->between($plan->fromVersion, $plan->target->version);
        } catch (UpgradeSourceUnavailable $e) {
            return [CheckResult::info(self::UNREADABLE, 'The rollout notes could not be read: '.$e->getMessage())];
        }

        $results = [];
        foreach ($sections as $section) {
            foreach ($section->rollout->actions() as $action) {
                $results[] = CheckResult::warn(self::ACTION, \sprintf('v%s needs an operator action: %s', $section->version, $action), 'Do it as described, then confirm with --yes.', false);
            }
        }

        return $results;
    }
}
