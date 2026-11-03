<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ProductionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionGuardTest extends TestCase
{
    public function testAcceptsASafeProdConfiguration(): void
    {
        ProductionGuard::assertSafe('prod', ['APP_SECRET' => 'a-long-random-secret', 'FLEET_RUNNER' => 'process']);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptySecrets(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'not a string' => [false];
    }

    #[DataProvider('emptySecrets')]
    public function testProdRefusesAnEmptyAppSecret(mixed $secret): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('APP_SECRET is empty');

        ProductionGuard::assertSafe('prod', ['APP_SECRET' => $secret, 'FLEET_RUNNER' => 'process']);
    }

    #[DataProvider('recordingSpellings')]
    public function testProdRefusesTheRecordingRunner(string $runner): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FLEET_RUNNER=recording');

        ProductionGuard::assertSafe('prod', ['APP_SECRET' => 'secret', 'FLEET_RUNNER' => $runner]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordingSpellings(): iterable
    {
        yield 'plain' => ['recording'];
        yield 'upper case' => ['RECORDING'];
        yield 'padded' => [' recording '];
    }

    public function testDevAndTestAreNotGuarded(): void
    {
        ProductionGuard::assertSafe('dev', ['APP_SECRET' => '', 'FLEET_RUNNER' => 'recording']);
        ProductionGuard::assertSafe('test', []);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function safe(array $override = []): array
    {
        return $override + [
            'APP_SECRET' => 'a-long-random-secret',
            'FLEET_RUNNER' => 'process',
            'PROJECT_DIR' => '/opt/ddev-fleet/app',
            'FLEET_HOME' => '/srv/fleet',
            'FLEET_PROXY_SECRET' => str_repeat('p', 40),
            'FLEET_TRUSTED_PROXIES' => '',
        ];
    }

    public function testAcceptsAFullySetUpProdConfiguration(): void
    {
        ProductionGuard::assertSafe('prod', self::safe());

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homesInsideTheProject(): iterable
    {
        yield 'the dotenv default, unresolved' => ['%kernel.project_dir%/var/fleet-home'];
        yield 'resolved under the project' => ['/opt/ddev-fleet/app/var/fleet-home'];
        yield 'the project itself' => ['/opt/ddev-fleet/app'];
        yield 'trailing slash' => ['/opt/ddev-fleet/app/var/'];
    }

    #[DataProvider('homesInsideTheProject')]
    public function testProdRefusesAFleetHomeInsideTheProject(string $home): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FLEET_HOME');

        ProductionGuard::assertSafe('prod', self::safe(['FLEET_HOME' => $home]));
    }

    public function testASiblingDirectoryWithTheSamePrefixIsNotInsideTheProject(): void
    {
        ProductionGuard::assertSafe('prod', self::safe(['FLEET_HOME' => '/opt/ddev-fleet/app-data']));

        $this->expectNotToPerformAssertions();
    }

    public function testProdRefusesTrustedProxiesWhenAProxySecretIsSet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FLEET_TRUSTED_PROXIES');

        ProductionGuard::assertSafe('prod', self::safe(['FLEET_TRUSTED_PROXIES' => '127.0.0.1,::1']));
    }

    public function testTrustedProxiesWithoutAProxySecretStillBoot(): void
    {
        ProductionGuard::assertSafe('prod', self::safe(['FLEET_PROXY_SECRET' => '', 'FLEET_TRUSTED_PROXIES' => '127.0.0.1']));
        ProductionGuard::assertSafe('prod', self::safe(['FLEET_TRUSTED_PROXIES' => ' , ']));

        $this->expectNotToPerformAssertions();
    }

    public function testAShortProxySecretIsLoggedNotRefused(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'guard-log');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            ProductionGuard::assertSafe('prod', self::safe(['FLEET_PROXY_SECRET' => 'short']));
            ProductionGuard::assertSafe('prod', self::safe(['FLEET_PROXY_SECRET' => '']));
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', false === $previous ? '' : $previous);
            @unlink($log);
        }

        self::assertSame(1, substr_count($logged, 'FLEET_PROXY_SECRET'), 'only the non-empty short secret is reported');
        self::assertStringNotContainsString('short', str_replace('shorter', '', $logged), 'the secret value is never logged');
    }

    public function testNewGuardsDoNotApplyOutsideProd(): void
    {
        ProductionGuard::assertSafe('dev', self::safe(['FLEET_HOME' => '%kernel.project_dir%/var/fleet-home', 'FLEET_TRUSTED_PROXIES' => '127.0.0.1']));

        $this->expectNotToPerformAssertions();
    }
}
