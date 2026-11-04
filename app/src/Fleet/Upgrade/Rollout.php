<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * The `#### Rollout` notes of one release: bullets for the operator. A bullet that starts `ACTION:` needs the
 * operator to do something (it raises a warning and a confirmation in the upgrade flow).
 */
final readonly class Rollout
{
    public const string ACTION_PREFIX = 'ACTION:';

    /**
     * @param list<string> $notes the bullet texts, in order, with the leading "- " removed
     */
    public function __construct(public array $notes)
    {
    }

    /**
     * @return list<string> the ACTION notes, without the prefix
     */
    public function actions(): array
    {
        $actions = [];
        foreach ($this->notes as $note) {
            if (str_starts_with($note, self::ACTION_PREFIX)) {
                $actions[] = trim(substr($note, \strlen(self::ACTION_PREFIX)));
            }
        }

        return $actions;
    }

    public function isEmpty(): bool
    {
        return [] === $this->notes;
    }
}
