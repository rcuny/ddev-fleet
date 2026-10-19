<?php

declare(strict_types=1);

namespace App\Fleet\Host;

use App\Setup\SetupProgress;

/**
 * The names of the fleet-wide secrets the host settings page manages (SecretStore, fleet scope).
 */
final class HostSecrets
{
    /** The same secret the setup wizard's Claude step writes. */
    public const string CLAUDE_TOKEN = SetupProgress::CLAUDE_SECRET;
    public const string SMTP_PASSWORD = 'smtp_password';

    private function __construct()
    {
    }
}
