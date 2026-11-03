<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

/**
 * What AutheliaUsersFile::syncWithReport() did.
 */
final readonly class UsersSyncResult
{
    /**
     * @param bool         $changed true when users.yml was rewritten; false when it already had this content
     * @param list<string> $adopted usernames whose password hash was taken over from Authelia (self-service reset)
     */
    public function __construct(
        public bool $changed,
        public array $adopted = [],
    ) {
    }
}
