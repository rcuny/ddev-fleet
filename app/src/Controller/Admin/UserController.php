<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Project\ProjectAccessRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AccessInstallFailed;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\InvalidUserInput;
use App\Fleet\Auth\LastAdminGuard;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UnknownUser;
use App\Fleet\Auth\UserChangeRefused;
use App\Fleet\Auth\UserService;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Form\User\UserCreateType;
use App\Form\User\UserEditType;
use App\Form\User\UserPasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin user pages: list, create, edit (profile, role, project memberships), set a password, enable / disable and
 * delete. The rules live in UserService, ProjectAccessService and LastAdminGuard; this class maps forms to them.
 * Every change goes through IdentitySync::commit(), so the database changes only when users.yml was rendered, and the
 * Authelia access rules follow. Each action is behind the `^/admin` access rule and asks for ROLE_ADMIN as well
 * (defence in depth). A user is never deleted, disabled or demoted by themselves, nor when they are the last enabled
 * administrator (LastAdminGuard).
 *
 * Usernames only ever appear before a fixed suffix (`/edit`, `/password`, ...), so a user called `new` cannot shadow
 * the create page.
 */
final class UserController extends AbstractController
{
    private const string USERNAME = '[a-z0-9][a-z0-9._-]{0,63}';

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $service,
        private readonly ProjectAccessService $access,
        private readonly ProjectAccessRepository $accessRows,
        private readonly ProjectRepository $projects,
        private readonly LastAdminGuard $guard,
        private readonly IdentitySync $identity,
        private readonly AdminGroup $admins,
    ) {
    }

    #[Route('/admin/users', name: 'users', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $counts = [];
        foreach ($this->accessRows->membersByProject() as $usernames) {
            foreach ($usernames as $username) {
                $counts[$username] = ($counts[$username] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($this->users->all() as $user) {
            $rows[] = ['user' => $user, 'admin' => $this->admins->includes($user), 'projects' => $counts[$user->username()] ?? 0];
        }

        return $this->render('admin/users/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/admin/users/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserCreateType::class, null, ['projects' => $this->projectNames()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $username = self::text($data['username'] ?? null);
            $projects = self::names($data['projects'] ?? null);
            $siteOnly = true === ($data['siteOnly'] ?? false);
            $outcome = $this->commit(
                $form,
                function () use ($data, $username, $projects, $siteOnly): void {
                    $this->service->create(
                        $username,
                        self::text($data['displayName'] ?? null),
                        self::text($data['email'] ?? null),
                        self::text($data['password'] ?? null),
                        true === ($data['admin'] ?? false),
                        $siteOnly,
                    );
                    if ([] !== $projects) {
                        $this->access->setProjects($username, $projects);
                    }
                },
                \sprintf('User "%s" created.', $username),
            );
            if (true === $outcome) {
                return $this->redirectToRoute('user_edit', ['username' => $username], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('admin/users/new.html.twig', ['form' => $form->createView()], $this->statusOf($form));
    }

    #[Route('/admin/users/{username}/edit', name: 'user_edit', requirements: ['username' => self::USERNAME], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $username): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->load($username);
        $self = $this->actor() === $username;

        $form = $this->createForm(UserEditType::class, [
            'displayName' => $user->displayName(),
            'email' => $user->email(),
            'siteOnly' => $user->isSiteOnly(),
            'admin' => $this->admins->includes($user),
            'projects' => $this->access->projectsOf($username),
        ], ['projects' => $this->projectNames(), 'self' => $self]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $wantAdmin = true === ($data['admin'] ?? false);
            $wantSiteOnly = true === ($data['siteOnly'] ?? false);
            $outcome = $this->commit(
                $form,
                function () use ($data, $user, $username, $wantAdmin, $wantSiteOnly): void {
                    // The kind first (the email rule depends on it), then the profile: it validates everything before the
                    // role or the memberships are touched.
                    if ($user->isSiteOnly() !== $wantSiteOnly) {
                        $this->service->setSiteOnly($username, $wantSiteOnly);
                    }
                    $this->service->updateProfile($username, self::text($data['displayName'] ?? null), self::text($data['email'] ?? null));
                    $isAdmin = $this->admins->includes($user);
                    if ($isAdmin && !$wantAdmin) {
                        $this->guard->assertMayDemote($user, $this->actor());
                    }
                    if ($isAdmin !== $wantAdmin) {
                        $this->service->setAdmin($username, $wantAdmin);
                    }
                    $this->access->setProjects($username, self::names($data['projects'] ?? null));
                },
                'User saved.',
            );
            if (true === $outcome) {
                return $this->redirectToRoute('user_edit', ['username' => $username], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderEdit($user, $form, $this->createForm(UserPasswordType::class));
    }

    #[Route('/admin/users/{username}/password', name: 'user_password', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function password(Request $request, string $username): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->load($username);

        $form = $this->createForm(UserPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->formData($form);
            $outcome = $this->commit(
                $form,
                fn () => $this->service->setPassword($username, self::text($data['password'] ?? null)),
                \sprintf('The password of "%s" was changed. %s', $username, AutheliaConfigRenderer::banNotice($username)),
            );
            if (true === $outcome) {
                return $this->redirectToRoute('user_edit', ['username' => $username], Response::HTTP_SEE_OTHER);
            }
        }

        $edit = $this->createForm(UserEditType::class, [
            'displayName' => $user->displayName(),
            'email' => $user->email(),
            'siteOnly' => $user->isSiteOnly(),
            'admin' => $this->admins->includes($user),
            'projects' => $this->access->projectsOf($username),
        ], ['projects' => $this->projectNames(), 'self' => $this->actor() === $username]);

        return $this->renderEdit($user, $edit, $form);
    }

    #[Route('/admin/users/{username}/enable', name: 'user_enable', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function enable(Request $request, string $username): Response
    {
        return $this->toggle($request, $username, true);
    }

    #[Route('/admin/users/{username}/disable', name: 'user_disable', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function disable(Request $request, string $username): Response
    {
        return $this->toggle($request, $username, false);
    }

    #[Route('/admin/users/{username}/delete', name: 'user_delete', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function delete(Request $request, string $username): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->load($username);
        $this->denyUnlessCsrf($request);

        $outcome = $this->flashOutcome(
            function () use ($user, $username): void {
                $this->guard->assertMayDelete($user, $this->actor());
                $this->service->delete($username);
            },
            \sprintf('User "%s" deleted.', $username),
            'The user was not deleted.',
        );

        return $outcome
            ? $this->redirectToRoute('users', [], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('user_edit', ['username' => $username], Response::HTTP_SEE_OTHER);
    }

    private function toggle(Request $request, string $username, bool $enabled): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->load($username);
        $this->denyUnlessCsrf($request);

        $this->flashOutcome(
            function () use ($user, $username, $enabled): void {
                if (!$enabled) {
                    $this->guard->assertMayDisable($user, $this->actor());
                }
                $this->service->setEnabled($username, $enabled);
            },
            \sprintf('User "%s" %s.', $username, $enabled ? 'enabled' : 'disabled'),
            \sprintf('The user was not %s.', $enabled ? 'enabled' : 'disabled'),
        );

        return $this->redirectToRoute('user_edit', ['username' => $username], Response::HTTP_SEE_OTHER);
    }

    /**
     * Runs a change from a Symfony form and reports it as the form's own errors: a field refused by UserService goes
     * under its field, a refusal of LastAdminGuard or a failed render is a form-level error. A flash and `true` when
     * the change was committed.
     *
     * @param FormInterface<mixed> $form
     * @param callable(): mixed    $change
     */
    private function commit(FormInterface $form, callable $change, string $success): bool
    {
        try {
            $this->identity->commit($change);
        } catch (AccessInstallFailed $failed) {
            $this->addFlash('warning', $success.' '.$failed->getMessage());

            return true;
        } catch (InvalidUserInput $invalid) {
            $form->has($invalid->field) ? $form->get($invalid->field)->addError(new FormError($invalid->getMessage())) : $form->addError(new FormError($invalid->getMessage()));

            return false;
        } catch (UserChangeRefused $refused) {
            $form->addError(new FormError($refused->getMessage()));

            return false;
        } catch (UnknownUser) {
            throw new NotFoundHttpException();
        } catch (\RuntimeException|\InvalidArgumentException $failed) {
            $form->addError(new FormError('Nothing was changed: '.$failed->getMessage()));

            return false;
        }
        $this->addFlash('success', $success);

        return true;
    }

    /**
     * The same for the one-click actions (no form): the outcome is a flash.
     *
     * @param callable(): mixed $change
     */
    private function flashOutcome(callable $change, string $success, string $failure): bool
    {
        try {
            $this->identity->commit($change);
        } catch (AccessInstallFailed $failed) {
            $this->addFlash('warning', $success.' '.$failed->getMessage());

            return true;
        } catch (UserChangeRefused $refused) {
            $this->addFlash('warning', $failure.' '.$refused->getMessage());

            return false;
        } catch (UnknownUser) {
            throw new NotFoundHttpException();
        } catch (\RuntimeException|\InvalidArgumentException $failed) {
            $this->addFlash('warning', $failure.' Nothing was changed: '.$failed->getMessage());

            return false;
        }
        $this->addFlash('success', $success);

        return true;
    }

    /**
     * @param FormInterface<mixed> $edit
     * @param FormInterface<mixed> $password
     */
    private function renderEdit(User $user, FormInterface $edit, FormInterface $password): Response
    {
        $status = $edit->isSubmitted() && !$edit->isValid() || $password->isSubmitted() && !$password->isValid()
            || $edit->getErrors()->count() > 0 || $password->getErrors()->count() > 0
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/users/edit.html.twig', [
            'user' => $user,
            'admin' => $this->admins->includes($user),
            'siteOnly' => $user->isSiteOnly(),
            'self' => $this->actor() === $user->username(),
            'form' => $edit->createView(),
            'passwordForm' => $password->createView(),
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function statusOf(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() && !$form->isValid() || $form->getErrors()->count() > 0
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK);
    }

    private function load(string $username): User
    {
        return $this->users->findByUsername($username) ?? throw new NotFoundHttpException();
    }

    private function denyUnlessCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function actor(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }

    /**
     * @return list<string>
     */
    private function projectNames(): array
    {
        return array_map(static fn ($project): string => $project->name(), $this->projects->all());
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    private function formData(FormInterface $form): array
    {
        $data = $form->getData();
        if (!\is_array($data)) {
            return [];
        }

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, '\is_string')) : [];
    }
}
