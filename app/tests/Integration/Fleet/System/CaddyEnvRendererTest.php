<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\System\CaddyEnvRenderer;
use App\Tests\Support\FleetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class CaddyEnvRendererTest extends KernelTestCase
{
    use FleetDatabase;

    private const string PROXY_SECRET = 'proxy-secret-0123456789abcdef-0123456789';

    private SecretStore $store;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $this->store = new SecretStore(
            new SecretRepository($em),
            new SecretBox(new SecretKeyFile(self::getContainer()->get(FleetPaths::class))),
            new MockClock('2026-10-19 10:00:00 UTC'),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private static function host(TlsMode $mode): HostSettings
    {
        $host = new HostSettings('fleet.example.com');
        $host->changeTlsMode($mode);

        return $host;
    }

    private function storeOvh(): void
    {
        $this->store->putFleetWide('ovh_endpoint', 'ovh-eu', 'admin');
        $this->store->putFleetWide('ovh_application_key', 'app-key-12345678', 'admin');
        $this->store->putFleetWide('ovh_application_secret', 'app-secret-12345678', 'admin');
        $this->store->putFleetWide('ovh_consumer_key', 'consumer-key-12345678', 'admin');
    }

    public function testHttp01ContainsOnlyTheProxySecret(): void
    {
        $this->storeOvh();

        $out = new CaddyEnvRenderer($this->store, self::PROXY_SECRET)->render(self::host(TlsMode::Http01));

        self::assertSame('FLEET_PROXY_SECRET='.self::PROXY_SECRET."\n", $out);
    }

    public function testOvhValuesComeFromTheSecretStore(): void
    {
        $this->storeOvh();

        $out = new CaddyEnvRenderer($this->store, self::PROXY_SECRET)->render(self::host(TlsMode::OvhDns));

        self::assertSame(
            'FLEET_PROXY_SECRET='.self::PROXY_SECRET."\nOVH_ENDPOINT=ovh-eu\nOVH_APPLICATION_KEY=app-key-12345678\nOVH_APPLICATION_SECRET=app-secret-12345678\nOVH_CONSUMER_KEY=consumer-key-12345678\n",
            $out,
        );
    }

    public function testMissingOvhSecretFails(): void
    {
        $this->storeOvh();
        $this->store->forgetFleetWide('ovh_consumer_key');

        try {
            new CaddyEnvRenderer($this->store, self::PROXY_SECRET)->render(self::host(TlsMode::OvhDns));
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ovh_consumer_key', $e->getMessage());
            self::assertStringNotContainsString('app-secret', $e->getMessage());
        }
    }

    public function testNoOvhSecretsAtAllFails(): void
    {
        $this->expectException(\RuntimeException::class);

        new CaddyEnvRenderer($this->store, self::PROXY_SECRET)->render(self::host(TlsMode::OvhDns));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeStoredValues(): iterable
    {
        yield 'newline in a key' => ['ovh_application_key', "app-key-1234\nLD_PRELOAD=/tmp/x.so"];
        yield 'space in a secret' => ['ovh_application_secret', 'app secret 12345678'];
        yield 'quote in the consumer key' => ['ovh_consumer_key', 'consumer"key-12345678'];
        yield 'hash in a key' => ['ovh_application_key', 'app-key-1234#comment'];
        yield 'brace in a secret' => ['ovh_application_secret', 'app-secret-}{1234'];
        yield 'unknown endpoint' => ['ovh_endpoint', 'ovh-eu.evil.example'];
        yield 'endpoint with newline' => ['ovh_endpoint', "ovh-eu\nOVH_X=1"];
    }

    #[DataProvider('unsafeStoredValues')]
    public function testRefusesAStoredValueThatCouldInjectAnEnvironmentLine(string $name, string $value): void
    {
        $this->storeOvh();
        $this->store->putFleetWide($name, $value, 'admin');

        try {
            new CaddyEnvRenderer($this->store, self::PROXY_SECRET)->render(self::host(TlsMode::OvhDns));
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(trim($value), $e->getMessage());
            self::assertStringNotContainsString('LD_PRELOAD', $e->getMessage());
        }
    }

    public function testRefusesAMissingOrShortProxySecret(): void
    {
        foreach (['', 'short', "line\nbreak-0123456789abcdef-0123456789"] as $secret) {
            try {
                new CaddyEnvRenderer($this->store, $secret)->render(self::host(TlsMode::Http01));
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
