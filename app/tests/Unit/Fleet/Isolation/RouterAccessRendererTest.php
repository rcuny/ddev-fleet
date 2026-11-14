<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Isolation;

use App\Fleet\Isolation\RouterAccessEntry;
use App\Fleet\Isolation\RouterAccessRenderer;
use PHPUnit\Framework\TestCase;

/** FLE-326: the router-path allow-list file. */
final class RouterAccessRendererTest extends TestCase
{
    public function testNothingToRenderIsAnEmptyFile(): void
    {
        self::assertSame('', RouterAccessRenderer::render([]));
    }

    public function testEachInstanceGetsAPlainAndATlsDenyRouterWithItsHostsAndSources(): void
    {
        $out = RouterAccessRenderer::render([
            new RouterAccessEntry('blog--main', ['blog--main.fleet.example.com', 'api-blog--main.fleet.example.com'], ['172.18.0.1', '172.18.0.9', '172.18.0.5']),
        ]);

        self::assertStringContainsString("    fleet-iso-blog--main:\n", $out);
        self::assertStringContainsString("    fleet-iso-blog--main-tls:\n      rule:", $out);
        self::assertStringContainsString('(Host(`blog--main.fleet.example.com`) || Host(`api-blog--main.fleet.example.com`)) && !(ClientIP(`127.0.0.1/32`) || ClientIP(`172.18.0.1/32`) || ClientIP(`172.18.0.5/32`) || ClientIP(`172.18.0.9/32`))', $out);
        self::assertSame(2, substr_count($out, 'service: noop@internal'));
        self::assertSame(1, substr_count($out, 'tls: {}'));
        self::assertStringNotContainsString('tcp:', $out);
    }

    public function testTheHttpDenyRoutersAreBoundToTheGivenEntryPointsOnly(): void
    {
        $entry = new RouterAccessEntry('a', ['a.x.test'], ['172.18.0.1']);

        $bound = RouterAccessRenderer::render([$entry], ['http-8080', 'http-8443', 'bad name`', 'http-8080']);

        // Both HTTP routers (plain and tls) are bound to the given HTTP entry points; no TCP router exists.
        self::assertSame(2, substr_count($bound, "      entryPoints: [http-8080, http-8443]\n"));
        self::assertStringNotContainsString('tcp:', $bound);
        self::assertStringNotContainsString('bad name', $bound);
        self::assertStringNotContainsString('fleet-mirror', $bound);
        // Unknown entry points: no restriction (the safe side for the deny, not for the own name).
        self::assertStringNotContainsString('entryPoints: [http', RouterAccessRenderer::render([$entry], []));
    }

    public function testInvalidNamesAndAddressesNeverReachTheFile(): void
    {
        $out = RouterAccessRenderer::render([
            new RouterAccessEntry('ok', ['good.example.com', 'bad`) || Host(`x', 'UPPER.example.com'], ['172.18.0.1', '1.2.3.4`)', '::1', 'junk']),
            new RouterAccessEntry('bad id', ['a.example.com'], []),
            new RouterAccessEntry('nohost', ['not a host'], []),
        ]);

        self::assertStringNotContainsString('bad`', $out);
        self::assertStringNotContainsString('UPPER', $out);
        self::assertStringNotContainsString('junk', $out);
        self::assertStringNotContainsString('::1', $out);
        self::assertStringNotContainsString('bad id', $out);
        self::assertStringNotContainsString('nohost', $out);
        self::assertStringContainsString('Host(`good.example.com`)', $out);
    }
}
