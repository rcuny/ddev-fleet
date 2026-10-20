<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\PortMirror\LiveEntrypoints;

/**
 * The router's live entrypoints as a test scripts them: a queue of answers (each null for "API unreachable"); the last
 * answer repeats.
 */
final class FakeLiveEntrypoints implements LiveEntrypoints
{
    public int $reads = 0;

    /** @var list<list<string>|null> */
    private array $answers;

    /**
     * @param list<string>|null ...$answers
     */
    public function __construct(?array ...$answers)
    {
        $this->answers = [] === $answers ? [null] : array_values($answers);
    }

    /**
     * @param list<string>|null ...$answers
     */
    public function script(?array ...$answers): void
    {
        $this->answers = array_values($answers);
    }

    public function names(): ?array
    {
        ++$this->reads;
        if (\count($this->answers) > 1) {
            return array_shift($this->answers);
        }

        return $this->answers[0] ?? null;
    }
}
