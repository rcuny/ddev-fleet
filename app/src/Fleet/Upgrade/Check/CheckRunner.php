<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every tagged UpgradeCheck in registry order (tag priority, then registration) and gathers the findings. A check
 * that throws is a Block that cannot be waived: a pre-flight that could not run proves nothing.
 */
final readonly class CheckRunner
{
    public const string CRASHED = 'check.crashed';

    /**
     * @param iterable<UpgradeCheck> $checks
     */
    public function __construct(#[AutowireIterator('fleet.upgrade_check')] private iterable $checks)
    {
    }

    /**
     * @param list<string> $force check ids to waive (overridable Blocks only)
     */
    public function run(Plan $plan, array $force = []): CheckReport
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                foreach ($check->check($plan) as $result) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $results[] = CheckResult::block(
                    self::CRASHED,
                    \sprintf('The check %s could not run: %s', new \ReflectionClass($check)->getShortName(), $e->getMessage()),
                    'Fix the cause above and run the check again.',
                );
            }
        }

        $forced = $refused = $unmatched = [];
        foreach (array_values(array_unique($force)) as $id) {
            $matches = array_values(array_filter($results, static fn (CheckResult $r): bool => $r->id === $id));
            if ([] === $matches) {
                $unmatched[] = $id;
                continue;
            }
            $blocks = array_filter($matches, static fn (CheckResult $r): bool => Severity::Block === $r->severity);
            if ([] !== array_filter($blocks, static fn (CheckResult $r): bool => !$r->overridable)) {
                $refused[] = $id;
            } else {
                $forced[] = $id;
            }
        }

        return new CheckReport($results, $forced, $refused, $unmatched);
    }
}
