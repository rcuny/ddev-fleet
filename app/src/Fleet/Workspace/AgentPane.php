<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * A pane API terminal input may type into, as found by {@see Tmux::agentPane()}: its tmux id, the foreground command shown for
 * it (`claude`, or `ddev>claude` when the agent runs through `ddev exec`, FLE-352) and the effective command the gate verified,
 * or null when it verified none.
 */
final readonly class AgentPane
{
    public function __construct(public string $id, public string $command, public ?string $effective = null)
    {
    }

    public function isAgent(): bool
    {
        return null !== $this->effective && \in_array($this->effective, Tmux::AGENTS, true);
    }
}
