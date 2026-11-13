<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Fleet\Shell\CommandRunner;

/**
 * FLE-299: Debian's `nftables.service`, when enabled, loads /etc/nftables.conf (which begins with `flush ruleset`) at boot
 * and runs `nft flush ruleset` when it stops or reloads: that wipes Docker's and ufw's tables and ours. Fleet never enables
 * it. Isolation refuses to turn on while it is enabled or active, and says how to fix it.
 */
final readonly class NftablesServiceGuard
{
    public function __construct(private CommandRunner $runner)
    {
    }

    /**
     * @return string|null the problem (a sentence with the fix), or null when nftables.service is neither enabled nor active
     */
    public function problem(): ?string
    {
        foreach (['is-enabled' => 'enabled', 'is-active' => 'active'] as $verb => $word) {
            try {
                $result = $this->runner->run(['systemctl', $verb, 'nftables.service'], null, [], null, 10.0);
            } catch (\Throwable) {
                continue;
            }
            if ($result->isSuccess() && $word === trim($result->stdout)) {
                return 'nftables.service is '.$word.': its stop and reload run "nft flush ruleset", which would wipe Docker\'s, ufw\'s and the isolation tables. Disable it first (sudo systemctl disable --now nftables.service); Fleet never enables it.';
            }
        }

        return null;
    }
}
