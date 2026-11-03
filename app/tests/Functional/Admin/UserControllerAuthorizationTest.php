<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\UserController;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\UserService;
use App\Security\FleetUser;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The `^/admin` access rule answers a member before a controller runs, so the page tests cannot tell whether the
 * controller asks for the role itself. Here the actions are called directly (no firewall, no access_control) with a
 * member token in the token storage: each one must refuse on its own, and change nothing. An admin token is the control.
 */
final class UserControllerAuthorizationTest extends AdminTestCase
{
    private function signIn(string $group): void
    {
        $user = new FleetUser('mallory', 'Mallory', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function controller(): UserController
    {
        $controller = $this->container()->get(UserController::class);
        self::assertInstanceOf(UserController::class, $controller);

        return $controller;
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('https://localhost/admin/users', $method, 'POST' === $method ? ['_token' => 'csrf-token'] : []);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);

        return $request;
    }

    private function seed(): void
    {
        $service = $this->container()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $service);
        $service->create('alice', 'Alice', 'alice@example.com', 'correct horse battery staple');
    }

    public function testEveryActionRefusesAMemberOnItsOwn(): void
    {
        $this->seed();
        $this->signIn('demo');

        $actions = [
            'index' => static fn (UserController $c): mixed => $c->index(),
            'new' => fn (UserController $c): mixed => $c->new($this->request('POST')),
            'edit' => fn (UserController $c): mixed => $c->edit($this->request('POST'), 'alice'),
            'password' => fn (UserController $c): mixed => $c->password($this->request('POST'), 'alice'),
            'enable' => fn (UserController $c): mixed => $c->enable($this->request('POST'), 'alice'),
            'disable' => fn (UserController $c): mixed => $c->disable($this->request('POST'), 'alice'),
            'delete' => fn (UserController $c): mixed => $c->delete($this->request('POST'), 'alice'),
        ];
        foreach ($actions as $name => $call) {
            try {
                $call($this->controller());
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }

        $alice = new UserRepository($this->em())->findByUsername('alice') ?? self::fail('nothing was deleted');
        self::assertTrue($alice->isEnabled());
    }

    public function testTheSameCallsPassForAnAdmin(): void
    {
        $this->seed();
        $this->signIn('fleet-admins');

        $this->request();
        self::assertSame(200, $this->controller()->index()->getStatusCode());
    }
}
