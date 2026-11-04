<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Setup\SetupCookie;
use App\Tests\Support\WizardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The refusal page of a wrong setup link, spelling by spelling, and the throttled page (FLE-162, test diet 5/7; the
 * browser spec kept only the cookie round trip). SetupAccessTest holds the identical-body, expiry and throttle-count checks.
 */
final class SetupLinkRefusalTest extends WizardTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongLinks(): iterable
    {
        yield 'a wrong token' => ['/setup?token=not-the-token'];
        yield 'an empty token' => ['/setup?token='];
        yield 'an array token' => ['/setup?token[]=x'];
        yield 'a 200-character token' => ['/setup?token='.str_repeat('a', 200)];
        yield 'no token at all' => ['/setup?other=1'];
        yield 'the token plus a character' => ['/setup?token={token}x'];
        yield 'the token without its first character' => ['/setup?token={token-1}'];
        yield 'the token in capitals' => ['/setup?token={TOKEN}'];
        yield 'the token behind a space' => ['/setup?token=%20{token}'];
    }

    #[DataProvider('wrongLinks')]
    public function testAWrongLinkGetsTheOneFixedRefusalAndNoSession(string $template): void
    {
        $uri = str_replace(['{token-1}', '{TOKEN}', '{token}'], [substr($this->token, 1), strtoupper($this->token), $this->token], $template);

        $this->client->request('GET', self::BASE.$uri);

        self::assertResponseStatusCodeSame(403);
        $body = html_entity_decode($this->body());
        self::assertStringContainsString('Setup link needed', $body);
        self::assertStringContainsString('This page needs the one-time setup link, and it may have expired.', $body);
        self::assertStringContainsString('fleet:setup:token', $body);
        self::assertStringNotContainsString('Create the administrator', $body);
        self::assertNull($this->client->getCookieJar()->get(SetupCookie::NAME, '/', 'localhost'));
    }

    public function testTheThrottledPageSaysSoAndOnlyTheExchangeIsCounted(): void
    {
        for ($i = 0; $i < 15; ++$i) {
            $this->client->request('GET', self::BASE.'/setup?token=guess-'.$i);
            if (429 === $this->client->getResponse()->getStatusCode()) {
                break;
            }
        }
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(5, $i, 'a handful of guesses is allowed first');

        $this->client->request('GET', self::BASE.'/setup?token='.$this->token);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('900', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('Too many attempts', $this->body());
        self::assertStringContainsString('Wait a few minutes, then open the setup link again.', $this->body());
        self::assertNull($this->client->getCookieJar()->get(SetupCookie::NAME, '/', 'localhost'));

        $this->client->request('GET', self::BASE.'/setup/admin');
        self::assertResponseStatusCodeSame(403, 'a wizard page without a session is still the plain 403');
    }
}
