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
}
