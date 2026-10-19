<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Functional tests of /admin/host: a real kernel on a fresh FLEET_HOME (migrated database, initialised host) and a
 * client that behaves like a browser behind the proxy (same-origin requests, the dev stand-in role in a header).
 */
abstract class HostPageTestCase extends WebTestCase
{
    use FleetDatabase;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->console('fleet:host:init', ['--domain' => 'fleet.example.com']);
        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->as('admin');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param 'admin'|'member'|'none' $role
     */
    protected function as(string $role, string $fetchSite = 'same-origin'): void
    {
        $this->client->setServerParameters([
            'HTTP_X_FLEET_STAND_IN_ROLE' => $role,
            'HTTP_SEC_FETCH_SITE' => $fetchSite,
        ]);
    }

    protected function open(string $path): Crawler
    {
        return $this->client->request('GET', $path);
    }

    /**
     * Opens $tab and submits its form with $values (field names as the browser sends them).
     *
     * @param array<string, mixed> $values
     */
    protected function submit(string $tab, string $button, array $values): Crawler
    {
        $crawler = $this->open('/admin/host?tab='.$tab);
        self::assertResponseStatusCodeSame(200);

        return $this->client->submit($crawler->selectButton($button)->form($values));
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return $em;
    }

    protected function host(): HostSettings
    {
        return new HostSettingsRepository($this->em())->get() ?? self::fail('No host row.');
    }

    protected function paths(): FleetPaths
    {
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        return $paths;
    }

    protected function secretStore(): SecretStore
    {
        return new SecretStore(new SecretRepository($this->em()), new SecretBox(new SecretKeyFile($this->paths())), Clock::get());
    }

    /**
     * Everything the application logged in this test run, as one string ('' when no file).
     */
    protected function logText(): string
    {
        $dir = (string) self::getContainer()->getParameter('kernel.logs_dir');
        $text = '';
        foreach (glob($dir.'/*.log') ?: [] as $file) {
            $text .= (string) file_get_contents($file);
        }

        return $text;
    }
}
