<?php

declare(strict_types=1);

namespace App\Fleet\Export;

/**
 * One line of an import preview: what happens to one thing. The note names fields or gives a reason; it never holds a
 * value from the file.
 */
final readonly class ImportItem
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string UNCHANGED = 'unchanged';
    public const string SKIPPED = 'skipped';

    public const array ACTIONS = [self::CREATE, self::UPDATE, self::UNCHANGED, self::SKIPPED];

    public function __construct(
        public string $kind,
        public string $name,
        public string $action,
        public ?string $note = null,
    ) {
    }

    /**
     * @return array{kind: string, name: string, action: string, note: string|null}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'name' => $this->name, 'action' => $this->action, 'note' => $this->note];
    }
}
