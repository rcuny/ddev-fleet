<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

/**
 * What a CheckRunner found, in the registry's order. `--force=<id>` ids waive an overridable Block; `refused` lists the
 * forced ids that matched a Block that cannot be waived, `unmatched` those that matched nothing.
 */
final readonly class CheckReport
{
    /**
     * @param list<CheckResult> $results
     * @param list<string>      $forced    the ids actually waived
     * @param list<string>      $refused   forced ids naming a non-overridable Block
     * @param list<string>      $unmatched forced ids that no finding carries
     */
    public function __construct(
        public array $results,
        public array $forced = [],
        public array $refused = [],
        public array $unmatched = [],
    ) {
    }

    /** @return list<CheckResult> the Blocks that still stop the upgrade (not waived) */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (CheckResult $r): bool => Severity::Block === $r->severity && !\in_array($r->id, $this->forced, true),
        ));
    }

    /** @return list<CheckResult> */
    public function withSeverity(Severity $severity): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $r): bool => $r->severity === $severity));
    }

    public function isBlocked(): bool
    {
        return [] !== $this->blocking();
    }

    public function find(string $id): ?CheckResult
    {
        foreach ($this->results as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        return null;
    }
}
