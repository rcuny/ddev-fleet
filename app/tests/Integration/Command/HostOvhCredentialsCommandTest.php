<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\HostOvhCredentialsCommand;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SetupFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HostOvhCredentialsCommandTest extends KernelTestCase
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
     * @param list<string>         $lines   what is typed at the hidden prompts, or piped when not interactive
     */
    private function rotate(array $options = [], array $lines = ['new-app-key-1', 'new-app-secret-1', 'new-consumer-1'], bool $interactive = false): CommandTester
    {
        $tester = new CommandTester(new HostOvhCredentialsCommand($this->fx->settings, $this->fx->store, $this->fx->system));
        $tester->setInputs($lines);
        $tester->execute($options, ['capture_stderr_separately' => true, 'interactive' => $interactive]);

        return $tester;
    }

    private function completeDnsHost(): void
    {
        $host = $this->fx->readyHost(TlsMode::OvhDns);
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);
    }

    public function testRotationStoresTheValuesAndAppliesCaddyWithTheEndpointInTheCaddyfile(): void
    {
        $this->completeDnsHost();
        $caddyfile = $env = '';
        $this->fx->probe = function (string $event) use (&$caddyfile, &$env): void {
            if ('apply:caddy' === $event) {
                $caddyfile = (string) file_get_contents($this->fx->paths->stagingFile('caddy', 'Caddyfile'));
                $env = (string) file_get_contents($this->fx->paths->stagingFile('caddy', 'env'));
            }
        };

        $tester = $this->rotate(['--endpoint' => 'ovh-ca']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame('new-app-secret-1', $this->fx->store->getFleetWide('ovh_application_secret'));
        self::assertSame('ovh-ca', $this->fx->store->getFleetWide('ovh_endpoint'));
        self::assertContains('apply:caddy', $this->fx->events);
        self::assertStringContainsString('        endpoint ovh-ca', $caddyfile);
        self::assertStringContainsString('OVH_CONSUMER_KEY=new-consumer-1', $env);
        self::assertStringNotContainsString('ENDPOINT', $env);
        self::assertSame([], $this->fx->staged(), 'nothing stays staged');
        self::assertStringNotContainsString('new-app-secret-1', $tester->getDisplay().$tester->getErrorOutput());
    }

    public function testHiddenPromptsWorkInteractively(): void
    {
        $this->completeDnsHost();

        $tester = $this->rotate(lines: ['prompt-app-key-1', 'prompt-app-secret-1', 'prompt-consumer-1'], interactive: true);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame('prompt-app-key-1', $this->fx->store->getFleetWide('ovh_application_key'));
        self::assertSame('ovh-eu', $this->fx->store->getFleetWide('ovh_endpoint'), 'the stored endpoint is kept');
    }

    public function testAPartialOrInvalidSetStoresNothing(): void
    {
        $this->completeDnsHost();

        $missing = $this->rotate(lines: ['new-app-key-1', 'new-app-secret-1']);
        $badValue = $this->rotate(lines: ['new-app-key-1', 'has a space 123', 'new-consumer-1']);
        $badEndpoint = $this->rotate(['--endpoint' => 'ovh-eu.evil.example']);

        foreach ([$missing, $badValue, $badEndpoint] as $tester) {
            self::assertSame(1, $tester->getStatusCode());
        }
        self::assertStringContainsString('consumer key is missing', $missing->getErrorOutput());
        self::assertSame('app-key-12345678', $this->fx->store->getFleetWide('ovh_application_key'), 'the old values stay');
        self::assertNotContains('apply:caddy', $this->fx->events);
    }

    public function testRefusedOutsideDnsMode(): void
    {
        $this->fx->host(TlsMode::Http01);

        $tester = $this->rotate();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('does not use the OVH DNS challenge', $tester->getErrorOutput());
        self::assertFalse($this->fx->store->hasFleetWide('ovh_application_key'));
    }

    public function testAFailedInstallReportsItAndKeepsTheStoredValues(): void
    {
        $this->completeDnsHost();
        $this->fx->applyFails['caddy'] = new \RuntimeException('caddy validate rejected the staged Caddyfile');

        $tester = $this->rotate();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('could not be installed', $tester->getErrorOutput());
        self::assertSame('new-app-key-1', $this->fx->store->getFleetWide('ovh_application_key'));
        self::assertSame([], $this->fx->staged());
    }

    public function testBeforeSetupIsCompleteTheValuesAreOnlyStored(): void
    {
        $this->fx->readyHost(TlsMode::OvhDns);

        $tester = $this->rotate();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('Finish step', $tester->getDisplay());
        self::assertNotContains('apply:caddy', $this->fx->events);
    }

    public function testWithoutAStoredEndpointTheOptionIsRequired(): void
    {
        $host = $this->fx->host(TlsMode::OvhDns);
        $host->markSetupComplete($this->fx->clock->now());
        $this->fx->settings->save($host);

        $refused = $this->rotate();
        $accepted = $this->rotate(['--endpoint' => 'ovh-us']);

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('--endpoint', $refused->getErrorOutput());
        self::assertSame(0, $accepted->getStatusCode(), $accepted->getErrorOutput());
    }
}
