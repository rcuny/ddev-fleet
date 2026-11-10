<?php

declare(strict_types=1);

namespace App\Domain\Template;

/**
 * Which SSH identities a template's instances get in their own ssh-agent (FLE-266). Only meaningful for a project with
 * `container_ssh` on.
 */
enum SshIdentities: string
{
    /** The host's push key plus the template's extra keys (what every template did before FLE-266). */
    case PushAndExtras = 'push_and_extras';

    /** Only the template's extra keys: a narrow-purpose instance never gets the write key. */
    case ExtrasOnly = 'extras_only';

    public function label(): string
    {
        return match ($this) {
            self::PushAndExtras => 'Push key and extra keys',
            self::ExtrasOnly => 'Extra keys only (no push key)',
        };
    }
}
