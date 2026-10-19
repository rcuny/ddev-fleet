<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Who may open the instance sites of one project (`<project>--<label>.<domain>`, and the aliases
 * `<hostname>-<project>--<label>.<domain>` of its additional hostnames): the administrators' group and the members'
 * usernames. Plain data; AutheliaConfigRenderer validates every value before it renders it.
 */
final readonly class AccessRule
{
    /**
     * @param list<string> $users     usernames of the project's members
     * @param list<string> $hostnames the project's additional hostname labels (the alias prefixes)
     */
    public function __construct(
        public string $project,
        public string $adminGroup,
        public array $users = [],
        public array $hostnames = [],
    ) {
    }
}
