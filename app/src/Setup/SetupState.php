<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\HostSettingsRepository;

/**
 * Where the host is in setup. Every setup route asks this first: once setup is complete the answer is closed, and
 * nothing but fleet:setup:reset (host shell only) changes that.
 */
final readonly class SetupState
{
    public function __construct(
        private HostSettingsRepository $settings,
        private SetupCookie $cookie,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->settings->get()?->isSetupComplete() ?? false;
    }

    /**
     * The host is initialised and setup is not complete: the wizard may be used by whoever holds the token.
     */
    public function isOpen(): bool
    {
        $host = $this->settings->get();

        return null !== $host && !$host->isSetupComplete();
    }

    /**
     * May this request use the wizard's steps (everything except the token exchange itself)? True only while setup
     * is open and the cookie was issued from the current token.
     */
    public function permits(#[\SensitiveParameter] ?string $cookieValue): bool
    {
        $host = $this->settings->get();

        return null !== $host && !$host->isSetupComplete() && $this->cookie->isValid($cookieValue, $host);
    }
}
