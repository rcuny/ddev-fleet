<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * What a form or a command line submits for a named port. The numbers arrive as strings from both; normalised()
 * turns the ones that are plain digits into integers and leaves anything else for the rule to refuse.
 */
final readonly class NamedPortInput
{
    public function __construct(
        public string $name,
        public int|string $public,
        public int|string $router,
    ) {
    }

    public function normalised(): self
    {
        return new self(trim($this->name), self::number($this->public), self::number($this->router));
    }

    private static function number(int|string $value): int|string
    {
        if (\is_int($value)) {
            return $value;
        }
        $value = trim($value);

        return 1 === preg_match('/^\d{1,5}$/D', $value) ? (int) $value : $value;
    }
}
