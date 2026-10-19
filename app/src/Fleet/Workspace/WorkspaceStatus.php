<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * What the agent workspace needs from the host, as booleans: never a key or a token value.
 */
final readonly class WorkspaceStatus
{
    public function __construct(
        public bool $pushKey,
        public bool $gitIdentity,
        public bool $claudeToken,
    ) {
    }

    public function ok(): bool
    {
        return $this->pushKey && $this->gitIdentity && $this->claudeToken;
    }

    /**
     * @return array{push_key: bool, git_identity: bool, claude_token: bool}
     */
    public function toArray(): array
    {
        return ['push_key' => $this->pushKey, 'git_identity' => $this->gitIdentity, 'claude_token' => $this->claudeToken];
    }
}
