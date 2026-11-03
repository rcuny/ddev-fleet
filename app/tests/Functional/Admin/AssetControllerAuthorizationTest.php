<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\AssetController;
use App\Fleet\FleetPaths;
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
 * The `^/admin` access rule answers a member before a controller runs. Here every action is called directly (no
 * firewall, no access_control) with a member token: each must refuse on its own, through ProjectVoter::SETTINGS, before
 * touching a file. An admin token is the control.
 */
final class AssetControllerAuthorizationTest extends AdminTestCase
{
    private function signIn(string $group): void
    {
        $user = new FleetUser('alice', 'Alice', null, [$group], 'fleet-admins');
        $storage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function controller(): AssetController
    {
        $controller = $this->container()->get(AssetController::class);
        self::assertInstanceOf(AssetController::class, $controller);

        return $controller;
    }

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('https://localhost/admin/projects/blog/assets', $method, 'POST' === $method ? ['_token' => 'csrf-token', 'path' => 'a.sql', 'name' => 'x'] : [], [], [], ['HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);

        return $request;
    }

    /**
     * @return array<string, callable(AssetController): mixed>
     */
    private function actions(): array
    {
        $id = str_repeat('a', 32);

        return [
            'index' => fn (AssetController $c): mixed => $c->index($this->request(), 'blog'),
            'download' => fn (AssetController $c): mixed => $c->download($this->request(), 'blog'),
            'folder' => fn (AssetController $c): mixed => $c->folder($this->request('POST'), 'blog'),
            'delete' => fn (AssetController $c): mixed => $c->delete($this->request('POST'), 'blog'),
            'begin' => fn (AssetController $c): mixed => $c->begin($this->request('POST'), 'blog'),
            'status' => fn (AssetController $c): mixed => $c->status($this->request(), 'blog', $id),
            'chunk' => fn (AssetController $c): mixed => $c->chunk($this->request('POST'), 'blog', $id, 0),
            'complete' => fn (AssetController $c): mixed => $c->complete($this->request('POST'), 'blog', $id),
            'abort' => fn (AssetController $c): mixed => $c->abort($this->request('POST'), 'blog', $id),
        ];
    }

    private function makeProject(): string
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $dir = $paths->assetsDir('blog');
        mkdir($dir, 0o750, true);
        file_put_contents($dir.'/a.sql', 'keep');

        return $dir;
    }

    public function testEveryActionRefusesAMemberOnItsOwn(): void
    {
        $dir = $this->makeProject();

        $this->signIn('demo');
        foreach ($this->actions() as $name => $call) {
            try {
                $call($this->controller());
                self::fail($name.' let a member through');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(['.', '..', 'a.sql'], scandir($dir), 'nothing was created or deleted');
        self::assertSame('keep', file_get_contents($dir.'/a.sql'));
    }

    public function testTheSameCallsPassTheVoterForAnAdmin(): void
    {
        $this->makeProject();

        $this->signIn('fleet-admins');
        self::assertSame(200, $this->controller()->index($this->request(), 'blog')->getStatusCode());
        self::assertSame(404, $this->controller()->status($this->request(), 'blog', str_repeat('a', 32))->getStatusCode());
    }
}
