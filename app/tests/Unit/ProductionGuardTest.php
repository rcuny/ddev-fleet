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
}
