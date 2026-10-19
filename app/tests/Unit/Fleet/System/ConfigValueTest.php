<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\System;

use App\Fleet\System\ConfigValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigValueTest extends TestCase
{
    #[DataProvider('goodDomains')]
    public function testAcceptsAFullyQualifiedDomain(string $domain): void
    {
        self::assertSame($domain, ConfigValue::domain($domain));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function goodDomains(): iterable
    {
        yield 'two labels' => ['fleet.example.com'];
        yield 'subdomain' => ['a.b.fleet.example.com'];
        yield 'hyphens and digits' => ['f-1.example-2.org'];
        yield 'punycode tld' => ['fleet.xn--p1ai'];
        yield 'dev site' => ['fleet.ddev.site'];
    }

    #[DataProvider('badDomains')]
    public function testRefusesAnythingElse(string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConfigValue::domain($domain);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDomains(): iterable
    {
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-a.example.com'];
        yield 'trailing hyphen label' => ['a-.example.com'];
        yield 'long label' => [str_repeat('a', 64).'.example.com'];
        yield 'too long' => [str_repeat('a.', 130).'com'];
        yield 'newline at the end' => ["fleet.example.com\n"];
        yield 'numeric tld' => ['fleet.123'];
        yield 'underscore' => ['fle_et.example.com'];
        yield 'url' => ['https://fleet.example.com'];
    }

    public function testEmailIsOptional(): void
    {
        self::assertNull(ConfigValue::acmeEmail(null));
        self::assertNull(ConfigValue::acmeEmail(''));
        self::assertSame('ops+fleet@example.com', ConfigValue::acmeEmail('ops+fleet@example.com'));
    }

    public function testOvhEndpointIsAnAllowlist(): void
    {
        self::assertSame('ovh-eu', ConfigValue::ovhEndpoint('ovh-eu'));
        $this->expectException(\InvalidArgumentException::class);

        ConfigValue::ovhEndpoint("ovh-eu\n}");
    }

    #[DataProvider('badCredentials')]
    public function testOvhCredentialRefusesUnsafeValuesWithoutEchoingThem(string $value): void
    {
        try {
            ConfigValue::ovhCredential('ovh_application_key', $value);
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ovh_application_key', $e->getMessage());
            self::assertStringNotContainsString(trim($value), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCredentials(): iterable
    {
        yield 'newline' => ["abcdefgh\nOVH_ENDPOINT=x"];
        yield 'brace' => ['abcdefgh}{'];
        yield 'hash' => ['abcdefgh#cmt'];
        yield 'quote' => ['abcdefgh"x'];
        yield 'single quote' => ["abcdefgh'x"];
        yield 'space' => ['abcdefgh ijk'];
        yield 'backslash' => ['abcdefgh\\x'];
        yield 'dollar' => ['abcdefgh$HOME'];
        yield 'equals' => ['abcdefgh=x'];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'nul' => ["abcdefgh\0x"];
    }

    public function testOvhCredentialAcceptsTheUsualShape(): void
    {
        self::assertSame('a1B2c3D4.e5_f6-G7', ConfigValue::ovhCredential('ovh_consumer_key', 'a1B2c3D4.e5_f6-G7'));
    }

    public function testProxySecretNeedsThirtyTwoSafeCharacters(): void
    {
        $secret = str_repeat('a1', 16);
        self::assertSame($secret, ConfigValue::proxySecret($secret));

        foreach (['', 'short', str_repeat('a', 31), $secret."\n", $secret.' x', $secret.'"', $secret.'#', $secret.'$'] as $bad) {
            try {
                ConfigValue::proxySecret($bad);
                self::fail('Expected a refusal for '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function testPathMustBeAbsoluteAndNormalised(): void
    {
        self::assertSame('/srv/fleet/authelia', ConfigValue::path('Dir', '/srv/fleet/authelia'));

        foreach (['relative/x', '/a/../b', '/a/./b', '/a b', "/a\n", "/a'b", '/a{b', '/a#b', ''] as $bad) {
            try {
                ConfigValue::path('Dir', $bad);
                self::fail('Expected a refusal for '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Characters some parsers (YAML 1.1, JavaScript, Unicode-aware line splitters) treat as a line break, besides "\n".
     * Every pattern here is ASCII-only with `/D` and no `/u`, so they are refused today; these cases pin that.
     *
     * @return iterable<string, array{string}>
     */
    public static function lineBreaks(): iterable
    {
        yield 'carriage return' => ["\r"];
        yield 'vertical tab' => ["\v"];
        yield 'form feed' => ["\f"];
        yield 'next line U+0085' => ["\u{0085}"];
        yield 'line separator U+2028' => ["\u{2028}"];
        yield 'paragraph separator U+2029' => ["\u{2029}"];
    }

    #[DataProvider('lineBreaks')]
    public function testNoValueAcceptsAnyKindOfLineBreak(string $break): void
    {
        $secret = str_repeat('a1', 16);
        $cases = [
            'domain, inside' => static fn () => ConfigValue::domain('fleet'.$break.'respond.example.com'),
            'domain, trailing' => static fn () => ConfigValue::domain('fleet.example.com'.$break),
            'email, inside' => static fn () => ConfigValue::acmeEmail('ops'.$break.'x@example.com'),
            'email, trailing' => static fn () => ConfigValue::acmeEmail('ops@example.com'.$break),
            'credential, inside' => static fn () => ConfigValue::ovhCredential('ovh_application_key', 'abcdefgh'.$break.'ijklmn'),
            'credential, trailing' => static fn () => ConfigValue::ovhCredential('ovh_application_key', 'abcdefghijkl'.$break),
            'proxy secret, inside' => static fn () => ConfigValue::proxySecret($secret.$break.$secret),
            'proxy secret, trailing' => static fn () => ConfigValue::proxySecret($secret.$break),
            'path, inside' => static fn () => ConfigValue::path('Dir', '/srv/fleet'.$break.'/x'),
            'path, trailing' => static fn () => ConfigValue::path('Dir', '/srv/fleet'.$break),
            'endpoint' => static fn () => ConfigValue::ovhEndpoint('ovh-eu'.$break),
        ];

        foreach ($cases as $label => $call) {
            try {
                $call();
                self::fail($label.' accepted '.json_encode($break));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
