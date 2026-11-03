<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Auth;

use App\Domain\User\PasswordHash;
use App\Domain\User\User;
use App\Fleet\Auth\AutheliaUsersRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AutheliaUsersRendererTest extends TestCase
{
    public const string HASH_A = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$ZGlnZXN0ZGlnZXN0ZGlnZXN0';
    public const string HASH_B = '$argon2id$v=19$m=65536,t=4,p=1$b3RoZXJzYWx0b3RoZXI$b3RoZXJkaWdlc3RvdGhlcmRpZ2VzdA';

    /**
     * @param list<string> $groups
     */
    private static function user(string $username, string $name = 'Alice', string $email = 'alice@example.com', array $groups = ['fleet-admins'], string $hash = self::HASH_A): User
    {
        return new User($username, $name, $email, $hash, $groups);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $yaml): array
    {
        $parsed = Yaml::parse($yaml);
        self::assertIsArray($parsed);

        return $parsed;
    }

    public function testProjectMembershipsBecomeProjectGroupsOnce(): void
    {
        $parsed = self::parse(new AutheliaUsersRenderer()->render(
            [self::user('alice', groups: ['dev']), self::user('bob', groups: []), self::user('carol')],
            ['alice' => ['blog', 'demo'], 'bob' => ['demo'], 'ghost' => ['demo']],
        ));

        self::assertSame(['dev', 'fleet-project-blog', 'fleet-project-demo'], $parsed['users']['alice']['groups']);
        self::assertSame(['fleet-project-demo'], $parsed['users']['bob']['groups']);
        self::assertSame(['fleet-admins'], $parsed['users']['carol']['groups'], 'no membership, no project group');
        self::assertArrayNotHasKey('ghost', $parsed['users'], 'a membership of an unknown user renders nothing');
    }

    public function testASiteOnlyUserGetsTheMarkerGroupAndAPlaceholderEmail(): void
    {
        $siteOnly = new User('shield', 'shield', '', self::HASH_A, [], siteOnly: true);

        $parsed = self::parse(new AutheliaUsersRenderer()->render([$siteOnly], ['shield' => ['blog']]));

        self::assertSame(['fleet-site-only', 'fleet-project-blog'], $parsed['users']['shield']['groups']);
        self::assertSame('shield@site-only.invalid', $parsed['users']['shield']['email']);
    }

    public function testAStoredUserGroupWithTheReservedPrefixIsRefused(): void
    {
        $user = self::user('alice');
        // A row edited by hand: the entity refuses the group, a hydrated one does not go through the constructor.
        new \ReflectionProperty($user, 'groups')->setValue($user, ['fleet-project-blog']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        new AutheliaUsersRenderer()->render([$user]);
    }

    public function testAProjectNameThatIsNotAValidNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AutheliaUsersRenderer()->render([self::user('alice')], ['alice' => ["blog\ngroups: [fleet-admins]"]]);
    }

    public function testGoldenOutput(): void
    {
        $out = new AutheliaUsersRenderer()->render([
            self::user('bob', 'Bob Builder', 'bob@example.com', ['dev', 'ops'], self::HASH_B),
            self::user('alice'),
        ]);

        // Pins the dumper's real output: every key and string double-quoted, two-space indent, users sorted.
        $expected = <<<'YAML'
            "users":
              "alice":
                "disabled": false
                "displayname": "Alice"
                "password": "$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$ZGlnZXN0ZGlnZXN0ZGlnZXN0"
                "email": "alice@example.com"
                "groups":
                  - "fleet-admins"
              "bob":
                "disabled": false
                "displayname": "Bob Builder"
                "password": "$argon2id$v=19$m=65536,t=4,p=1$b3RoZXJzYWx0b3RoZXI$b3RoZXJkaWdlc3RvdGhlcmRpZ2VzdA"
                "email": "bob@example.com"
                "groups":
                  - "dev"
                  - "ops"

            YAML;
        self::assertSame($expected, $out);
        self::assertSame($out, new AutheliaUsersRenderer()->render([
            self::user('alice'),
            self::user('bob', 'Bob Builder', 'bob@example.com', ['dev', 'ops'], self::HASH_B),
        ]), 'input order does not matter');
    }

    public function testQuotesColonsAndApostrophes(): void
    {
        $name = "O'Brien: \"the\" #1 \\ {x} [y] - !z & *w | > % @ `v`";
        $out = new AutheliaUsersRenderer()->render([self::user('alice', $name, 'a+b@example.com')]);

        $parsed = self::parse($out);
        self::assertSame($name, $parsed['users']['alice']['displayname']);
        self::assertSame('a+b@example.com', $parsed['users']['alice']['email']);
        self::assertSame(['users'], array_keys($parsed));
        self::assertSame(['disabled', 'displayname', 'password', 'email', 'groups'], array_keys($parsed['users']['alice']));
    }

    public function testNewlinesInProfileCannotAddStructure(): void
    {
        $out = new AutheliaUsersRenderer()->render([self::user('alice', "Alice\n  evil:\n    groups: [fleet-admins]", "a@example.com\n")]);

        $parsed = self::parse($out);
        self::assertSame(['alice'], array_keys($parsed['users']));
        self::assertSame(['fleet-admins'], $parsed['users']['alice']['groups']);
        self::assertSame(['disabled', 'displayname', 'password', 'email', 'groups'], array_keys($parsed['users']['alice']));
    }

    public function testDisabledUser(): void
    {
        $user = self::user('alice');
        $user->setEnabled(false);

        $parsed = self::parse(new AutheliaUsersRenderer()->render([$user, self::user('bob')]));

        self::assertTrue($parsed['users']['alice']['disabled']);
        self::assertFalse($parsed['users']['bob']['disabled']);
    }

    public function testPlaintextNeverRendered(): void
    {
        $plain = 'plain-secret-passphrase';
        $hash = password_hash($plain, \PASSWORD_ARGON2ID);
        $out = new AutheliaUsersRenderer()->render([self::user('alice', hash: $hash)]);

        self::assertStringNotContainsString($plain, $out);
        self::assertSame($hash, self::parse($out)['users']['alice']['password']);
        self::assertTrue(password_verify($plain, self::parse($out)['users']['alice']['password']));
    }

    public function testUsernamesThatLookLikeYamlScalarsStayStringKeys(): void
    {
        $names = ['123', '1e3', '1.5', 'null', 'true', 'yes', 'on', '0x1f', '007', 'a.b-c_d'];
        $users = array_map(static fn (string $name): User => self::user($name), $names);

        $out = new AutheliaUsersRenderer()->render($users);
        $parsed = self::parse($out);

        // Compare as strings: PHP turns the integer-like keys of a parsed map back into ints.
        $keys = array_map('strval', array_keys($parsed['users']));
        sort($keys, \SORT_STRING);
        $expected = $names;
        sort($expected, \SORT_STRING);
        self::assertSame($expected, $keys);
        // A float-like key (1.5, 1e3) must be quoted or Authelia's Go parser would read a number, not a string.
        self::assertStringContainsString("'1.5':", $out);
        self::assertStringContainsString("'1e3':", $out);
    }

    public function testEmptyListRendersOneDisabledPlaceholderBecauseAutheliaRefusesAnEmptyMap(): void
    {
        $out = new AutheliaUsersRenderer()->render([]);
        $parsed = self::parse($out);

        self::assertSame([AutheliaUsersRenderer::PLACEHOLDER_USERNAME], array_keys($parsed['users']));
        $entry = $parsed['users'][AutheliaUsersRenderer::PLACEHOLDER_USERNAME];
        self::assertTrue($entry['disabled'], 'the placeholder can never sign in');
        self::assertSame([], $entry['groups'], 'and belongs to no group');
        self::assertTrue(PasswordHash::isArgon2id($entry['password']));
    }

    public function testRealUsersNeverCarryThePlaceholder(): void
    {
        $out = new AutheliaUsersRenderer()->render([self::user('alice')]);

        self::assertStringNotContainsString(AutheliaUsersRenderer::PLACEHOLDER_USERNAME, $out);
    }

    public function testTheInstallersStarterUsersFileEqualsWhatTheAppRendersForNoUsers(): void
    {
        self::assertSame(
            new AutheliaUsersRenderer()->render([]),
            file_get_contents(\dirname(__DIR__, 5).'/install/etc/authelia-users-empty.yml'),
        );
    }

    public function testRefusesAHashThatIsNotArgon2id(): void
    {
        // A User can only be built with an argon2id hash, so reach a tampered row the way the ORM does.
        $user = new \ReflectionClass(User::class)->newInstanceWithoutConstructor();
        foreach (['username' => 'alice', 'displayName' => 'Alice', 'email' => 'a@example.com', 'passwordHash' => 'letmein', 'groups' => [], 'enabled' => true] as $property => $value) {
            new \ReflectionProperty(User::class, $property)->setValue($user, $value);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^((?!letmein).)*$/s');
        new AutheliaUsersRenderer()->render([$user]);
    }

    public function testRefusesDuplicateUsernames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AutheliaUsersRenderer()->render([self::user('alice'), self::user('alice')]);
    }
}
