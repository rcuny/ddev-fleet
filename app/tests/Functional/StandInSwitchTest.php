<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StandInSwitchTest extends WebTestCase
{
    public function testSwitchSetsTheCookieAndRedirectsHome(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/member');

        self::assertResponseRedirects('/', 302);
        $cookie = $client->getResponse()->headers->getCookies()[0];
        self::assertSame('fleet_stand_in_role', $cookie->getName());
        self::assertSame('member', $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
    }

    public function testSwitchedRoleIsUsedOnTheNextRequest(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/member');
        $client->request('GET', '/admin/x');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/_dev/stand-in/none');
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(401);

        // "none" must not lock the developer out of the switch.
        $client->request('GET', '/_dev/stand-in/admin');
        self::assertResponseRedirects('/');
        $client->request('GET', '/admin/x');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNextPathIsHonoured(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/member?next=/instances');

        self::assertResponseRedirects('/instances', 302);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNext(): iterable
    {
        yield 'protocol relative' => ['//evil.example'];
        yield 'absolute' => ['https://evil.example'];
        yield 'crlf' => ["/\r\nX:y"];
        yield 'tab' => ["/\t/evil.example"];
        yield 'vertical tab' => ["/\x0b/evil.example"];
        yield 'nul' => ["/\x00/evil.example"];
        yield 'del' => ["/\x7f/evil.example"];
        yield 'trailing control' => ["/instances\x09"];
        yield 'backslash' => ['/\\evil.example'];
        yield 'backslash inside' => ['/a\\b'];
        yield 'relative' => ['instances'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafeNext')]
    public function testUnsafeNextFallsBackToHome(string $next): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/member', ['next' => $next]);

        self::assertResponseRedirects('/', 302);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeEncodedNext(): iterable
    {
        yield 'crlf' => ['/%0d%0aX:y'];
        yield 'tab' => ['/%09/evil.example'];
        yield 'vertical tab' => ['/%0b/evil.example'];
        yield 'nul' => ['/%00/evil.example'];
    }

    #[DataProvider('unsafeEncodedNext')]
    public function testUnsafeEncodedNextFallsBackToHome(string $next): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/member?next='.$next);

        self::assertResponseRedirects('/', 302);
    }

    public function testUnknownRoleIs404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/stand-in/root');

        self::assertResponseStatusCodeSame(404);
    }
}
