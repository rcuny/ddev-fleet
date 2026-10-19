<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Host\GitIdentityRule;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Secrets\SecretStore;

/**
 * Read-only: is the push key there, is the git identity set (and valid), is the Claude token stored. It never opens a
 * secret and never reads a key file's contents.
 */
final readonly class WorkspaceCheck
{
    public function __construct(
        private FleetPaths $paths,
        private HostSettingsRepository $settings,
        private SecretStore $secrets,
    ) {
    }

    public function run(): WorkspaceStatus
    {
        $host = $this->settings->get();
        $name = trim($host?->gitUserName() ?? '');
        $email = trim($host?->gitUserEmail() ?? '');
        $identity = '' !== $name && '' !== $email && [] === GitIdentityRule::violations($name, $email);

        return new WorkspaceStatus(
            is_file($this->paths->sshPrivateKey(SshKeyRole::Push)) && is_file($this->paths->sshPublicKey(SshKeyRole::Push)),
            $identity,
            $this->secrets->hasFleetWide(HostSecrets::CLAUDE_TOKEN),
        );
    }
}
