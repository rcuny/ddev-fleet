<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use Psr\Log\LoggerInterface;

/**
 * Rewrites users.yml (the project groups) after a project was created or deleted (ProjectService calls it, so the pages and
 * the commands get it alike). The project change is already committed, so a failure here is a warning, never a
 * rollback: it is logged and kept for the caller to show (takeWarning()), and the next change retries the write. No restart of Authelia is involved.
 */
final class ProjectAccessRefresher
{
    private ?string $warning = null;

    public function __construct(
        private readonly IdentitySync $identity,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function refresh(): void
    {
        try {
            $this->identity->refreshAccess();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->logger?->warning('The user file was not updated after a project change.', ['exception' => $e]);
            $this->warning = 'The project was saved, but the sign-in service was not updated: '.$e->getMessage();
        }
    }

    /**
     * The warning left by the last refresh(), once.
     */
    public function takeWarning(): ?string
    {
        $warning = $this->warning;
        $this->warning = null;

        return $warning;
    }
}
