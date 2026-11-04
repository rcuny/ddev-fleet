<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * One pane of a window as tmux reports it: its id (`%12`), where it sits, and the `@fleet_role` tag it carries (`sidebar`,
 * or empty for a shell).
 */
final readonly class PaneInfo
{
    public const string ROLE_SIDEBAR = 'sidebar';

    public function __construct(
        public string $id,
        public int $left,
        public int $top,
        public int $width,
        public int $windowWidth,
        public string $role,
        public string $command,
    ) {
    }

    public function isSidebar(): bool
    {
        return self::ROLE_SIDEBAR === $this->role;
    }
}
