<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * What the Caddy gate snippet (FleetPaths::gateFile()) enforces in front of the app.
 */
enum GateMode: string
{
    /** First run: everything but the setup wizard, its assets and /health redirects to /setup. */
    case Setup = 'setup';
    /** Normal operation: every request except /health and /hooks/* goes through Authelia's forward_auth. */
    case Authelia = 'authelia';

    public function template(): string
    {
        return match ($this) {
            self::Setup => 'system/gate-setup.twig',
            self::Authelia => 'system/gate-authelia.twig',
        };
    }
}
