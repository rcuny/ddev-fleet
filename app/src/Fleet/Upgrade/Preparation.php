<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * The outcome of the plan phase of `sudo fleet upgrade`: whether the upgrade may start, and why not. Nothing has changed
 * on the host when this exists. Exit codes: 0 (ready or nothing to do), 1 failed, 2 usage, 3 refused.
 */
final readonly class Preparation
{
    public const int EXIT_FAILED = 1;
    public const int EXIT_USAGE = 2;
    public const int EXIT_REFUSED = 3;

    /**
     * @param list<string> $reasons why it was refused or failed (one line each)
     * @param list<string> $actions the `ACTION:` rollout notes the operator must have read
     */
    public function __construct(
        public RunState $state,
        public string $from = '',
        public ?Release $target = null,
        public array $reasons = [],
        public array $actions = [],
        public ?UpgradeAssessment $assessment = null,
    ) {
    }

    public function exitCode(): int
    {
        return match ($this->state) {
            RunState::Ready, RunState::UpToDate => 0,
            RunState::Failed => self::EXIT_FAILED,
            RunState::Usage => self::EXIT_USAGE,
            RunState::Refused => self::EXIT_REFUSED,
        };
    }
}
