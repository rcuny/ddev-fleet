<?php

declare(strict_types=1);

namespace App\Fleet\Export;

/**
 * What importing a file would do on this host: one item per thing, and the validation errors. A plan with errors is
 * never applied.
 */
final readonly class ImportPlan
{
    /**
     * @param list<ImportItem> $items
     * @param list<string>     $errors   "path: reason", never a value from the file
     * @param list<string>     $warnings
     */
    public function __construct(
        public string $project,
        public array $items,
        public array $errors,
        public array $warnings = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /**
     * @return array<string, int> every action, with its count
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ImportItem::ACTIONS, 0);
        foreach ($this->items as $item) {
            ++$counts[$item->action];
        }

        return $counts;
    }

    public function changes(): bool
    {
        $counts = $this->counts();

        return $counts[ImportItem::CREATE] + $counts[ImportItem::UPDATE] > 0;
    }

    /**
     * @return array{project: string, valid: bool, counts: array<string, int>, items: list<array<string, string|null>>, errors: list<string>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'valid' => !$this->hasErrors(),
            'counts' => $this->counts(),
            'items' => array_map(static fn (ImportItem $item): array => $item->toArray(), $this->items),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
