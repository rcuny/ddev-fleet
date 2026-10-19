<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Fleet\System\CaddyfileRenderer;
use App\Security\TrustedProxyChecker;
use App\Tests\Support\SystemTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaddyfileRendererTest extends TestCase
{
    private static function renderer(string $projectDir = '/opt/ddev-fleet/app', string $caddyDir = '/etc/caddy/fleet'): CaddyfileRenderer
    {
        return new CaddyfileRenderer(SystemTemplates::twig(), new FleetPaths('/srv/fleet', $caddyDir), $projectDir);
    }

    private static function host(string $domain = 'fleet.example.com', ?string $email = null, TlsMode $mode = TlsMode::Http01): HostSettings
    {
        $host = new HostSettings($domain);
        $host->changeAcmeEmail($email);
        $host->changeTlsMode($mode);

        return $host;
    }

    public function testStripsEveryRemoteHeaderSpellingBeforeTheGate(): void
    {
        $out = self::renderer()->render(self::host());

        $routeAt = strpos($out, "    route {\n");
        $importAt = strpos($out, 'import ');
        self::assertNotFalse($routeAt);
        self::assertNotFalse($importAt);

        foreach (['User', 'Groups', 'Name', 'Email'] as $field) {
            foreach (['-', '_'] as $separator) {
                $line = '        request_header -Remote'.$separator.$field."\n";
                $at = strpos($out, $line);
                self::assertNotFalse($at, $line);
                self::assertGreaterThan($routeAt, $at, 'inside the route');
                self::assertLessThan($importAt, $at, 'before the gate import');
            }
        }
        self::assertSame(8, substr_count($out, 'request_header -'), 'nothing else is stripped or added');
        self::assertSame(1, substr_count($out, "route {\n"), 'one route keeps the written order');
    }

    public function testNothingIsExemptFromTheStrip(): void
    {
        $out = self::renderer()->render(self::host());

        // The strip sits in front of the gate import and every handler, so /health, /setup* and /hooks/* get it too.
        $stripAt = strpos($out, 'request_header -Remote-User');
        foreach (['import ', 'handle ', '@hooks', 'php_fastcgi', 'file_server'] as $needle) {
            $at = strpos($out, $needle);
            self::assertNotFalse($at, $needle);
            self::assertLessThan($at, $stripAt, $needle);
        }
        self::assertStringNotContainsString('@protected', $out);
        self::assertDoesNotMatchRegularExpression('/request_header\s+@/', $out, 'no matcher narrows the strip');
    }

    public function testGateImportIsALiteralPathAfterTheStrip(): void
    {
        $out = self::renderer(caddyDir: '/etc/caddy/fleet')->render(self::host());

        self::assertStringContainsString("        import /etc/caddy/fleet/gate.conf\n", $out);
        self::assertSame(2, substr_count($out, 'import '), 'the gate and the instance sites');
        self::assertSame(1, substr_count($out, '*.conf'), 'the gate is never a glob (a silent no-op when nothing matches); only the instance sites are');
    }

    public function testFastcgiParamCarriesTheProxySecretPlaceholder(): void
    {
        $out = self::renderer()->render(self::host());

        // The parameter name is rendered from the constant the app reads; the secret itself is only a placeholder.
        self::assertStringContainsString('env '.TrustedProxyChecker::PROXY_PARAM.' {env.FLEET_PROXY_SECRET}', $out);
        self::assertStringNotContainsString('{$', $out, 'no parse-time substitution: validate would see empty values');

        $template = (string) file_get_contents(SystemTemplates::dir().'/system/Caddyfile.twig');
        self::assertStringNotContainsString(TrustedProxyChecker::PROXY_PARAM, $template, 'the template must take the name from the constant');
    }

    public function testMercureProxyDisablesBuffering(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertStringContainsString("handle /.well-known/mercure* {\n            reverse_proxy ".CaddyfileRenderer::HUB." {\n                flush_interval -1\n            }\n        }\n", $out);
        self::assertSame('127.0.0.1:3000', CaddyfileRenderer::HUB);
    }

    public function testImportsTheInstanceSnippets(): void
    {
        $out = self::renderer(caddyDir: '/etc/caddy/fleet')->render(self::host());

        self::assertStringContainsString("\nimport /etc/caddy/fleet/instances/*.conf\n", $out);
        self::assertGreaterThan(strpos($out, "\nauth.fleet.example.com {"), strpos($out, 'import /etc/caddy/fleet/instances/'), 'the import is a top-level line after the fixed sites');
    }

    public function testAuthSiteProxiesAuthelia(): void
    {
        $out = self::renderer()->render(self::host('fleet.example.com'));

        self::assertStringContainsString("auth.fleet.example.com {\n    header Strict-Transport-Security \"max-age=31536000\"\n\n    reverse_proxy 127.0.0.1:9091\n}\n", $out);
    }

    public function testOvhBlockOnlyInOvhMode(): void
    {
        $http = self::renderer()->render(self::host(mode: TlsMode::Http01));
        $ovh = self::renderer()->render(self::host(mode: TlsMode::OvhDns));

        self::assertStringNotContainsString('acme_dns', $http);
        self::assertStringNotContainsString('OVH', $http);
        self::assertStringContainsString(
            "    acme_dns ovh {\n        endpoint {file./etc/caddy/ovh/endpoint}\n        application_key {file./etc/caddy/ovh/application_key}\n        application_secret {file./etc/caddy/ovh/application_secret}\n        consumer_key {file./etc/caddy/ovh/consumer_key}\n    }\n",
            $ovh,
        );
        // Credentials live in files: no OVH value can sit in Caddy's environment, so no {env.OVH_*} placeholder remains.
        self::assertStringNotContainsString('{env.OVH', $ovh);
        self::assertStringContainsString('{env.FLEET_PROXY_SECRET}', $ovh);
    }

    public function testNoEmailLineWhenUnset(): void
    {
        $without = self::renderer()->render(self::host(email: null));
        $with = self::renderer()->render(self::host(email: 'ops@example.com'));
        $empty = self::renderer()->render(self::host(email: ''));

        self::assertStringNotContainsString('email', $without);
        self::assertStringContainsString("\n    admin localhost:2019\n    email ops@example.com\n", $with);
        self::assertSame($without, $empty);
        // The global block is always there: it carries the admin pin and the log filter.
        self::assertStringContainsString("\n    admin localhost:2019\n\n    # Caddy's error log", $without);
    }

    public function testTheSetupTokenIsFilteredOutOfEveryLogLine(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertStringContainsString("        format filter {\n            wrap json\n            fields {\n                request>uri query {\n                    delete token\n", $out);
        self::assertStringNotContainsString("\n    log {", substr($out, (int) strpos($out, 'fleet.example.com {')), 'no access log: every request would otherwise be recorded');
    }

    public function testHooksBodyCapped(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertStringContainsString("        @hooks path /hooks/*\n        request_body @hooks {\n            max_size 1MB\n        }\n", $out);
    }

    public function testSocketMatchesConstant(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertSame('/run/php/fleet.sock', CaddyfileRenderer::PHP_SOCKET);
        self::assertStringContainsString('php_fastcgi unix//run/php/fleet.sock {', $out);
    }

    public function testRootPointsAtThePublicDirectoryOfTheProject(): void
    {
        $out = self::renderer(projectDir: '/opt/ddev-fleet/app')->render(self::host());

        self::assertStringContainsString("        root * /opt/ddev-fleet/app/public\n", $out);
        self::assertLessThan((int) strpos($out, 'php_fastcgi'), (int) strpos($out, 'root *'));
    }

    public function testSecurityHeaders(): void
    {
        $out = self::renderer()->render(self::host());

        foreach (['X-Content-Type-Options nosniff', 'X-Frame-Options DENY', 'X-Robots-Tag noindex', '-Server'] as $line) {
            self::assertStringContainsString("        {$line}\n", $out);
        }
    }

    public function testTheCaddyAdminEndpointIsPinnedToLoopbackInTheGlobalBlock(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertSame(1, substr_count($out, "\n    admin "), 'one admin directive');
        self::assertStringContainsString("\n    admin localhost:2019\n", $out);
        // It sits in the global block, i.e. before the first site block.
        self::assertLessThan((int) strpos($out, "\nfleet.example.com {"), (int) strpos($out, 'admin localhost:2019'));
    }

    public function testHstsIsSetOnBothSitesWithoutSubdomainsOrPreload(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertSame(2, substr_count($out, 'Strict-Transport-Security "max-age=31536000"'), 'the domain and auth.<domain>');
        self::assertStringNotContainsString('includeSubDomains', $out);
        self::assertStringNotContainsString('preload', $out);

        $authAt = (int) strpos($out, "\nauth.fleet.example.com {");
        $domainAt = (int) strpos($out, "\nfleet.example.com {");
        $hsts = [];
        preg_match_all('/Strict-Transport-Security "max-age=31536000"/', $out, $hsts, \PREG_OFFSET_CAPTURE);
        self::assertGreaterThan($domainAt, $hsts[0][0][1]);
        self::assertLessThan($authAt, $hsts[0][0][1]);
        self::assertGreaterThan($authAt, $hsts[0][1][1]);
    }

    public function testReferrerPolicyIsOnlyADefaultSoTheAppsNoReferrerOnSetupWins(): void
    {
        $out = self::renderer()->render(self::host());

        self::assertSame(1, substr_count($out, 'Referrer-Policy'), 'one directive');
        self::assertStringContainsString("        ?Referrer-Policy same-origin\n", $out, '`?` sets the value only when the response has none');
        self::assertStringNotContainsString("\n        Referrer-Policy", $out, 'an unconditional set would override the app');
    }

    public function testNoCredentialValueIsEverRendered(): void
    {
        $out = self::renderer()->render(self::host(mode: TlsMode::OvhDns));
        $config = implode("\n", array_filter(explode("\n", $out), static fn (string $line): bool => !str_starts_with($line, '#')));

        self::assertDoesNotMatchRegularExpression('/application_secret\s+(?!\{file\.)/', $config);
        self::assertSame(4, substr_count($config, '{file./etc/caddy/ovh/'), 'four OVH placeholders, each reading its own file');
        self::assertSame(1, substr_count($config, '{env.'), 'the proxy secret is the only environment placeholder');
    }

    public function testGolden(): void
    {
        $expected = <<<'CADDY'
            # Generated by Fleet and installed by `apply caddy`; edits are overwritten. Credentials never appear here: the proxy
            # secret is an {env.X} placeholder (Caddy's environment file), the DNS-challenge credentials are {file.X} placeholders that point
            # at root:caddy 0640 files, read each time Caddy loads its configuration (a reload picks up rotated values).
            {
                # Pinned, not left to Caddy's default, so the admin endpoint the app reloads through (CaddyReloader) cannot drift to
                # another address. Loopback only: nothing off-host can reach it.
                admin localhost:2019
                email ops@example.com
                acme_dns ovh {
                    endpoint {file./etc/caddy/ovh/endpoint}
                    application_key {file./etc/caddy/ovh/application_key}
                    application_secret {file./etc/caddy/ovh/application_secret}
                    consumer_key {file./etc/caddy/ovh/consumer_key}
                }

                # Caddy's error log (a 502 while php-fpm restarts, say) records the full request URI. The setup link carries its
                # token in the query string, so the query parameter is removed from every log line.
                log {
                    format filter {
                        wrap json
                        fields {
                            request>uri query {
                                delete token
                            }
                        }
                    }
                }
            }

            fleet.example.com {
                header {
                    X-Content-Type-Options nosniff
                    Strict-Transport-Security "max-age=31536000"
                    # Default only (`?`): the app's own stricter `no-referrer` on /setup, which carries the token, must win.
                    ?Referrer-Policy same-origin
                    X-Frame-Options DENY
                    X-Robots-Tag noindex
                    -Server
                }

                # One route keeps the written order: Caddy would otherwise sort the directives and could run the header strip
                # after forward_auth, deleting the identity forward_auth just copied. Every Remote-*/Remote_* spelling is removed
                # first, on every path (health, setup and hooks included), so only the gate can introduce an identity.
                route {
                    request_header -Remote-User
                    request_header -Remote_User
                    request_header -Remote-Groups
                    request_header -Remote_Groups
                    request_header -Remote-Name
                    request_header -Remote_Name
                    request_header -Remote-Email
                    request_header -Remote_Email

                    import /etc/caddy/fleet/gate.conf

                    handle /.well-known/mercure* {
                        reverse_proxy 127.0.0.1:3000 {
                            flush_interval -1
                        }
                    }

                    @hooks path /hooks/*
                    request_body @hooks {
                        max_size 1MB
                    }

                    root * /opt/ddev-fleet/app/public
                    php_fastcgi unix//run/php/fleet.sock {
                        env FLEET_PROXY_AUTH {env.FLEET_PROXY_SECRET}
                    }
                    file_server
                }
            }

            auth.fleet.example.com {
                header Strict-Transport-Security "max-age=31536000"

                reverse_proxy 127.0.0.1:9091
            }

            # One site per instance, written by the worker as `<id>.conf`. An empty directory is fine.
            import /etc/caddy/fleet/instances/*.conf

            CADDY;

        self::assertSame($expected, self::renderer()->render(self::host('fleet.example.com', 'ops@example.com', TlsMode::OvhDns)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectedDomains(): iterable
    {
        foreach (['newline' => "a.example.com\nrespond 200", 'brace' => 'a.example.com}', 'open brace' => 'a{.example.com', 'hash' => 'a.example.com#', 'quote' => 'a".example.com', 'space' => 'a.example.com b.example.com', 'semicolon' => 'a.example.com;', 'wildcard' => '*.example.com', 'port' => 'a.example.com:8080', 'ip' => '203.0.113.7', 'single label' => 'localhost', 'uppercase' => 'A.example.com', 'empty' => '', 'nul' => "a.example.com\0", 'backtick' => 'a`.example.com', 'dollar' => '$a.example.com', 'trailing dot' => 'a.example.com.', 'double dot' => 'a..example.com', 'placeholder' => '{env.X}.example.com'] as $label => $value) {
            yield $label => [$value];
        }
    }

    #[DataProvider('injectedDomains')]
    public function testRefusesADomainThatCouldInjectDirectives(string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::renderer()->render(self::host($domain));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectedEmails(): iterable
    {
        foreach (['newline' => "a@example.com\nrespond 200", 'brace' => 'a@example.com}', 'open brace' => '{a}@example.com', 'hash' => 'a#b@example.com', 'quote' => '"a b"@example.com', 'space' => 'a b@example.com', 'two' => 'a@example.com b@example.com', 'angle' => 'A <a@example.com>', 'placeholder' => '{env.X}@example.com'] as $label => $value) {
            yield $label => [$value];
        }
    }

    #[DataProvider('injectedEmails')]
    public function testRefusesAnEmailThatCouldInjectDirectives(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::renderer()->render(self::host(email: $email));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        foreach (['space' => '/opt/my app', 'brace' => '/opt/a}b', 'open brace' => '/opt/{env.X}', 'hash' => '/opt/a#b', 'quote' => '/opt/a"b', 'newline' => "/opt/a\nb"] as $label => $value) {
            yield $label => [$value];
        }
    }

    #[DataProvider('unsafePaths')]
    public function testRefusesAProjectDirectoryThatCouldInjectDirectives(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::renderer(projectDir: $path)->render(self::host());
    }

    #[DataProvider('unsafePaths')]
    public function testRefusesASnippetDirectoryThatCouldInjectDirectives(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::renderer(caddyDir: $path)->render(self::host());
    }
}
