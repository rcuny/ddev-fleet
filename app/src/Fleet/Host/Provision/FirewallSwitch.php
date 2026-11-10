<?php

declare(strict_types=1);

namespace App\Fleet\Host\Provision;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;

/**
 * Reads the firewall dead-man's switch; never changes it. Confirming is the one thing this app must not do: it has to
 * come from a second, independent SSH session (`sudo fleet-firewall-confirm`), which proves the host is still reachable.
 * Read-only: the settings file and the marker are root:fleet-readable, the timer state comes from `systemctl is-active`.
 */
final readonly class FirewallSwitch
{
    public const string TIMER = 'fleet-firewall-deadman.timer';
    public const string COMMAND = 'sudo fleet-firewall-confirm';

    public function __construct(
        private CommandRunner $runner,
        private string $settingsFile,
        private string $markerFile,
    ) {
    }

    public function state(): FirewallState
    {
        if (!$this->hardeningStored()) {
            return FirewallState::Off;
        }
        if (is_file($this->markerFile)) {
            return FirewallState::Confirmed;
        }

        return $this->timerActive() ? FirewallState::Armed : FirewallState::Expired;
    }

    /**
     * When the timer fires, as systemd prints it (a plain date), only while the switch is armed.
     */
    public function firesAt(): ?string
    {
        if (FirewallState::Armed !== $this->state()) {
            return null;
        }
        try {
            $result = $this->runner->run(['systemctl', 'show', self::TIMER, '--property=NextElapseUSecRealtime', '--value'], timeout: 5.0);
        } catch (CommandRunnerException) {
            return null;
        }
        $text = trim($result->stdout);

        return $result->isSuccess() && 1 === preg_match('/^[A-Za-z0-9 :+-]{1,64}$/D', $text) ? $text : null;
    }

    private function hardeningStored(): bool
    {
        $text = is_file($this->settingsFile) ? @file_get_contents($this->settingsFile, false, null, 0, 16384) : false;

        return \is_string($text) && 1 === preg_match('/^ENABLED=1$/m', $text);
    }

    private function timerActive(): bool
    {
        try {
            return $this->runner->run(['systemctl', 'is-active', '--quiet', self::TIMER], timeout: 5.0)->isSuccess();
        } catch (CommandRunnerException) {
            return false;
        }
    }
}
