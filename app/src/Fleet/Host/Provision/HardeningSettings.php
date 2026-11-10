<?php

declare(strict_types=1);

namespace App\Fleet\Host\Provision;

/**
 * The hardening answers of the host (host-vars.yml): what the Hardening tab edits and the provision job renders. The
 * rules are the installer's (install/lib/common.sh): SSH account names, addresses or CIDRs, a grace period of 2 to 120
 * minutes. When hardening is on the allow list is never empty and must contain the account the administrator logs in
 * with, so that applying it cannot lock that account out (the playbook checks the same, strictly).
 */
final readonly class HardeningSettings
{
    public const int GRACE_MIN = 2;
    public const int GRACE_MAX = 120;
    public const int GRACE_DEFAULT = 10;
    public const int MAX_ITEMS = 64;

    private const string USER = '/^[a-z_][a-z0-9_-]{0,31}$/D';

    /**
     * @param list<string> $sshAllowUsers
     * @param list<string> $trustedIps
     */
    public function __construct(
        public bool $enabled,
        public array $sshAllowUsers = [],
        public array $trustedIps = [],
        public int $graceMinutes = self::GRACE_DEFAULT,
        public bool $hardenTmp = false,
        public ?string $invokingUser = null,
    ) {
    }

    public static function off(): self
    {
        return new self(false);
    }

    /**
     * One entry per line or separated by commas or spaces; blank entries dropped, duplicates removed, order kept.
     *
     * @return list<string>
     */
    public static function splitList(?string $text): array
    {
        $items = preg_split('/[\s,]+/', trim($text ?? ''), -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(false === $items ? [] : $items));
    }

    /**
     * @return array<string, string> a message per form field; empty when the settings can be applied
     */
    public function violations(): array
    {
        if (!$this->enabled) {
            return [];
        }
        $problems = [];

        if ([] === $this->sshAllowUsers) {
            $problems['sshUsers'] = 'Name at least one account that may log in over SSH.';
        } elseif (\count($this->sshAllowUsers) > self::MAX_ITEMS || [] !== array_filter($this->sshAllowUsers, static fn (string $u): bool => 1 !== preg_match(self::USER, $u))) {
            $problems['sshUsers'] = 'Use Unix account names (lower case letters, digits, "_" and "-"), at most '.self::MAX_ITEMS.'.';
        }

        if (null === $this->invokingUser || 1 !== preg_match(self::USER, $this->invokingUser)) {
            $problems['invokingUser'] = 'Name the SSH account you log in with.';
        } elseif (!isset($problems['sshUsers']) && !\in_array($this->invokingUser, $this->sshAllowUsers, true)) {
            $problems['invokingUser'] = 'The account you log in with must be in the allow list, or key-only SSH would lock you out.';
        }

        if (\count($this->trustedIps) > self::MAX_ITEMS || [] !== array_filter($this->trustedIps, static fn (string $a): bool => !self::address($a))) {
            $problems['trustedIps'] = 'Use IP addresses or CIDR ranges such as 203.0.113.10 or 2001:db8::/32, at most '.self::MAX_ITEMS.'.';
        }

        if ($this->graceMinutes < self::GRACE_MIN || $this->graceMinutes > self::GRACE_MAX) {
            $problems['graceMinutes'] = \sprintf('The grace period is %d to %d minutes.', self::GRACE_MIN, self::GRACE_MAX);
        }

        return $problems;
    }

    private static function address(string $address): bool
    {
        $parts = explode('/', $address, 2);
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return false !== filter_var($parts[0], \FILTER_VALIDATE_IP) && (!isset($parts[1]) || (1 === preg_match('/^\d{1,3}$/D', $parts[1]) && (int) $parts[1] <= $max));
    }
}
