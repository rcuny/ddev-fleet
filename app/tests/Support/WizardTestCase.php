<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Secret\SecretRepository;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Setup\SetupTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Functional tests of the setup wizard: a real kernel on a fresh FLEET_HOME (migrated database, initialised host, a
 * minted setup token), a client that looks like a browser behind the sign-in proxy before setup (https, no identity,
 * same-origin requests) and a recording command runner. The kernel is NOT rebooted between requests, so the recorded
 * commands and scripted results of the runner survive; services are read fresh through the helpers below.
 */
abstract class WizardTestCase extends WebTestCase
{
    use FleetDatabase;

    public const string BASE = 'https://localhost';
    public const string PASSWORD = 'correct horse battery staple';
    public const string PROXY_SECRET = 'wizard-test-proxy-secret-0123456789abcdef';

    protected KernelBrowser $client;
    protected string $token = '';
    protected string $clientIp = '';

    /** @var array{server: mixed, env: mixed, process: string|false, hadServer: bool, hadEnv: bool} */
    private array $previousProxySecret;

    protected function setUp(): void
    {
        // What the installer puts in fleet.env: Finish renders the Caddy environment file from it.
        $this->previousProxySecret = [
            'server' => $_SERVER['FLEET_PROXY_SECRET'] ?? null,
            'env' => $_ENV['FLEET_PROXY_SECRET'] ?? null,
            'process' => getenv('FLEET_PROXY_SECRET'),
            'hadServer' => \array_key_exists('FLEET_PROXY_SECRET', $_SERVER),
            'hadEnv' => \array_key_exists('FLEET_PROXY_SECRET', $_ENV),
        ];
        $_SERVER['FLEET_PROXY_SECRET'] = $_ENV['FLEET_PROXY_SECRET'] = self::PROXY_SECRET;
        putenv('FLEET_PROXY_SECRET='.self::PROXY_SECRET);

        $this->setUpFleetDatabase();
        $this->console('fleet:host:init', ['--domain' => 'fleet.example.com']);
        $this->token = $this->tokens()->mint();
        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();
        // Every test is a different client address (the throttle's counters outlive a test run in the cache pool), so
        // the attempts of one test never count against another.
        $this->clientIp = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->client->setServerParameters($this->browserServer());
        $this->runner()->reset();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        unset($_SERVER['FLEET_PROXY_SECRET'], $_ENV['FLEET_PROXY_SECRET']);
        $this->previousProxySecret['hadServer'] && $_SERVER['FLEET_PROXY_SECRET'] = $this->previousProxySecret['server'];
        $this->previousProxySecret['hadEnv'] && $_ENV['FLEET_PROXY_SECRET'] = $this->previousProxySecret['env'];
        putenv(false === $this->previousProxySecret['process'] ? 'FLEET_PROXY_SECRET' : 'FLEET_PROXY_SECRET='.$this->previousProxySecret['process']);
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    protected function browserServer(string $fetchSite = 'same-origin'): array
    {
        return [
            'HTTPS' => 'on',
            'REMOTE_ADDR' => $this->clientIp,
            'HTTP_X_FLEET_STAND_IN_ROLE' => 'none',
            'HTTP_SEC_FETCH_SITE' => $fetchSite,
        ];
    }

    protected function container(): ContainerInterface
    {
        $container = self::getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    protected function em(): EntityManagerInterface
    {
        $em = $this->container()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        // What the database holds now, not what an earlier request left in the identity map.
        $em->clear();

        return $em;
    }

    protected function tokens(): SetupTokenService
    {
        $tokens = $this->container()->get('fleet.test_alias.setup_token_service');
        self::assertInstanceOf(SetupTokenService::class, $tokens);

        return $tokens;
    }

    protected function runner(): RecordingCommandRunner
    {
        $runner = $this->container()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    protected function paths(): FleetPaths
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        return $paths;
    }

    protected function host(): HostSettings
    {
        return new HostSettingsRepository($this->em())->get() ?? self::fail('No host row.');
    }

    protected function users(): UserRepository
    {
        return new UserRepository($this->em());
    }

    protected function keys(): SshKeyRepository
    {
        return new SshKeyRepository($this->em());
    }

    protected function secretStore(): SecretStore
    {
        $paths = $this->paths();

        return new SecretStore(new SecretRepository($this->em()), new SecretBox(new SecretKeyFile($paths)), Clock::get());
    }

    protected function createAdmin(string $username = 'root', string $password = self::PASSWORD): void
    {
        new UserService($this->users(), new PasswordPolicy())->upsertAdmin($username, 'Root Admin', 'root@example.com', $password);
    }

    protected function completeSetupInDatabase(): void
    {
        $repository = new HostSettingsRepository($this->em());
        $host = $repository->get() ?? self::fail('No host row.');
        $host->clearSetupToken();
        $host->markSetupComplete();
        $repository->save($host);
    }

    /**
     * Opens the wizard session the way a person does: the setup link, then the redirect it answers with.
     */
    protected function signIn(): void
    {
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token, server: $this->browserServer('none'));
        self::assertResponseStatusCodeSame(302);
    }

    protected function open(string $path): Crawler
    {
        return $this->client->request('GET', self::BASE.$path);
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

    /**
     * A valid ssh-ed25519 public key (the blob is well formed, the key bytes are random).
     */
    protected static function randomEd25519(): string
    {
        return base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32));
    }
}
