<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Secrets\SecretStore;

/**
 * What the wizard has collected so far, read from the database each time (every step persists). Nothing here
 * carries a secret value: only whether one is stored.
 */
final readonly class SetupProgress
{
    public const string CLAUDE_SECRET = 'claude_oauth_token';
    public const array OVH_SECRETS = ['ovh_application_key', 'ovh_application_secret', 'ovh_consumer_key'];

    public function __construct(
        private HostSettingsRepository $settings,
        private UserRepository $users,
        private SshKeyRepository $keys,
        private SecretStore $secrets,
    ) {
    }

    /**
     * The administrator the Admin step edits: the first enabled administrator. The wizard never creates a second one
     * (renaming would leave the first in users.yml, see ruling D18), so the username is fixed once it exists.
     */
    public function admin(): ?User
    {
        foreach ($this->users->all() as $user) {
            if ($user->isAdmin() && $user->isEnabled()) {
                return $user;
            }
        }

        return null;
    }

    public function hasKeys(): bool
    {
        foreach (SshKeyRole::cases() as $role) {
            if (null === $this->keys->findByRole($role)) {
                return false;
            }
        }

        return true;
    }

    public function claudeTokenStored(): bool
    {
        return $this->secrets->hasFleetWide(self::CLAUDE_SECRET);
    }

    public function ovhComplete(): bool
    {
        if (!$this->secrets->hasFleetWide('ovh_endpoint')) {
            return false;
        }
        foreach (self::OVH_SECRETS as $name) {
            if (!$this->secrets->hasFleetWide($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first step that blocks Finish: the page a person with a valid session lands on. A fresh wizard starts at
     * the administrator; when everything required is in place it is the summary, from which every step is one link
     * away.
     */
    public function firstIncomplete(): SetupStep
    {
        if (null === $this->admin()) {
            return SetupStep::Admin;
        }
        $host = $this->settings->get();
        if (null !== $host && TlsMode::OvhDns === $host->tlsMode() && !$this->ovhComplete()) {
            return SetupStep::Tls;
        }
        if (!$this->hasKeys()) {
            return SetupStep::Ssh;
        }

        return SetupStep::Finish;
    }
}
