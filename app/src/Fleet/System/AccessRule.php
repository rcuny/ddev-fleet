<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Who may open the instance sites of one project (`<project>--<label>.<domain>`): the administrators' group and the
 * members' usernames. Plain data; AutheliaConfigRenderer validates every value before it renders it.
 */
final readonly class AccessRule
{
    /**
     * @param list<string> $users usernames of the project's members
     */
    public function __construct(
        public string $project,
        public string $adminGroup,
        public array $users = [],
    ) {
    }
}
