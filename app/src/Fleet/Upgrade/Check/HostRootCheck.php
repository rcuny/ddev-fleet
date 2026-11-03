<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The apply step runs as root (`sudo fleet upgrade`, ruling R2), so sudo must exist on the host.
 *
 * Findings: `host.sudo` (Block, not overridable): sudo is missing. Remedy: install sudo, or run the upgrade as root.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 900])]
final readonly class HostRootCheck implements UpgradeCheck
{
    public const string SUDO = 'host.sudo';

    public function __construct(private CommandRunner $runner)
    {
    }

    public function check(Plan $plan): array
    {
        try {
            $ok = $this->runner->run(['sudo', '--version'], timeout: 5.0)->isSuccess();
        } catch (\Throwable) {
            $ok = false;
        }

        return $ok ? [] : [CheckResult::block(self::SUDO, 'sudo is not available on this host, and the upgrade runs as root.', 'Install sudo, or log in as root and run `fleet upgrade` there.')];
    }
}
