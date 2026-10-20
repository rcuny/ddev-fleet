<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Domain\Secret\SecretRepository;
use App\Domain\Ssh\SshKey;
use App\Domain\Ssh\SshKeyRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Fleet\System\CaddyEnvRenderer;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateWriter;
use App\Fleet\System\StoredOvhEndpoint;
use App\Fleet\System\SystemRenderer;
use App\Setup\SetupFinisher;
use App\Setup\SetupTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The setup domain wired by hand around real repositories, real renderers and a recording applier/runner, so a test
 * can assert the order of side effects across the helper, Caddy and the file system.
 */
final class SetupFixture
{
    public const string PROXY_SECRET = 'proxy-secret-0123456789abcdef-0123456789';

    /** @var list<string> every side effect in order: users-file, apply:<target>, caddy:validate, caddy:reload, ... */
    public array $events = [];

    public readonly MockClock $clock;
    public readonly HostSettingsRepository $settings;
    public readonly UserRepository $users;
    public readonly SshKeyRepository $keys;
    public readonly SecretStore $store;
    public readonly UserService $userService;
    public readonly AutheliaUsersFile $usersFile;
    public readonly SystemRenderer $system;
    public readonly SetupTokenService $tokens;
    public readonly SetupFinisher $finisher;

    /** @var array<string, \Throwable> apply target value => failure */
    public array $applyFails = [];
    public readonly AdminGroup $admins;
    /** @var list<string> caddy sub-commands (validate|reload) that exit 1 */
    public array $caddyFails = [];
    /** @var (callable(string): void)|null called with each event as it happens */
    public $probe;

    public function __construct(EntityManagerInterface $em, public readonly FleetPaths $paths, string $adminGroup = 'fleet-admins')
    {
        $this->admins = new AdminGroup($adminGroup);
        $this->clock = new MockClock('2026-10-19 10:00:00 UTC');
        $this->settings = new HostSettingsRepository($em);
        $this->users = new UserRepository($em);
        $this->keys = new SshKeyRepository($em);
        $this->userService = new UserService($this->users, new PasswordPolicy(), $this->admins);
        $this->store = new SecretStore(new SecretRepository($em), new SecretBox(new SecretKeyFile($paths)), $this->clock);
        new SecretKeyFile($paths)->ensure();

        $fixture = $this;
        $applier = new class($fixture) implements Applier {
            public function __construct(private readonly SetupFixture $fixture)
            {
            }

            public function apply(ApplyTarget $target): void
            {
                $this->fixture->record('apply:'.$target->value);
                if (isset($this->fixture->applyFails[$target->value])) {
                    throw $this->fixture->applyFails[$target->value];
                }
            }
        };
        $runner = new class($fixture) implements CommandRunner {
            public function __construct(private readonly SetupFixture $fixture)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $this->fixture->record('caddy:'.$argv[1]);

                return \in_array($argv[1], $this->fixture->caddyFails, true) ? new CommandResult(1, '', 'caddy says no') : new CommandResult(0);
            }
        };

        $twig = SystemTemplates::twig();
        $this->system = new SystemRenderer(
            new CaddyfileRenderer($twig, $paths, new StoredOvhEndpoint($this->store), '/opt/ddev-fleet/app'),
            new CaddyEnvRenderer($this->store, self::PROXY_SECRET),
            new AutheliaConfigRenderer($twig, $paths, new AdminGroup('fleet-admins')),
            new GateWriter($twig, $paths, new CaddyReloader($runner, $paths)),
            $paths,
            $applier,
        );
        $this->usersFile = new AutheliaUsersFile($paths, new AutheliaUsersRenderer());
        $this->tokens = new SetupTokenService($this->settings, $this->clock);
        $this->finisher = new SetupFinisher(
            $this->settings,
            $this->users,
            $this->admins,
            $this->keys,
            $this->usersFile,
            $this->system,
            new LockFactory(new InMemoryStore()),
            $this->clock,
            new NullLogger(),
        );
    }

    public function record(string $event): void
    {
        $this->events[] = $event;
        if (null !== $this->probe) {
            ($this->probe)($event);
        }
    }

    public function host(TlsMode $mode = TlsMode::Http01): HostSettings
    {
        $host = $this->settings->getOrCreate('fleet.example.com');
        $host->changeTlsMode($mode);
        $this->settings->save($host);

        return $host;
    }

    public function addAdmin(string $username = 'alice'): void
    {
        $this->userService->upsertAdmin($username, 'Alice', $username.'@example.com', 'a long enough passphrase');
    }

    public function addKeys(): void
    {
        foreach (SshKeyRole::cases() as $role) {
            $this->keys->save(new SshKey($role, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPlaceholderPlaceholderPlaceholderPlaceho test', 'SHA256:placeholder'));
        }
    }

    public function addOvhSecrets(): void
    {
        $this->store->putFleetWide('ovh_endpoint', 'ovh-eu', 'admin');
        $this->store->putFleetWide('ovh_application_key', 'app-key-12345678', 'admin');
        $this->store->putFleetWide('ovh_application_secret', 'app-secret-12345678', 'admin');
        $this->store->putFleetWide('ovh_consumer_key', 'consumer-key-12345678', 'admin');
    }

    /**
     * An initialised host that can finish: admin, both keys and (DNS mode) the OVH secrets.
     */
    public function readyHost(TlsMode $mode = TlsMode::Http01): HostSettings
    {
        $host = $this->host($mode);
        $this->addAdmin();
        $this->addKeys();
        if (TlsMode::OvhDns === $mode) {
            $this->addOvhSecrets();
        }

        return $host;
    }

    /**
     * @return list<string> staged files, relative to the staging directory
     */
    public function staged(): array
    {
        $files = [];
        $dir = $this->paths->stagingDir();
        foreach (is_dir($dir) ? (array) scandir($dir) : [] as $target) {
            if (!\is_string($target) || '.' === $target || '..' === $target) {
                continue;
            }
            foreach ((array) scandir($dir.'/'.$target) as $name) {
                if (\is_string($name) && '.' !== $name && '..' !== $name) {
                    $files[] = $target.'/'.$name;
                }
            }
        }
        sort($files);

        return $files;
    }
}
