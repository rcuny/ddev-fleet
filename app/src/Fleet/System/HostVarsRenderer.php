<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Renders /etc/ddev-fleet/host-vars.yml, the host's own answers for the Ansible provisioning (install/ansible/site.yml).
 * The installer writes the same file from its flags (install/lib/host-vars.sh); a repo test keeps the two byte-identical.
 * Every value is validated before it is rendered, so none can add a key or a line. Schema: docs/reference/04-installer.md.
 */
final class HostVarsRenderer
{
    /**
     * @param list<string> $sshAllowUsers accounts allowed to log in over SSH (hardening only)
     * @param list<string> $trustedIps    addresses or CIDRs never banned or firewalled (hardening only)
     * @param int          $firewallGrace minutes before the dead-man's switch turns an unconfirmed firewall off, 2 to 120
     */
    public function render(
        string $domain,
        ?string $acmeEmail,
        bool $shellProfile,
        bool $hardening,
        array $sshAllowUsers = [],
        array $trustedIps = [],
        int $firewallGrace = 10,
        bool $hardenTmp = false,
    ): string {
        $out = "---\n"
            ."# Written by the ddev-fleet installer from its flags. A re-run rewrites it; the hardening part follows the stored settings.\n"
            .\sprintf("fleet_domain: \"%s\"\n", ConfigValue::domain($domain))
            .\sprintf("fleet_acme_email: \"%s\"\n", ConfigValue::acmeEmail($acmeEmail) ?? '')
            .'fleet_shell_profile: '.self::bool($shellProfile)."\n";

        if (!$hardening) {
            return $out."fleet_hardening_enabled: false\n";
        }
        if ($firewallGrace < 2 || $firewallGrace > 120) {
            throw new \InvalidArgumentException('The firewall grace period must be 2 to 120 minutes.');
        }

        return $out."fleet_hardening_enabled: true\n"
            .self::list('fleet_hardening_ssh_allow_users', array_map(self::user(...), $sshAllowUsers))
            .self::list('fleet_hardening_trusted_ips', array_map(self::address(...), $trustedIps))
            .'fleet_hardening_firewall_grace_minutes: '.$firewallGrace."\n"
            .'fleet_hardening_tmp: '.self::bool($hardenTmp)."\n";
    }

    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param list<string> $items
     */
    private static function list(string $key, array $items): string
    {
        if ([] === $items) {
            return $key.": []\n";
        }

        return $key.":\n".implode('', array_map(static fn (string $item): string => \sprintf("  - \"%s\"\n", $item), $items));
    }

    private static function user(string $user): string
    {
        if (1 !== preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $user)) {
            throw new \InvalidArgumentException('Not a valid SSH user name.');
        }

        return $user;
    }

    private static function address(string $address): string
    {
        $ip = explode('/', $address, 2);
        $max = str_contains($ip[0], ':') ? 128 : 32;
        if (false === filter_var($ip[0], \FILTER_VALIDATE_IP) || (isset($ip[1]) && (1 !== preg_match('/^\d{1,3}$/D', $ip[1]) || (int) $ip[1] > $max))) {
            throw new \InvalidArgumentException('Not an IP address or CIDR.');
        }

        return $address;
    }
}
