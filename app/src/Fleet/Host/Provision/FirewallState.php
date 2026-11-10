<?php

declare(strict_types=1);

namespace App\Fleet\Host\Provision;

/**
 * Where the firewall dead-man's switch stands (install/ansible/roles/hardening).
 */
enum FirewallState: string
{
    /** Hardening is not on, so there is no switch. */
    case Off = 'off';
    /** The firewall is on and the timer runs: unless someone confirms, it turns the firewall off when the grace period ends. */
    case Armed = 'armed';
    /** Someone ran `sudo fleet-firewall-confirm`: the firewall stays on. */
    case Confirmed = 'confirmed';
    /** The grace period ended without a confirmation: the switch turned the firewall off. */
    case Expired = 'expired';
}
