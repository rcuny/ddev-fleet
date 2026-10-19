<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\GitIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitIdentityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function names(): iterable
    {
        foreach (['ddev-fleet bot', 'Renée', 'a', str_repeat('a', 100), 'Dr. J. O\'Neil'] as $name) {
            yield substr($name, 0, 30) => [$name];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badNames(): iterable
    {
        foreach (['', ' ', 'a<b', 'a>b', "a\nb", "a\tb", "a\0b", "a\x1bb", "a\u{2028}b", "a\u{200B}b", "\xff", str_repeat('a', 101), null, 5] as $i => $name) {
            yield '#'.$i.' '.(\is_string($name) ? addcslashes(substr($name, 0, 20), "\0..\37\177..\377") : get_debug_type($name)) => [$name];
        }
    }

    #[DataProvider('names')]
    public function testNameAccepts(string $name): void
    {
        self::assertNull(GitIdentity::nameError($name));
    }

    #[DataProvider('badNames')]
    public function testNameRejects(mixed $name): void
    {
        self::assertIsString(GitIdentity::nameError($name));
    }

    public function testNameLimitIsCountedInCharacters(): void
    {
        self::assertNull(GitIdentity::nameError(str_repeat('é', 100)));
        self::assertNotNull(GitIdentity::nameError(str_repeat('é', 101)));
    }

    /** @return iterable<string, array{string}> */
    public static function emails(): iterable
    {
        foreach (['bot@example.com', 'ddev.bot+fleet@git.example.com', 'a@b.io'] as $email) {
            yield $email => [$email];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badEmails(): iterable
    {
        foreach (['', 'bot', 'a@b', 'a b@example.com', "bot@example.com\n", 'bot@example.com ', '@example.com', 'bot@', 'bot@@example.com', 'a<b>@example.com', 'bot@exämple.com', 'bot@-example.com', 'bot@example..com', str_repeat('a', 250).'@example.com', null, 5] as $i => $email) {
            yield '#'.$i.' '.(\is_string($email) ? addcslashes(substr($email, 0, 20), "\0..\37\177..\377") : get_debug_type($email)) => [$email];
        }
    }

    #[DataProvider('emails')]
    public function testEmailAccepts(string $email): void
    {
        self::assertNull(GitIdentity::emailError($email));
    }

    #[DataProvider('badEmails')]
    public function testEmailRejects(mixed $email): void
    {
        $error = GitIdentity::emailError($email);

        self::assertIsString($error);
        if (\is_string($email) && \strlen($email) >= 4) {
            self::assertStringNotContainsString($email, $error, 'no echo of the input');
        }
        self::assertStringNotContainsString('ACME', $error);
    }
}
