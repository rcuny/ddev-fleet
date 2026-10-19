<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretStore;
use App\Form\Setup\ClaudeTokenType;
use App\Setup\SetupActor;
use App\Setup\SetupProgress;
use App\Setup\SetupStep;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 4: the optional Claude Code OAuth token, stored sealed in the fleet-wide secret store. The field is write-only:
 * the page never shows the token or what is stored, only whether one is.
 */
final class ClaudeController extends AbstractSetupController
{
    private const string TOKEN_SHAPE = '/^[A-Za-z0-9._~+\/=-]{20,512}$/D';

    public function __construct(
        private readonly SetupProgress $progress,
        private readonly SecretStore $secrets,
        private readonly SetupActor $actor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/setup/claude', name: 'setup_claude', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (null !== $refusal = $this->guard($request)) {
            return $refusal;
        }

        $stored = $this->progress->claudeTokenStored();
        $form = $this->createForm(ClaudeTokenType::class, null, ['stored' => $stored]);
        $form->handleRequest($request);
        $next = SetupStep::Claude->next()?->route() ?? 'setup_finish';

        if ($form->isSubmitted() && $form->isValid()) {
            $skip = $form->get('skip');
            if ($skip instanceof ClickableInterface && $skip->isClicked()) {
                return $this->redirectToRoute($next);
            }

            $token = trim((string) $form->get('token')->getData());
            if ('' === $token) {
                if ($stored) {
                    return $this->redirectToRoute($next);
                }
                $form->get('token')->addError(new FormError('Paste the token, or press "Skip for now".'));
            } elseif (1 !== preg_match(self::TOKEN_SHAPE, $token)) {
                $form->get('token')->addError(new FormError('That does not look like a Claude token. Copy it again from "claude setup-token".'));
            } else {
                try {
                    $this->secrets->putFleetWide(SetupProgress::CLAUDE_SECRET, $token, $this->actor->name());
                    $this->addFlash('success', 'Claude token stored.');

                    return $this->redirectToRoute($next);
                } catch (SecretConflict|\RuntimeException $e) {
                    $this->logger->error('The Claude token could not be stored.', ['exception' => $e::class]);
                    $form->addError(new FormError('The token could not be stored. Try again.'));
                }
            }
        }

        return $this->page('setup/claude.html.twig', SetupStep::Claude, ['form' => $form, 'stored' => $stored]);
    }
}
