<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\User\User;
use App\Setup\SetupAccessSubscriber;
use App\Tests\Support\WizardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every wizard route, spelled every odd way, with setup open (no session) and complete, by GET and POST: the answer is
 * the guard's (403, or 404 once complete) and nothing changes. Then the same with the access subscriber removed from
 * the dispatcher, so each controller is shown to refuse on its own.
 */
final class SetupGuardTest extends WizardTestCase
{
    private const string ATTACKER = 'attacker passphrase that is long';

    /**
     * @return list<string> the path of every route whose name starts with setup_
     */
    private function setupPaths(): array
    {
        $router = $this->container()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $paths = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'setup_')) {
                $paths[] = $route->getPath();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function variants(): iterable
    {
        yield 'plain' => ['%s'];
        yield 'encoded first letter' => ['/%%73etup%s'];
        yield 'encoded last segment' => ['@lastsegment'];
        yield 'upper case' => ['@upper'];
        yield 'dot segment' => ['@dot'];
        yield 'double slash' => ['/%s'];
        yield 'trailing slash' => ['%s/'];
        yield 'double encoded' => ['/%%2573etup%s'];
        yield 'encoded slash' => ['@encodedslash'];
    }

    private function variant(string $template, string $path): string
    {
        $rest = substr($path, \strlen('/setup'));

        return match ($template) {
            '@lastsegment' => '' === $rest ? '/%73etup' : '/setup/%'.dechex(\ord($rest[1])).substr($rest, 2),
            '@upper' => '/SETUP'.strtoupper($rest),
            '@dot' => '/setup/.'.('' === $rest ? '' : $rest),
            '@encodedslash' => '/setup%2f'.ltrim($rest, '/'),
            default => str_contains($template, '%%') ? \sprintf($template, $rest) : \sprintf($template, $path),
        };
    }

    private function adminHash(): string
    {
        $user = $this->users()->findByUsername('root');
        self::assertInstanceOf(User::class, $user);

        return $user->passwordHash();
    }

    /**
     * @return array<string, mixed>
     */
    private function attack(): array
    {
        return [
            'admin_account' => [
                'username' => 'root',
                'displayName' => 'Mallory',
                'email' => 'mallory@example.com',
                'password' => ['first' => self::ATTACKER, 'second' => self::ATTACKER],
                '_token' => 'csrf-token',
            ],
            'action' => 'regenerate',
            'role' => 'deploy',
            'confirm' => '1',
            'host' => 'git.example.com',
            'claude_token' => ['token' => 'sk-ant-oat01-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'save' => '', '_token' => 'csrf-token'],
        ];
    }

    private function assertNothingChanged(string $hash): void
    {
        self::assertSame($hash, $this->adminHash(), 'the administrator was not touched');
        self::assertSame('Root Admin', $this->users()->findByUsername('root')?->displayName());
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
        self::assertSame([], $this->runner()->callsMatching(['ssh-keyscan']));
    }

    public function testTheRouteListIsWhatWeThinkItIs(): void
    {
        self::assertSame(['/setup', '/setup/admin', '/setup/claude', '/setup/finish', '/setup/ssh', '/setup/tls'], $this->setupPaths());
    }

    #[DataProvider('variants')]
    public function testOpenWithoutASessionNothingIsServedAndNothingChanges(string $template): void
    {
        $this->createAdmin();
        $hash = $this->adminHash();

        foreach ($this->setupPaths() as $path) {
            $uri = self::BASE.$this->variant($template, $path);
            foreach (['GET', 'POST', 'PUT'] as $method) {
                $this->client->request($method, $uri, 'GET' === $method ? [] : $this->attack(), server: $this->browserServer());
                $status = $this->client->getResponse()->getStatusCode();
                self::assertContains($status, [403, 404], $method.' '.$uri);
                if (403 === $status) {
                    self::assertStringContainsString('Setup link needed', $this->body(), $method.' '.$uri);
                }
            }
        }
        $this->assertNothingChanged($hash);
    }

    #[DataProvider('variants')]
    public function testCompleteEverythingIs404AndNothingChanges(string $template): void
    {
        $this->createAdmin();
        $hash = $this->adminHash();
        $this->signIn();
        $this->completeSetupInDatabase();

        foreach ($this->setupPaths() as $path) {
            $uri = self::BASE.$this->variant($template, $path);
            foreach (['GET', 'POST', 'PUT'] as $method) {
                $this->client->request($method, $uri, 'GET' === $method ? [] : $this->attack(), server: $this->browserServer());
                self::assertResponseStatusCodeSame(404, $method.' '.$uri);
                self::assertStringContainsString('Setup is complete', $this->body(), $method.' '.$uri);
            }
        }
        $this->assertNothingChanged($hash);
    }

    public function testTheCompletePageSaysWhereToSignInAndLeaksNothing(): void
    {
        $this->createAdmin();
        $this->completeSetupInDatabase();

        $this->client->request('GET', self::BASE.'/setup/finish');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('[data-testid=setup-complete]', 'auth.fleet.example.com');
        self::assertSame('/', $this->client->getCrawler()->filter('[data-testid=setup-complete-sign-in]')->attr('href'));
        self::assertStringNotContainsString('root', $this->body());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    private function removeTheSubscriber(): void
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $removed = 0;
        foreach ([KernelEvents::REQUEST, KernelEvents::RESPONSE] as $event) {
            foreach ($dispatcher->getListeners($event) as $listener) {
                if (\is_array($listener) && $listener[0] instanceof SetupAccessSubscriber) {
                    $dispatcher->removeListener($event, $listener);
                    ++$removed;
                }
            }
        }
        self::assertSame(3, $removed, 'two request listeners and the response listener');
    }

    public function testEveryControllerRefusesOnItsOwnWhenSetupIsOpen(): void
    {
        $this->createAdmin();
        $hash = $this->adminHash();
        $this->removeTheSubscriber();

        foreach ($this->setupPaths() as $path) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, self::BASE.$path, 'GET' === $method ? [] : $this->attack(), server: $this->browserServer());
                self::assertResponseStatusCodeSame(403, $method.' '.$path);
                self::assertStringContainsString('Setup link needed', $this->body());
            }
        }
        $this->assertNothingChanged($hash);
    }

    public function testEveryControllerRefusesOnItsOwnWhenSetupIsComplete(): void
    {
        $this->createAdmin();
        $hash = $this->adminHash();
        $this->signIn();
        $this->completeSetupInDatabase();
        $this->removeTheSubscriber();

        foreach ($this->setupPaths() as $path) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, self::BASE.$path, 'GET' === $method ? [] : $this->attack(), server: $this->browserServer());
                self::assertResponseStatusCodeSame(404, $method.' '.$path);
                self::assertStringContainsString('Setup is complete', $this->body());
            }
        }
        $this->assertNothingChanged($hash);
    }

    public function testASessionFromBeforeCompletionDoesNotReachTheControllersEither(): void
    {
        $this->createAdmin();
        $hash = $this->adminHash();
        $this->signIn();
        $this->completeSetupInDatabase();
        $this->removeTheSubscriber();

        $this->post('/setup/admin', $this->attack());

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingChanged($hash);
    }
}
