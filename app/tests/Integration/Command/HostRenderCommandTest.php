<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\HostRenderCommand;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HostRenderCommandTest extends KernelTestCase
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
    private function render(array $options = []): CommandTester
    {
        $tester = new CommandTester(new HostRenderCommand($this->fx->settings, $this->fx->system));
        $tester->execute($options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function gate(): string
    {
        return (string) @file_get_contents($this->fx->paths->gateFile());
    }

    public function testAutoPicksSetupGateUntilComplete(): void
    {
        $host = $this->fx->host();

        $open = $this->render();
        self::assertSame(0, $open->getStatusCode(), $open->getErrorOutput());
        self::assertStringContainsString('Gate: setup.', $open->getDisplay());
        self::assertStringNotContainsString('forward_auth', $this->gate());
        self::assertSame(['authelia/configuration.yml', 'caddy/Caddyfile', 'caddy/env'], $this->fx->staged());
        self::assertSame(['caddy:validate', 'caddy:reload'], $this->fx->events);

        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
        $done = $this->render();
        self::assertStringContainsString('Gate: authelia.', $done->getDisplay());
        self::assertStringContainsString('forward_auth', $this->gate());
    }

    public function testNoReloadOnlyWritesTheGateFile(): void
    {
        $this->fx->host();

        $tester = $this->render(['--no-reload' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame([], $this->fx->events, 'no caddy validate/reload (Caddy is not installed yet)');
        self::assertFileExists($this->fx->paths->gateFile());
    }

    public function testExplicitGateOverridesTheState(): void
    {
        $this->fx->host();

        $tester = $this->render(['--gate' => 'authelia', '--no-reload' => true]);

        self::assertStringContainsString('forward_auth', $this->gate());
        self::assertStringContainsString('Gate: authelia.', $tester->getDisplay());
    }

    public function testApplyInstallsAndRemovesWhatItStaged(): void
    {
        $this->fx->host(TlsMode::OvhDns);
        $this->fx->addOvhSecrets();

        $tester = $this->render(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame(['apply:authelia', 'apply:caddy', 'caddy:validate', 'caddy:reload'], $this->fx->events);
        self::assertSame([], $this->fx->staged(), 'the OVH credentials do not stay behind');
    }

    public function testAFailureStagesNothingAndExitsNonZero(): void
    {
        $this->fx->host(TlsMode::OvhDns); // no OVH secrets yet

        $tester = $this->render();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('ovh_', $tester->getErrorOutput());
        self::assertSame([], $this->fx->staged());
        self::assertFileDoesNotExist($this->fx->paths->gateFile());
    }

    public function testAnInvalidGateOptionAndAMissingHostFail(): void
    {
        self::assertSame(1, $this->render(['--gate' => 'nope'])->getStatusCode());

        $noHost = $this->render();
        self::assertSame(1, $noHost->getStatusCode());
        self::assertStringContainsString('fleet:host:init', $noHost->getErrorOutput());
    }

    public function testTheSetupGateOnACompletedInstallNeedsForce(): void
    {
        $host = $this->fx->host();
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
        $this->render(['--no-reload' => true]);

        $refused = $this->render(['--gate' => 'setup', '--no-reload' => true]);
        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('fleet:setup:reset', $refused->getErrorOutput());
        self::assertStringContainsString('forward_auth', $this->gate(), 'the gate was not touched');

        $forced = $this->render(['--gate' => 'setup', '--force' => true, '--no-reload' => true]);
        self::assertSame(0, $forced->getStatusCode(), $forced->getErrorOutput());
        self::assertStringContainsString('--force', $forced->getErrorOutput(), 'a warning is printed');
        self::assertStringNotContainsString('forward_auth', $this->gate());
    }
}
