<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\TemplateController;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Security\FleetUser;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Like ProjectControllerAuthorizationTest: the actions are called directly (no firewall, no access_control) with a member
 * token in the token storage, so each one has to refuse on its own. An admin token is the control.
 */
final class TemplateControllerAuthorizationTest extends AdminTestCase
{
    private function signIn(string $group): void
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function controller(): TemplateController
    {
        $controller = $this->container()->get(TemplateController::class);
        self::assertInstanceOf(TemplateController::class, $controller);

        return $controller;
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('https://localhost/admin/projects/blog/templates', $method, 'POST' === $method ? ['_token' => 'csrf-token'] : []);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);

        return $request;
    }

    private function seed(): TemplateService
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('full'));

        return $templates;
    }

    public function testEveryActionRefusesAMemberOnItsOwn(): void
    {
        $templates = $this->seed();

        $actions = [
            'index' => static fn (TemplateController $c): mixed => $c->index('blog'),
            'new' => fn (TemplateController $c): mixed => $c->new($this->request('POST'), 'blog'),
            'edit' => fn (TemplateController $c): mixed => $c->edit($this->request('POST'), 'blog', 'full'),
            'delete' => fn (TemplateController $c): mixed => $c->delete($this->request('POST'), 'blog', 'full'),
        ];

        $this->signIn('demo');
        foreach ($actions as $name => $call) {
            try {
                $call($this->controller());
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('full', $templates->get('blog', 'full')->name(), 'nothing was deleted');
    }

    public function testTheSameCallsPassTheVoterForAnAdmin(): void
    {
        $this->seed();

        $this->signIn('fleet-admins');
        $this->request();
        self::assertSame(200, $this->controller()->index('blog')->getStatusCode());
        self::assertSame(200, $this->controller()->new($this->request(), 'blog')->getStatusCode());
        self::assertSame(200, $this->controller()->edit($this->request(), 'blog', 'full')->getStatusCode());
    }
}
