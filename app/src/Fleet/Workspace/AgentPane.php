<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * A pane API terminal input may type into, as found by {@see Tmux::agentPane()}: its tmux id and the foreground command at that moment.
 */
final readonly class AgentPane
{
    public function __construct(public string $id, public string $command)
    {
    }

    public function isAgent(): bool
    {
        return \in_array($this->command, Tmux::AGENTS, true);
    }
}
