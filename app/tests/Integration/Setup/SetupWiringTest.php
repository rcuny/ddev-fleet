<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Setup\SetupAttemptLimiter;
use App\Setup\SetupCookie;
use App\Setup\SetupFinisher;
use App\Setup\SetupState;
use App\Setup\SetupTokenService;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The setup domain as the container builds it: APP_SECRET reaches the cookie signer, the limiter is the configured
 * one, and the four host/setup commands are registered.
 */
final class SetupWiringTest extends KernelTestCase
{
    use FleetDatabase;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testServicesAreWired(): void
    {
        $c = self::getContainer();
        self::assertInstanceOf(SetupFinisher::class, $c->get(SetupFinisher::class));
        self::assertInstanceOf(SetupState::class, $c->get(SetupState::class));
        self::assertInstanceOf(SetupTokenService::class, $c->get(SetupTokenService::class));
    }

    public function testTheCookieIsSignedWithTheKernelSecret(): void
    {
        $c = self::getContainer();
        $cookie = $c->get(SetupCookie::class);
        self::assertInstanceOf(SetupCookie::class, $cookie);
        $repository = $c->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $repository);
        $host = $repository->getOrCreate('fleet.example.com');
        $host->storeSetupToken(str_repeat('a', 64), new \DateTimeImmutable('+1 hour'));

        $issued = $cookie->issue($host);

        self::assertTrue($cookie->isValid($issued->getValue(), $host));
        $secret = $c->getParameter('kernel.secret');
        self::assertTrue(new SetupCookie($secret, new \Symfony\Component\Clock\NativeClock())->isValid($issued->getValue(), $host));
        self::assertFalse(new SetupCookie($secret.'x', new \Symfony\Component\Clock\NativeClock())->isValid($issued->getValue(), $host));
    }

    public function testTheLimiterAllowsTenAttemptsPerAddress(): void
    {
        $limiter = self::getContainer()->get(SetupAttemptLimiter::class);
        self::assertInstanceOf(SetupAttemptLimiter::class, $limiter);
        $ip = '203.0.113.'.random_int(1, 254).'-'.bin2hex(random_bytes(4));

        for ($i = 1; $i <= 10; ++$i) {
            self::assertTrue($limiter->attempt($ip), 'attempt '.$i);
        }
        self::assertFalse($limiter->attempt($ip), 'the 11th is refused');
        self::assertTrue($limiter->attempt($ip.'-other'), 'another address has its own budget');
    }

    public function testTheCommandsAreRegistered(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);

        foreach (['fleet:host:init', 'fleet:host:render', 'fleet:setup:token', 'fleet:setup:reset'] as $name) {
            self::assertTrue($application->has($name), $name);
        }
    }
}
