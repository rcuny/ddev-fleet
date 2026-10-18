<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\HostInitCommand;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Ssh\KnownHostsFile;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HostInitCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private SetupFixture $fx;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->fx = new SetupFixture($em, $paths);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function init(array $options): CommandTester
    {
        $paths = $this->fx->paths;
        $command = new HostInitCommand(
            $paths,
            new SecretKeyFile($paths),
            new KnownHostsFile($paths, \dirname(__DIR__, 3).'/resources/ssh/known_hosts.seed'),
            $this->fx->settings,
        );
        $tester = new CommandTester($command);
        $tester->execute($options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testIsIdempotent(): void
    {
        $first = $this->init(['--domain' => 'fleet.example.com', '--acme-email' => 'ops@example.com']);
        $key = (string) file_get_contents($this->fx->paths->secretKey());
        $hosts = (string) file_get_contents($this->fx->paths->knownHostsFile());
        $second = $this->init(['--domain' => 'fleet.example.com', '--acme-email' => 'ops@example.com']);

        self::assertSame(0, $first->getStatusCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getStatusCode(), $second->getErrorOutput());
        self::assertSame($key, file_get_contents($this->fx->paths->secretKey()), 'the secret key is never regenerated');
        self::assertSame($hosts, file_get_contents($this->fx->paths->knownHostsFile()));
        $this->entityManager()->clear();
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM host_settings'));
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        self::assertSame('fleet.example.com', $host->domain());
        self::assertSame('ops@example.com', $host->acmeEmail());
        self::assertSame(TlsMode::Http01, $host->tlsMode());
    }

    public function testAnInstallerRerunWithoutOptionsKeepsTheSettings(): void
    {
        $this->init(['--domain' => 'fleet.example.com', '--acme-email' => 'ops@example.com', '--tls-mode' => 'ovh_dns']);
        $again = $this->init([]);

        self::assertSame(0, $again->getStatusCode(), $again->getErrorOutput());
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        self::assertSame('fleet.example.com', $host->domain());
        self::assertSame('ops@example.com', $host->acmeEmail());
        self::assertSame(TlsMode::OvhDns, $host->tlsMode());
    }

    public function testCreatesSecretKey0600AndSeedsKnownHosts(): void
    {
        $this->init(['--domain' => 'fleet.example.com']);

        clearstatcache();
        self::assertSame(0o600, fileperms($this->fx->paths->secretKey()) & 0o777);
        self::assertSame(32, \strlen((string) file_get_contents($this->fx->paths->secretKey())));
        self::assertStringContainsString('github.com', (string) file_get_contents($this->fx->paths->knownHostsFile()));
        self::assertDirectoryExists($this->fx->paths->stagingDir());
    }

    public function testDomainChangeAfterCompletionNeedsForce(): void
    {
        $this->init(['--domain' => 'fleet.example.com']);
        $host = $this->fx->settings->get();
        self::assertNotNull($host);
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);

        $refused = $this->init(['--domain' => 'other.example.com']);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('--force', $refused->getErrorOutput());
        self::assertSame('fleet.example.com', $this->fx->settings->get()?->domain());

        $modeRefused = $this->init(['--tls-mode' => 'ovh_dns']);
        self::assertSame(1, $modeRefused->getStatusCode());

        $same = $this->init(['--domain' => 'fleet.example.com']);
        self::assertSame(0, $same->getStatusCode(), 'the unchanged domain is fine');

        $forced = $this->init(['--domain' => 'other.example.com', '--force' => true]);
        self::assertSame(0, $forced->getStatusCode(), $forced->getErrorOutput());
        $after = $this->fx->settings->get();
        self::assertNotNull($after);
        self::assertSame('other.example.com', $after->domain());
        self::assertTrue($after->isSetupComplete());
    }

    public function testNeverMintsAToken(): void
    {
        $this->init(['--domain' => 'fleet.example.com']);
        $this->init(['--domain' => 'fleet.example.com']);

        self::assertNull($this->fx->settings->get()?->setupTokenHash());
        self::assertSame([], $this->fx->users->all());
        foreach (\App\Domain\Ssh\SshKeyRole::cases() as $role) {
            self::assertNull($this->fx->keys->findByRole($role));
        }
    }

    public function testInvalidValuesAreDomainErrors(): void
    {
        foreach ([
            ['--domain' => "x.example.com\n}"],
            ['--domain' => '192.0.2.1'],
            ['--domain' => 'fleet.example.com', '--acme-email' => 'not an email'],
            ['--domain' => 'fleet.example.com', '--tls-mode' => 'magic'],
        ] as $options) {
            $tester = $this->init($options);
            self::assertSame(1, $tester->getStatusCode(), json_encode($options, \JSON_THROW_ON_ERROR));
            self::assertNotSame('', $tester->getErrorOutput());
        }
        self::assertNull($this->fx->settings->get());
    }

    public function testTheDomainIsRequiredTheFirstTime(): void
    {
        $tester = $this->init([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--domain', $tester->getErrorOutput());
    }

    public function testUppercaseDomainIsNormalised(): void
    {
        $this->init(['--domain' => ' Fleet.Example.COM ']);

        self::assertSame('fleet.example.com', $this->fx->settings->get()?->domain());
    }
}
