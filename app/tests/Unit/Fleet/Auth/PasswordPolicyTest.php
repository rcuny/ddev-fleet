<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Auth;

use App\Fleet\Auth\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy();
    }

    public function testRejectsShort(): void
    {
        $violations = $this->policy->violations('elevenchars', 'alice');

        self::assertCount(1, $violations);
        self::assertStringContainsString('at least 12', $violations[0]);
    }

    public function testRejectsUsernameAsPassword(): void
    {
        self::assertSame(['The password must not be the username.'], $this->policy->violations('administrator-01', 'administrator-01'));
        self::assertSame(['The password must not be the username.'], $this->policy->violations('Administrator-01', 'administrator-01'), 'case does not make it a different password');
    }

    public function testAcceptsPassphrase(): void
    {
        self::assertSame([], $this->policy->violations('correct horse battery staple', 'alice'));
    }

    public function testLengthBoundariesCountCharactersNotBytes(): void
    {
        self::assertSame([], $this->policy->violations(str_repeat('é', 12), 'alice'), '12 two-byte characters are 12 characters');
        self::assertNotSame([], $this->policy->violations(str_repeat('é', 11), 'alice'));
        self::assertSame([], $this->policy->violations(str_repeat('a', 128), 'alice'));
        self::assertNotSame([], $this->policy->violations(str_repeat('a', 129), 'alice'));
    }

    public function testRejectsNulAndInvalidUtf8(): void
    {
        self::assertNotSame([], $this->policy->violations("long enough pass\0word", 'alice'));
        self::assertSame(['The password must be valid UTF-8.'], $this->policy->violations("caf\xE9 caf\xE9 caf\xE9 caf\xE9", 'alice'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secrets(): iterable
    {
        yield 'short' => ['sEcr3t-pw!'];
        yield 'with nul' => ["sEcr3t-pw!!!!!!!!\0"];
    }

    #[DataProvider('secrets')]
    public function testMessagesNeverContainThePassword(string $password): void
    {
        foreach ($this->policy->violations($password, 'alice') as $message) {
            self::assertStringNotContainsString('sEcr3t', $message);
        }
    }
}
