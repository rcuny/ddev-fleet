<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\AuditController;
use App\Controller\Admin\SecretController;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;
use App\Security\FleetUser;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The `^/admin` access rule answers a member before a controller runs, so the page tests cannot tell whether the
 * controllers ask for the admin role themselves. Here the actions are called directly (no firewall, no access_control)
 * with a member token in the token storage: each one must refuse on its own, and none may reveal, change or delete
 * anything. An admin token is the control.
 */
final class SecretControllerAuthorizationTest extends AdminTestCase
{
    private function signIn(string $group): void
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('https://localhost/admin/secrets', $method, 'POST' === $method ? ['_token' => 'csrf-token', 'name' => 'NEW_KEY', 'value' => 'abc'] : [], [], [], ['HTTPS' => 'on', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);

        return $request;
    }

    private function controller(): SecretController
    {
        $controller = $this->container()->get(SecretController::class);
        self::assertInstanceOf(SecretController::class, $controller);

        return $controller;
    }

    /**
     * @return array<string, callable(SecretController): mixed>
     */
    private function actions(): array
    {
        return [
            'fleetIndex' => fn (SecretController $c): mixed => $c->fleetIndex($this->request()),
            'fleetPut' => fn (SecretController $c): mixed => $c->fleetPut($this->request('POST')),
            'fleetDelete' => fn (SecretController $c): mixed => $c->fleetDelete($this->request('POST'), 'API_KEY'),
            'fleetReveal' => fn (SecretController $c): mixed => $c->fleetReveal($this->request('POST'), 'API_KEY'),
            'projectIndex' => fn (SecretController $c): mixed => $c->projectIndex($this->request(), 'blog'),
            'projectPut' => fn (SecretController $c): mixed => $c->projectPut($this->request('POST'), 'blog'),
            'projectDelete' => fn (SecretController $c): mixed => $c->projectDelete($this->request('POST'), 'blog', 'API_KEY'),
            'projectReveal' => fn (SecretController $c): mixed => $c->projectReveal($this->request('POST'), 'blog', 'API_KEY'),
        ];
    }

    private function seed(): SecretStore
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        $store->putManaged(null, 'API_KEY', 'fleet-value-1', 'admin');
        $store->putManaged('blog', 'API_KEY', 'blog-value-1', 'admin');

        return $store;
    }

    public function testEveryActionRefusesAMemberOnItsOwn(): void
    {
        $store = $this->seed();

        $this->signIn('demo');
        foreach ($this->actions() as $name => $call) {
            try {
                $call($this->controller());
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame('fleet-value-1', $store->getManaged(null, 'API_KEY'));
        self::assertSame('blog-value-1', $store->getManaged('blog', 'API_KEY'));
        self::assertFalse($store->hasManaged(null, 'NEW_KEY'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_entry'), 'a refused call leaves no audit entry');
    }

    public function testTheAuditPageRefusesAMemberOnItsOwn(): void
    {
        $controller = $this->container()->get(AuditController::class);
        self::assertInstanceOf(AuditController::class, $controller);

        $this->signIn('demo');
        $this->expectException(AccessDeniedException::class);

        $controller->index($this->request());
    }

    public function testTheSameCallsPassForAnAdmin(): void
    {
        $this->seed();

        $this->signIn('fleet-admins');

        self::assertSame(200, $this->controller()->fleetIndex($this->request())->getStatusCode());
        self::assertSame(200, $this->controller()->projectIndex($this->request(), 'blog')->getStatusCode());
        $reveal = $this->controller()->fleetReveal($this->request('POST'), 'API_KEY');
        self::assertSame(200, $reveal->getStatusCode());
        self::assertStringContainsString('no-store', (string) $reveal->headers->get('Cache-Control'));
    }
}
