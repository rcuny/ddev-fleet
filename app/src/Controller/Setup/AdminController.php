<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Form\Setup\AdminAccountType;
use App\Setup\SetupProgress;
use App\Setup\SetupStep;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 1: the administrator (username, name, email, password). The wizard owns exactly one administrator: once it
 * exists the username is fixed and another POST updates that same user.
 */
final class AdminController extends AbstractSetupController
{
    public function __construct(
        private readonly SetupProgress $progress,
        private readonly UserService $users,
        private readonly PasswordPolicy $policy,
    ) {
    }

    #[Route('/setup/admin', name: 'setup_admin', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $admin = $this->progress->admin();
        $form = $this->createForm(
            AdminAccountType::class,
            null === $admin ? null : ['username' => $admin->username(), 'displayName' => $admin->displayName(), 'email' => $admin->email()],
            ['username_locked' => null !== $admin],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{username?: mixed, displayName: string, email: string, password: string} $data */
            $data = $form->getData();
            $username = $admin?->username() ?? (string) ($data['username'] ?? '');
            $password = $data['password'];

            $violations = $this->policy->violations($password, $username);
            if ([] === $violations) {
                try {
                    $this->users->upsertAdmin($username, $data['displayName'], $data['email'], $password);
                    $this->addFlash('success', 'Administrator saved.');

                    return $this->redirectToRoute(SetupStep::Admin->next()?->route() ?? 'setup_finish');
                } catch (\InvalidArgumentException $e) {
                    // UserService messages describe the rule that failed, never the value.
                    $form->addError(new FormError($e->getMessage()));
                }
            }
            foreach ($violations as $violation) {
                $form->get('password')->get('first')->addError(new FormError($violation));
            }
        }

        return $this->page('setup/admin.html.twig', SetupStep::Admin, ['form' => $form, 'admin' => $admin]);
    }
}
