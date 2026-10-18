<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\FleetUser;
use App\Security\StandIn\StandInAuthenticator;
use App\Security\StandIn\StandInSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class StandInAuthenticatorTest extends TestCase
{
    private function authenticator(string $defaultRole = 'admin'): StandInAuthenticator
    {
        return new StandInAuthenticator(new StandInSettings(
            $defaultRole,
            'dev-admin',
            'fleet-admins',
            'dev-member',
            'demo',
            'fleet-admins',
        ));
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    private function request(array $server = [], array $cookies = []): Request
    {
        return Request::create('/', 'GET', [], $cookies, [], $server);
    }

    private static function userOf(Passport $passport): FleetUser
    {
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        $user = $badge->getUser();
        self::assertInstanceOf(FleetUser::class, $user);

        return $user;
    }

    public function testDefaultRoleComesFromTheEnvironment(): void
    {
        $admin = self::userOf($this->authenticator('admin')->authenticate($this->request()));
        self::assertSame('dev-admin', $admin->getUserIdentifier());
        self::assertSame('Dev Admin', $admin->displayName());
        self::assertSame('dev-admin@fleet.example.com', $admin->email());
        self::assertContains('ROLE_ADMIN', $admin->getRoles());

        $member = self::userOf($this->authenticator('member')->authenticate($this->request()));
        self::assertSame('dev-member', $member->getUserIdentifier());
        self::assertSame('Dev Member', $member->displayName());
        self::assertSame(['ROLE_USER', 'ROLE_MEMBER'], $member->getRoles());
    }

    public function testHeaderBeatsCookieBeatsEnvironment(): void
    {
        $authenticator = $this->authenticator('admin');

        $cookieOnly = $this->request([], [StandInSettings::COOKIE => 'member']);
        self::assertSame('dev-member', self::userOf($authenticator->authenticate($cookieOnly))->getUserIdentifier());

        $both = $this->request(['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin'], [StandInSettings::COOKIE => 'member']);
        self::assertSame('dev-admin', self::userOf($authenticator->authenticate($both))->getUserIdentifier());

        $headerMember = $this->request(['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);
        self::assertSame('dev-member', self::userOf($this->authenticator('admin')->authenticate($headerMember))->getUserIdentifier());
    }

    public function testNoneMeansNoAuthentication(): void
    {
        $authenticator = $this->authenticator('admin');

        self::assertFalse($authenticator->supports($this->request(['HTTP_X_FLEET_STAND_IN_ROLE' => 'none'])));
        self::assertFalse($authenticator->supports($this->request([], [StandInSettings::COOKIE => 'none'])));
        self::assertFalse($this->authenticator('none')->supports($this->request()));
        self::assertTrue($authenticator->supports($this->request()));
    }

    public function testAnUnknownValueFallsBackToTheEnvironmentDefault(): void
    {
        $authenticator = $this->authenticator('member');

        $request = $this->request(['HTTP_X_FLEET_STAND_IN_ROLE' => 'root'], [StandInSettings::COOKIE => 'superuser']);
        self::assertTrue($authenticator->supports($request));
        self::assertSame('dev-member', self::userOf($authenticator->authenticate($request))->getUserIdentifier());
    }

    public function testAnUnusableDefaultFailsClosed(): void
    {
        self::assertFalse($this->authenticator('root')->supports($this->request()));
    }

    public function testNeverSupportsARequestCarryingRemoteUser(): void
    {
        self::assertFalse($this->authenticator()->supports($this->request(['HTTP_REMOTE_USER' => 'alice'])));
    }

    public function testAuthenticateWithoutAnIdentityFails(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator('none')->authenticate($this->request());
    }

    public function testFailureLetsTheRequestContinueUnauthenticated(): void
    {
        self::assertNull($this->authenticator()->onAuthenticationFailure($this->request(), new AuthenticationException()));
    }
}
