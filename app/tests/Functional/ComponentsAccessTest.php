<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\FleetUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The live component endpoint (`/_components/…`) serves any registered component by name, and the access rules only
 * ask for a signed-in user: a component that forgot its own #[IsGranted] would be open to every member.
 */
final class ComponentsAccessTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /** What a real live component request carries; without them the bundle answers 400 before any access check. */
    private const array LIVE_REQUEST = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ACCEPT' => 'application/vnd.live-component+html', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function liveComponents(): iterable
    {
        $dir = \dirname(__DIR__, 2).'/src/Twig/Components';
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = 'App\\Twig\\Components\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            if ([] !== new \ReflectionClass($class)->getAttributes(AsLiveComponent::class)) {
                yield $class => [$class];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('liveComponents')]
    public function testEveryLiveComponentDeclaresIsGranted(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertNotSame([], $reflection->getAttributes(IsGranted::class), $class.' must declare #[IsGranted] on the class');
    }

    public function testThereIsAtLeastOneLiveComponentToCheck(): void
    {
        self::assertNotSame([], iterator_to_array(self::liveComponents()));
    }

    public function testAnonymousComponentRequestIs401(): void
    {
        $client = self::createClient();
        $client->request('POST', '/_components/IssueIdPreview', ['data' => '{}'], server: self::LIVE_REQUEST + ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberOnAdminComponentIs403(): void
    {
        $client = self::createClient();
        $component = $this->mountAsAdmin($client);

        // The same signed request the page would send, now from a member. The test kernel does not turn exceptions into
        // responses, so the 403 shows as the AccessDeniedException the component's class (or its hook) throws.
        $client->setServerParameter('HTTP_X_FLEET_STAND_IN_ROLE', 'member');

        $this->expectException(AccessDeniedException::class);
        $component->refresh();
    }

    public function testAdminReachesTheAdminComponent(): void
    {
        $client = self::createClient();
        $component = $this->mountAsAdmin($client);

        $client->setServerParameter('HTTP_X_FLEET_STAND_IN_ROLE', 'admin');
        $component->refresh();

        self::assertResponseIsSuccessful();
    }

    /**
     * The test helper mounts the component in this process, outside any request: it needs a signed-in administrator.
     */
    private function mountAsAdmin(KernelBrowser $client): TestLiveComponent
    {
        $user = new FleetUser('alice', 'Alice', null, ['fleet-admins'], 'fleet-admins');
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $this->createLiveComponent('IssueIdPreview', [], $client);
    }

    public function testNoRoleAdminBlanketOnComponents(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/security.yaml');
        self::assertIsArray($config);
        $rules = $config['security']['access_control'] ?? null;
        self::assertIsArray($rules);

        $found = 0;
        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $path = (string) ($rule['path'] ?? '');
            if ('ux_live_component' !== ($rule['route'] ?? null) && !str_contains($path, '_components')) {
                continue;
            }
            ++$found;
            self::assertSame('IS_AUTHENTICATED_FULLY', $rule['roles'] ?? null, 'a component rule must not be an admin blanket');
        }
        self::assertSame(2, $found, 'the route rule and the path rule');
    }
}
