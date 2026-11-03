<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Functional tests of the admin pages: a real kernel on a fresh FLEET_HOME (migrated database) and a client that acts
 * as an admin behind the dev stand-in (https, same-origin requests). The kernel is NOT rebooted between requests.
 * Switch identity with as('member'|'none'|'admin').
 */
abstract class AdminTestCase extends WebTestCase
{
    use FleetDatabase;

    public const string BASE = 'https://localhost';

    protected KernelBrowser $client;
    protected string $clientIp = '';
    private string $role = 'admin';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->clientIp = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->as('admin');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * Makes the following requests come from this stand-in role.
     */
    protected function as(string $role): void
    {
        $this->role = $role;
        $this->client->setServerParameters($this->browserServer());
    }

    /**
     * @return array<string, string>
     */
    protected function browserServer(string $fetchSite = 'same-origin'): array
    {
        return [
            'HTTPS' => 'on',
            'REMOTE_ADDR' => $this->clientIp,
            'HTTP_X_FLEET_STAND_IN_ROLE' => $this->role,
            'HTTP_SEC_FETCH_SITE' => $fetchSite,
        ];
    }

    protected function container(): ContainerInterface
    {
        $container = self::getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * What the database holds now, not what an earlier request left in the identity map (the kernel is not
     * rebooted, so services must be re-read through repositories built on this).
     */
    protected function em(): EntityManagerInterface
    {
        $em = $this->container()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return $em;
    }

    protected function guard(): FakeInstanceGuard
    {
        $guard = $this->container()->get(FakeInstanceGuard::class);
        self::assertInstanceOf(FakeInstanceGuard::class, $guard);

        return $guard;
    }

    protected function open(string $path): Crawler
    {
        return $this->client->request('GET', self::BASE.$path, server: $this->browserServer());
    }

    /**
     * @param array<string, mixed> $values form field name => value
     */
    protected function submit(Crawler $crawler, string $button, array $values): Crawler
    {
        $form = $crawler->selectButton($button)->form();
        $form->setValues($values);

        return $this->client->submit($form);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $files
     */
    protected function post(string $path, array $parameters, array $files = [], string $fetchSite = 'same-origin'): Crawler
    {
        return $this->client->request('POST', self::BASE.$path, $parameters + ['_token' => 'csrf-token'], $files, $this->browserServer($fetchSite));
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
