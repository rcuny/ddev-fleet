<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\ProjectController;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Security\FleetUser;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The `^/admin` access rule answers a member before a controller runs, so the page tests cannot tell whether the
 * controller asks the voter itself. Here the actions are called directly (no firewall, no access_control) with a member
 * token in the token storage: each one must refuse on its own. An admin token is the control: it gets through the voter.
 */
final class ProjectControllerAuthorizationTest extends AdminTestCase
{
    private function signIn(string $group): void
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function controller(): ProjectController
    {
        $controller = $this->container()->get(ProjectController::class);
        self::assertInstanceOf(ProjectController::class, $controller);

        return $controller;
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('https://localhost/admin/projects', $method, 'POST' === $method ? ['_token' => 'csrf-token'] : []);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);

        return $request;
    }

    /**
     * @return array<string, callable(ProjectController): mixed>
     */
    private function actions(): array
    {
        return [
            'index' => static fn (ProjectController $c): mixed => $c->index(),
            'new' => fn (ProjectController $c): mixed => $c->new($this->request('POST')),
            'edit' => fn (ProjectController $c): mixed => $c->edit($this->request('POST'), 'blog'),
            'ports' => fn (ProjectController $c): mixed => $c->ports($this->request('POST'), 'blog'),
            'delete' => fn (ProjectController $c): mixed => $c->delete($this->request('POST'), 'blog'),
        ];
    }

    public function testEveryActionRefusesAMemberOnItsOwn(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));

        $this->signIn('demo');
        foreach ($this->actions() as $name => $call) {
            try {
                $call($this->controller());
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('blog', $service->get('blog')->name(), 'nothing was deleted');
    }

    public function testTheSameCallsPassTheVoterForAnAdmin(): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));

        $this->signIn('fleet-admins');
        // The list and the ports tab render; they only need the voter to say yes.
        $this->request();
        self::assertSame(200, $this->controller()->index()->getStatusCode());
        self::assertSame(200, $this->controller()->ports(new Request(), 'blog')->getStatusCode());
    }
}
