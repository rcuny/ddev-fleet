<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Auth;

use App\Domain\User\User;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\AutheliaUsersRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminGroupTest extends TestCase
{
    /** @param list<string> $groups */
    private static function user(array $groups): User
    {
        return new User('alice', 'Alice', 'alice@example.com', AutheliaUsersRenderer::PLACEHOLDER_HASH, $groups);
    }

    public function testIncludesFollowsTheConfiguredName(): void
    {
        $custom = new AdminGroup('my-admins');
        $default = new AdminGroup('fleet-admins');

        self::assertTrue($custom->includes(self::user(['dev', 'my-admins'])));
        self::assertFalse($custom->includes(self::user(['fleet-admins'])));
        self::assertTrue($default->includes(self::user(['fleet-admins'])));
        self::assertFalse($default->includes(self::user([])));
    }

    public function testAddedToKeepsOtherGroupsAndAddsTheNameOnce(): void
    {
        $group = new AdminGroup('my-admins');

        self::assertSame(['dev', 'my-admins'], $group->addedTo(['dev']));
        self::assertSame(['my-admins', 'dev'], $group->addedTo(['my-admins', 'dev']));
        self::assertSame(['my-admins'], $group->addedTo([]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableNames(): iterable
    {
        yield 'empty' => [''];
        yield 'comma (Remote-Groups is a list)' => ['a,b'];
        yield 'space' => ['my admins'];
        yield 'quote' => ['my"admins'];
        yield 'newline' => ["my\nadmins"];
        yield 'leading dash' => ['-admins'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('unusableNames')]
    public function testRefusesANameAutheliaOrTheAuthenticatorCouldMisread(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminGroup($name);
    }

    public function testAGroupWithTheProjectPrefixIsReserved(): void
    {
        $user = self::user(['dev']);

        foreach ([static fn () => self::user(['fleet-project-blog']), static fn () => $user->changeGroups(['fleet-project-x'])] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the reserved group to be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('reserved', $e->getMessage());
            }
        }
        self::assertSame(['dev'], $user->groups());
    }
}
