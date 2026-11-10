<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * What the root helper (`apply <target>`) can install. The values are the helper's argument names, so they also
 * appear in the sudoers line and in the helper; change them in all three places together.
 */
enum ApplyTarget: string
{
    case Caddy = 'caddy';
    case Authelia = 'authelia';
    case Slices = 'slices';
    /** The host's optional roles (hardening): installs the staged host-vars.yml, then runs the installed tag's playbook. */
    case Provision = 'provision';
    /** The read-only twin of provision (FLE-261): the installed playbook with --check --diff; stages nothing, prints a JSON report. */
    case Drift = 'drift';
}
