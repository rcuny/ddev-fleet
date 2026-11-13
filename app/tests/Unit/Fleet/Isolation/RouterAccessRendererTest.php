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
        self::assertSame('', RouterAccessRenderer::render([], true));
    }

    public function testEachInstanceGetsAPlainAndATlsDenyRouterWithItsHostsAndSources(): void
    {
        $out = RouterAccessRenderer::render([
            new RouterAccessEntry('blog--main', ['blog--main.fleet.example.com', 'api-blog--main.fleet.example.com'], ['172.18.0.1', '172.18.0.9', '172.18.0.5']),
        ], false);

        self::assertStringContainsString("    fleet-iso-blog--main:\n", $out);
        self::assertStringContainsString("    fleet-iso-blog--main-tls:\n      rule:", $out);
        self::assertStringContainsString('(Host(`blog--main.fleet.example.com`) || Host(`api-blog--main.fleet.example.com`)) && !(ClientIP(`127.0.0.1/32`) || ClientIP(`172.18.0.1/32`) || ClientIP(`172.18.0.5/32`) || ClientIP(`172.18.0.9/32`))', $out);
        self::assertSame(2, substr_count($out, 'service: noop@internal'));
        self::assertSame(1, substr_count($out, 'tls: {}'));
        self::assertStringNotContainsString('tcp:', $out);
    }

    public function testTheSiteMirrorAddsASniRouterPerInstanceAndOneBlackholeService(): void
    {
        $out = RouterAccessRenderer::render([new RouterAccessEntry('a', ['a.x.test'], ['172.18.0.1'])], true);

        self::assertStringContainsString("tcp:\n  routers:\n    fleet-iso-a:\n      entryPoints: [fleet-mirror-443]\n", $out);
        self::assertStringContainsString('HostSNI(`a.x.test`)', $out);
        self::assertStringContainsString('passthrough: true', $out);
        self::assertStringContainsString('address: "127.0.0.1:9"', $out);
    }

    public function testInvalidNamesAndAddressesNeverReachTheFile(): void
    {
        $out = RouterAccessRenderer::render([
            new RouterAccessEntry('ok', ['good.example.com', 'bad`) || Host(`x', 'UPPER.example.com'], ['172.18.0.1', '1.2.3.4`)', '::1', 'junk']),
            new RouterAccessEntry('bad id', ['a.example.com'], []),
            new RouterAccessEntry('nohost', ['not a host'], []),
        ], false);

        self::assertStringNotContainsString('bad`', $out);
        self::assertStringNotContainsString('UPPER', $out);
        self::assertStringNotContainsString('junk', $out);
        self::assertStringNotContainsString('::1', $out);
        self::assertStringNotContainsString('bad id', $out);
        self::assertStringNotContainsString('nohost', $out);
        self::assertStringContainsString('Host(`good.example.com`)', $out);
    }
}
