<?php

declare(strict_types=1);

namespace App\Fleet\Host\Provision;

/**
 * Reads the hardening answers back from /etc/ddev-fleet/host-vars.yml (world-readable, no secret in it). The file is
 * ours (HostVarsRenderer writes a fixed shape), so a line reader is enough: anything it does not recognise is ignored
 * and a missing or unreadable file reads as "hardening off, shell profile on".
 */
final readonly class HostVarsReader
{
    /**
     * @param string $storedSettingsFile hardening.conf, the installer's record: it seeds the answers on a host whose
     *                                   host-vars.yml does not exist yet (installed by the bash steps)
     */
    public function __construct(private string $file, private string $storedSettingsFile = '')
    {
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function shellProfile(): bool
    {
        return 'false' !== ($this->scalars()['fleet_shell_profile'] ?? 'true');
    }

    public function hardening(): HardeningSettings
    {
        if (!$this->exists()) {
            return $this->fromStoredSettings();
        }
        $scalars = $this->scalars();
        if ('true' !== ($scalars['fleet_hardening_enabled'] ?? 'false')) {
            return HardeningSettings::off();
        }
        $grace = (int) ($scalars['fleet_hardening_firewall_grace_minutes'] ?? HardeningSettings::GRACE_DEFAULT);
        $invoking = $scalars['fleet_hardening_invoking_user'] ?? '';

        return new HardeningSettings(
            true,
            $this->list('fleet_hardening_ssh_allow_users'),
            $this->list('fleet_hardening_trusted_ips'),
            $grace,
            'true' === ($scalars['fleet_hardening_tmp'] ?? 'false'),
            '' === $invoking ? null : $invoking,
        );
    }

    private function fromStoredSettings(): HardeningSettings
    {
        $text = '' !== $this->storedSettingsFile && is_file($this->storedSettingsFile) ? @file_get_contents($this->storedSettingsFile, false, null, 0, 16384) : false;
        if (!\is_string($text) || 1 !== preg_match('/^ENABLED=1$/m', $text)) {
            return HardeningSettings::off();
        }
        $value = static fn (string $key): string => 1 === preg_match('/^'.$key.'=(.*)$/m', $text, $m) ? trim($m[1]) : '';
        $grace = $value('FIREWALL_GRACE_MINUTES');

        return new HardeningSettings(
            true,
            HardeningSettings::splitList($value('SSH_ALLOW_USERS')),
            HardeningSettings::splitList($value('TRUSTED_IPS')),
            '' === $grace ? HardeningSettings::GRACE_DEFAULT : (int) $grace,
            '1' === $value('HARDEN_TMP'),
        );
    }

    /**
     * @return array<string, string> top-level `key: value` pairs, quotes removed
     */
    private function scalars(): array
    {
        $out = [];
        foreach ($this->lines() as $line) {
            if (1 === preg_match('/^([a-z_]+): (.*)$/D', $line, $m)) {
                $out[$m[1]] = trim($m[2], '"');
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        $items = [];
        $inside = false;
        foreach ($this->lines() as $line) {
            if ($inside && 1 === preg_match('/^  - "?([^"]+)"?$/D', $line, $m)) {
                $items[] = $m[1];
                continue;
            }
            $inside = $key.':' === $line;
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $text = is_file($this->file) ? @file_get_contents($this->file, false, null, 0, 65536) : false;

        return \is_string($text) ? preg_split('/\R/', $text) ?: [] : [];
    }
}
