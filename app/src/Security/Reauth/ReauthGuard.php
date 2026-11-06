<?php

declare(strict_types=1);

namespace App\Security\Reauth;

use App\Fleet\Audit\AuditLog;
use App\Security\CurrentActor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a gated controller action calls: `if (!$guard->requireRecentAuth()) { return $guard->challenge(...); }`.
 */
final readonly class ReauthGuard
{
    public function __construct(
        private ReauthWindow $window,
        private AuditLog $audit,
        private CurrentActor $actor,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function requireRecentAuth(): bool
    {
        return $this->window->requireRecentAuth();
    }

    /** Ends the window after a one-shot action (sealed export, enabling Git over SSH, deleting a project). */
    public function close(): void
    {
        $this->window->close();
    }

    /**
     * Sends the user to the re-auth page and back to $returnPath (a page of the admin area, not the POST), and audits
     * the stopped action. $subject names the target (a secret name, a project), never a value.
     */
    public function challenge(string $action, string $subject, string $returnPath): RedirectResponse
    {
        $this->audit->record($this->actor->name(), AuditLog::REAUTH_REQUIRED, $action.' '.$subject);

        return new RedirectResponse($this->urls->generate('reauth', ['next' => $returnPath]), Response::HTTP_SEE_OTHER);
    }
}
