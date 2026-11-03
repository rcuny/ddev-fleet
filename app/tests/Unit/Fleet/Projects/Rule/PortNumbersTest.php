<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\PortNumbers;
use App\Fleet\System\CaddyfileRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortNumbersTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function badPublic(): iterable
    {
        foreach ([0, 65536, -1, '9108', 9108.0, null, true, 22, 80, 443, 8765, 2019, 3000, 9091, 8080, 8443] as $i => $port) {
            yield '#'.$i.' '.var_export($port, true) => [$port];
        }
    }

    #[DataProvider('badPublic')]
    public function testPublicRejects(mixed $port): void
    {
        self::assertIsString(PortNumbers::publicError($port));
    }

    public function testPublicAccepts(): void
    {
        foreach ([9108, 65535, 1, 8081, 8444, 1024] as $port) {
            self::assertNull(PortNumbers::publicError($port), (string) $port);
        }
    }

    public function testRouterAccepts(): void
    {
        self::assertNull(PortNumbers::routerError(8108, 9108));
        self::assertNull(PortNumbers::routerError(65535, 9108));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function badRouter(): iterable
    {
        foreach ([[8080, 9108], [8443, 9108], [22, 9108], [2019, 9108], [3000, 9108], [9091, 9108], [8765, 9108], [9108, 9108], [0, 9108], [65536, 9108], ['8108', 9108], [null, 9108]] as $i => [$router, $public]) {
            yield '#'.$i.' '.var_export($router, true) => [$router, $public];
        }
    }

    #[DataProvider('badRouter')]
    public function testRouterRejects(mixed $router, mixed $public): void
    {
        self::assertIsString(PortNumbers::routerError($router, $public));
    }

    public function testRouterIsNotComparedWithAnInvalidPublicPort(): void
    {
        self::assertNull(PortNumbers::routerError(8108, null));
    }

    public function testEveryListenerTheFleetBindsItselfIsReserved(): void
    {
        foreach ([CaddyfileRenderer::HUB, CaddyfileRenderer::AUTHELIA] as $listener) {
            $port = (int) substr($listener, (int) strrpos($listener, ':') + 1);
            self::assertGreaterThan(0, $port);
            self::assertContains($port, PortNumbers::RESERVED, $listener);
        }
        self::assertContains(2019, PortNumbers::RESERVED, 'the Caddy admin endpoint');
        self::assertSame([22, 80, 443, 8765, 2019, 3000, 9091], PortNumbers::RESERVED);
        self::assertSame([8080, 8443], PortNumbers::ROUTER_RESERVED);
    }
}
