<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Projects\Refused;

/**
 * Turns the in-network port mirror on or off (HostSettings::portMirror()). The setting is saved first; the files are
 * then synced best effort, so an unwritable Traefik directory is a warning and `fleet port:refresh` retries. Off removes
 * both files, :443 included, at this sync; the router itself only changes at the next deploy's converge.
 */
final readonly class PortMirrorSwitch
{
    public function __construct(private HostSettingsRepository $settings, private PortMirror $mirror)
    {
    }

    /**
     * @return string|null a warning when the setting was saved but the files were not synced
     *
     * @throws Refused when the host has not been initialised
     */
    public function set(bool $enabled): ?string
    {
        $host = $this->settings->get() ?? throw new Refused('The host is not initialised; run "fleet host:init" first.');
        if ($host->portMirror() !== $enabled) {
            $host->changePortMirror($enabled);
            $this->settings->save($host);
        }

        return $this->sync();
    }

    /**
     * The best-effort sync after a change; the warning names the retry.
     */
    public function sync(): ?string
    {
        try {
            $this->mirror->sync();
        } catch (\RuntimeException $e) {
            return \sprintf('The setting was saved, but the port mirror files were not updated (%s). Fix the cause and run "fleet port:refresh".', $e->getMessage());
        }

        return null;
    }
}
