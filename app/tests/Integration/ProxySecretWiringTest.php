<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Host\HostSettings;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\CaddyEnvRenderer;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\PrivilegedApply;
use App\Security\TrustedProxyChecker;
use App\Tests\Support\TempFleetHome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * FLEET_PROXY_SECRET travels from the environment to two consumers that must agree: the app (TrustedProxyChecker
 * compares it with the FastCGI parameter) and Caddy's environment file (CaddyEnvRenderer). If either side read a
 * different value, every authenticated request would silently fail closed.
 */
final class ProxySecretWiringTest extends KernelTestCase
{
    use TempFleetHome;

    private const string SECRET = 'wiring-secret-0123456789abcdef-0123456789';

    /** @var array{server: mixed, env: mixed, process: string|false, hadServer: bool, hadEnv: bool} */
    private array $previous = ['server' => null, 'env' => null, 'process' => false, 'hadServer' => false, 'hadEnv' => false];

    protected function setUp(): void
    {
        parent::setUp();
        // dotenv put the committed (empty) default into $_SERVER/$_ENV; give back exactly what was there.
        $this->previous = [
            'server' => $_SERVER['FLEET_PROXY_SECRET'] ?? null,
            'env' => $_ENV['FLEET_PROXY_SECRET'] ?? null,
            'process' => getenv('FLEET_PROXY_SECRET'),
            'hadServer' => \array_key_exists('FLEET_PROXY_SECRET', $_SERVER),
            'hadEnv' => \array_key_exists('FLEET_PROXY_SECRET', $_ENV),
        ];
        $this->useFreshFleetHome();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->restoreSecret();
        $this->restoreFleetHome();
        parent::tearDown();
    }

    private function restoreSecret(): void
    {
        unset($_SERVER['FLEET_PROXY_SECRET'], $_ENV['FLEET_PROXY_SECRET']);
        if ($this->previous['hadServer']) {
            $_SERVER['FLEET_PROXY_SECRET'] = $this->previous['server'];
        }
        if ($this->previous['hadEnv']) {
            $_ENV['FLEET_PROXY_SECRET'] = $this->previous['env'];
        }
        false === $this->previous['process'] ? putenv('FLEET_PROXY_SECRET') : putenv('FLEET_PROXY_SECRET='.$this->previous['process']);
    }

    private static function setSecret(?string $value): void
    {
        if (null === $value) {
            unset($_SERVER['FLEET_PROXY_SECRET'], $_ENV['FLEET_PROXY_SECRET']);
            putenv('FLEET_PROXY_SECRET');

            return;
        }
        $_SERVER['FLEET_PROXY_SECRET'] = $_ENV['FLEET_PROXY_SECRET'] = $value;
        putenv('FLEET_PROXY_SECRET='.$value);
    }

    private static function request(?string $param): Request
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9'];
        if (null !== $param) {
            $server[TrustedProxyChecker::PROXY_PARAM] = $param;
        }

        return new Request(server: $server);
    }

    public function testTheEnvironmentValueReachesTheCheckerAndTheCaddyEnvFile(): void
    {
        self::setSecret(self::SECRET);
        self::bootKernel();
        $container = self::getContainer();
        $checker = $container->get('fleet.test_alias.trusted_proxy_checker');
        $env = $container->get('fleet.test_alias.caddy_env_renderer');
        self::assertInstanceOf(TrustedProxyChecker::class, $checker);
        self::assertInstanceOf(CaddyEnvRenderer::class, $env);

        // The value Caddy will hand to the app is exactly the one the app compares against.
        $line = trim($env->render(new HostSettings('fleet.example.com')));
        self::assertSame('FLEET_PROXY_SECRET='.self::SECRET, $line);
        self::assertTrue($checker->isTrustedRequest(self::request(substr($line, \strlen('FLEET_PROXY_SECRET=')))));
        self::assertFalse($checker->isTrustedRequest(self::request(self::SECRET.'x')));
        self::assertFalse($checker->isTrustedRequest(self::request(null)));
    }

    public function testWithoutASecretNothingIsTrustedAndNothingIsRendered(): void
    {
        self::setSecret('');
        self::bootKernel();
        $container = self::getContainer();
        $checker = $container->get('fleet.test_alias.trusted_proxy_checker');
        $env = $container->get('fleet.test_alias.caddy_env_renderer');
        self::assertInstanceOf(TrustedProxyChecker::class, $checker);
        self::assertInstanceOf(CaddyEnvRenderer::class, $env);

        self::assertFalse($checker->isTrustedRequest(self::request('')), 'empty must never equal empty');
        $this->expectException(\InvalidArgumentException::class);

        $env->render(new HostSettings('fleet.example.com'));
    }

    public function testTheContainerRendersTheParameterNameTheCheckerReads(): void
    {
        self::setSecret(self::SECRET);
        self::bootKernel();
        $caddyfile = self::getContainer()->get('fleet.test_alias.caddyfile_renderer');
        self::assertInstanceOf(CaddyfileRenderer::class, $caddyfile);

        $out = $caddyfile->render(new HostSettings('fleet.example.com'));

        self::assertStringContainsString('env '.TrustedProxyChecker::PROXY_PARAM.' {env.FLEET_PROXY_SECRET}', $out);
        self::assertStringContainsString('/run/php/fleet.sock', $out);
        self::assertStringContainsString('root * '.self::getContainer()->getParameter('kernel.project_dir').'/public', $out);
    }

    public function testTheApplierAliasIsThePrivilegedImplementation(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $applier = $container->get('fleet.test_alias.applier');
        $runner = $container->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(PrivilegedApply::class, $applier);
        self::assertInstanceOf(Applier::class, $applier);
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        $applier->apply(ApplyTarget::Authelia);

        $sudo = $runner->callsMatching(['sudo']);
        self::assertCount(1, $sudo);
        self::assertSame(['sudo', '-n', '--', '/usr/local/libexec/fleet/apply', 'authelia'], $sudo[0]->argv);
    }
}
