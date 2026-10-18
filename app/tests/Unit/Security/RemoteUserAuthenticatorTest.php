<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\FleetUser;
use App\Security\RemoteUserAuthenticator;
use App\Security\TrustedProxyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class RemoteUserAuthenticatorTest extends TestCase
{
    private RemoteUserAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new RemoteUserAuthenticator(new TrustedProxyChecker('127.0.0.1,::1'), 'fleet-admins');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers, string $peer = '127.0.0.1'): Request
    {
        $server = ['REMOTE_ADDR' => $peer];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'GET', [], [], [], $server);
    }

    private static function userOf(Passport $passport): FleetUser
    {
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        $user = $badge->getUser();
        self::assertInstanceOf(FleetUser::class, $user);

        return $user;
    }

    public function testSupportsOnlyWhenRemoteUserIsPresent(): void
    {
        self::assertTrue($this->authenticator->supports($this->request(['Remote-User' => 'alice'])));
        self::assertFalse($this->authenticator->supports($this->request([])));
        self::assertFalse($this->authenticator->supports($this->request(['Remote-Groups' => 'fleet-admins'])));
    }

    public function testGroupsAreParsedTrimmedAndDeduplicated(): void
    {
        $user = self::userOf($this->authenticator->authenticate(
            $this->request(['Remote-User' => 'alice', 'Remote-Groups' => 'fleet-admins, demo,,demo, ']),
        ));

        self::assertSame(['fleet-admins', 'demo'], $user->groups());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testNameDefaultsToTheUsernameAndEmailIsOptional(): void
    {
        $plain = self::userOf($this->authenticator->authenticate($this->request(['Remote-User' => 'alice'])));
        self::assertSame('alice', $plain->displayName());
        self::assertNull($plain->email());
        self::assertSame([], $plain->groups());
        self::assertSame(['ROLE_USER', 'ROLE_MEMBER'], $plain->getRoles());

        $full = self::userOf($this->authenticator->authenticate($this->request([
            'Remote-User' => 'alice', 'Remote-Name' => 'Alice Liddell', 'Remote-Email' => 'alice@fleet.example.com',
        ])));
        self::assertSame('Alice Liddell', $full->displayName());
        self::assertSame('alice@fleet.example.com', $full->email());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUsernames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'space' => ['bad user'];
        yield 'nul byte' => ["a\x00b"];
        yield 'slash' => ['a/b'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'unicode' => ['alicé'];
    }

    #[DataProvider('badUsernames')]
    public function testInvalidUsernamesFailAuthentication(string $username): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($this->request(['Remote-User' => $username]));
    }

    public function testLongestAllowedUsernameIsAccepted(): void
    {
        $name = str_repeat('a', 128);

        self::assertSame($name, self::userOf($this->authenticator->authenticate($this->request(['Remote-User' => $name])))->getUserIdentifier());
    }

    public function testGroupBoundsAreEnforced(): void
    {
        $max = implode(',', array_map(static fn (int $i): string => 'g'.$i, range(1, 64)));
        self::assertCount(64, self::userOf($this->authenticator->authenticate(
            $this->request(['Remote-User' => 'alice', 'Remote-Groups' => $max]),
        ))->groups());

        $longest = str_repeat('g', 128);
        self::assertSame([$longest], self::userOf($this->authenticator->authenticate(
            $this->request(['Remote-User' => 'alice', 'Remote-Groups' => $longest]),
        ))->groups());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oversizedGroups(): iterable
    {
        yield 'too many groups' => [implode(',', array_map(static fn (int $i): string => 'g'.$i, range(1, 65)))];
        yield 'group too long' => [str_repeat('g', 129)];
    }

    #[DataProvider('oversizedGroups')]
    public function testOversizedGroupsFailAuthentication(string $groups): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($this->request(['Remote-User' => 'alice', 'Remote-Groups' => $groups]));
    }

    public function testUntrustedProxyIsRejected(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($this->request(['Remote-User' => 'alice', 'Remote-Groups' => 'fleet-admins'], '203.0.113.9'));
    }

    public function testForwardedForCannotMakeAPeerTrusted(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($this->request(['Remote-User' => 'alice', 'X-Forwarded-For' => '127.0.0.1'], '203.0.113.9'));
    }

    public function testFailureAndEntryPointAnswer401PlainText(): void
    {
        $request = $this->request([]);

        foreach ([
            $this->authenticator->onAuthenticationFailure($request, new AuthenticationException()),
            $this->authenticator->start($request),
        ] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        }
    }
}
