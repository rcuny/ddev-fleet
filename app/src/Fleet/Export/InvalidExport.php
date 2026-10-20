<?php

declare(strict_types=1);

namespace App\Fleet\Export;

/**
 * An export file, a passphrase or an import was refused and nothing was written. The problems name a path in the file
 * and a reason; they never contain a value from the file.
 */
final class InvalidExport extends \DomainException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(match (\count($problems)) {
            0 => 'The import was refused.',
            1 => $problems[0],
            default => \sprintf('%d problems: %s', \count($problems), implode(' ', $problems)),
        });
    }

    public static function of(string $problem): self
    {
        return new self([$problem]);
    }
}
