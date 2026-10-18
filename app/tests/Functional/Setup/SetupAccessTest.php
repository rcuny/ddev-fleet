<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Setup\SetupCookie;
use App\Tests\Support\WizardTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;

final class SetupAccessTest extends WizardTestCase
{
    private const array STEP_PATHS = ['/setup', '/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish'];

    public function testStepPathsWithoutCookieAre403WithOneIdenticalBody(): void
    {
        $bodies = [];
        foreach (self::STEP_PATHS as $path) {
            $this->client->request('GET', self::BASE.$path);
            self::assertResponseStatusCodeSame(403, $path);
            $bodies[$path] = $this->body();
        }
        foreach (['/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish'] as $path) {
            $this->post($path, []);
            self::assertResponseStatusCodeSame(403, 'POST '.$path);
            $bodies['POST '.$path] = $this->body();
        }

        self::assertCount(1, array_unique($bodies), 'every refusal has the same body');
        self::assertStringNotContainsString($this->token, implode('', $bodies));
    }

    public function testWrongTokenIs403SameBody(): void
    {
        $this->client->request('GET', self::BASE.'/setup/admin');
        $noCookie = $this->body();

        $this->client->request('GET', self::BASE.'/setup?token='.str_repeat('a', 43));
        self::assertResponseStatusCodeSame(403);
        self::assertSame($noCookie, $this->body());
        self::assertNull($this->client->getResponse()->headers->getCookies()[0] ?? null, 'no cookie for a wrong token');

        // A token of the right length that differs in one character, and an absurdly long one.
        $this->client->request('GET', self::BASE.'/setup?token='.substr($this->token, 0, -1).'x');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::BASE.'/setup?token='.str_repeat('z', 5000));
        self::assertResponseStatusCodeSame(403);
        self::assertSame($noCookie, $this->body());
    }

    public function testAnArrayTokenIsTreatedAsMissing(): void
    {
        $this->client->request('GET', self::BASE.'/setup?token[]=x');

        self::assertResponseStatusCodeSame(403);
    }

    public function testForgedCookiesAreRefusedWithTheSameBody(): void
    {
        $this->client->request('GET', self::BASE.'/setup/admin');
        $noCookie = $this->body();

        foreach (['garbage', '9999999999.'.str_repeat('0', 64), '1.'.str_repeat('f', 64), ''] as $value) {
            $this->client->getCookieJar()->set(new BrowserCookie(SetupCookie::NAME, $value, null, '/', 'localhost', true, true));
            $this->client->request('GET', self::BASE.'/setup/admin');
            self::assertResponseStatusCodeSame(403, $value);
            self::assertSame($noCookie, $this->body());
        }
    }

    public function testRightTokenSetsStrictHttpOnlyCookieAndRedirects(): void
    {
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token, server: $this->browserServer('none'));

        self::assertResponseStatusCodeSame(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('/setup/admin', $location);
        self::assertStringNotContainsString('token', $location, 'the token does not stay in the address bar');

        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        $cookie = $cookies[0];
        self::assertSame('__Host-fleet_setup', $cookie->getName());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain(), '__Host- cookies carry no Domain');
        self::assertStringNotContainsString($this->token, (string) $cookie->getValue());
        self::assertGreaterThan(time(), $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 7200 + 5, $cookie->getExpiresTime());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
    }

    public function testTheCookieIsUsableOnTheRedirectTarget(): void
    {
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token, server: $this->browserServer('none'));
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Create the administrator');
        self::assertStringContainsString('<title>Setup · Administrator · Fleet</title>', $this->body());
    }

    public function testACrossSiteLinkGetsAPageToClickInsteadOfARedirect(): void
    {
        // A Strict cookie is not sent on a redirect chain that began on another site; a click on our own page is same-site.
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token, server: $this->browserServer('cross-site'));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->client->getResponse()->headers->getCookies());
        $this->client->clickLink('Continue');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Create the administrator');
    }

    public function testAValidSessionOnTheEntryForwardsToTheFirstOpenStep(): void
    {
        $this->signIn();

        $this->client->request('GET', self::BASE.'/setup');
        self::assertResponseRedirects(self::BASE.'/setup/admin');

        $this->createAdmin();
        $this->client->request('GET', self::BASE.'/setup');
        self::assertResponseRedirects(self::BASE.'/setup/ssh', null, 'the SSH keys do not exist yet');

        $this->open('/setup/ssh');
        $this->client->request('GET', self::BASE.'/setup');
        self::assertResponseRedirects(self::BASE.'/setup/finish');
    }

    public function testCompletedSetupIs404ForEveryPath(): void
    {
        $this->completeSetupInDatabase();

        foreach (self::STEP_PATHS as $path) {
            $this->client->request('GET', self::BASE.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token);
        self::assertResponseStatusCodeSame(404);
        $this->post('/setup/finish', []);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getResponse()->headers->getCookies(), 'no session is handed out after completion');
    }

    public function testCookieIssuedBeforeCompletionIs404After(): void
    {
        $this->signIn();
        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(200);

        $this->completeSetupInDatabase();

        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(404);
    }

    public function testACookieFromBeforeANewTokenStopsWorking(): void
    {
        $this->signIn();
        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(200);

        $this->container()->get('fleet.test_alias.setup_token_service')->mint();

        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredTokenIs403(): void
    {
        $this->client->request('GET', self::BASE.'/setup/admin');
        $noCookie = $this->body();

        $repository = new HostSettingsRepository($this->em());
        $host = $repository->get() ?? self::fail('No host row.');
        $host->storeSetupToken(hash('sha256', $this->token), new \DateTimeImmutable('-1 minute'));
        $repository->save($host);

        $this->client->request('GET', self::BASE.'/setup?token='.$this->token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($noCookie, $this->body());
    }

    public function testReachableWithoutProxyIdentity(): void
    {
        // The browser server parameters carry no identity at all (stand-in role "none", no Remote-* header).
        $this->signIn();
        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(200);

        $this->open('/');
        self::assertResponseStatusCodeSame(401, 'the rest of the app still needs a sign-in');
    }

    public function testTheTokenExchangeIsThrottledPerClientAddress(): void
    {
        $statuses = [];
        for ($i = 0; $i < 12; ++$i) {
            $this->client->request('GET', self::BASE.'/setup?token='.str_repeat('a', 43));
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(403, $statuses[0]);
        self::assertSame(429, $statuses[11]);
        self::assertSame('900', $this->client->getResponse()->headers->get('Retry-After'));

        // Even the right token waits while over the limit; another address is unaffected.
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token);
        self::assertResponseStatusCodeSame(429);
        $this->client->request('GET', self::BASE.'/setup?token='.$this->token, server: ['REMOTE_ADDR' => \sprintf('172.%d.%d.%d', random_int(16, 31), random_int(0, 255), random_int(1, 254))] + $this->browserServer('none'));
        self::assertResponseStatusCodeSame(302);
    }

    public function testNothingUnderSetupIsCachedOrIndexed(): void
    {
        $this->client->request('GET', self::BASE.'/setup/admin');

        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
    }
}
