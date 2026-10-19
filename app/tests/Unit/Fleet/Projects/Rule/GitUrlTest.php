<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\GitUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach ([
            'https://github.com/acme/blog.git',
            'https://git.example.com:8443/team/blog',
            'git@github.com:acme/blog.git',
            'ssh://git@bitbucket.org/acme/blog.git',
            'ssh://git@git.example.com:2222/acme/blog.git',
            'git.example.com:acme/blog.git',
            'ssh://git.example.com/acme/blog',
            'https://10.0.0.5/acme/blog.git',
            'git@git.example.com:/srv/git/blog.git',
            'https://git.example.com/team/blog%2Bx.git',
            'ssh://deploy_1@git.example.com/~acme/blog',
            'https://gitlab.com/group/subgroup/project.git',
            'https://dev.azure.com/org/project/_git/repo',
            'ssh://git@bitbucket.example.com:7999/proj/repo.git',
        ] as $url) {
            yield $url => [$url];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        $cases = [
            'http://example.com/a.git',
            'https:/example.com/a.git',
            'ssh:/example.com/a.git',
            'git://example.com/a.git',
            'file:///srv/a',
            '/srv/a',
            './a',
            'ftp://h/a',
            'https://user:pw@example.com/a.git',
            'https://token@example.com/a.git',
            'ssh://user:pw@example.com/a.git',
            '-oProxyCommand=touch_x',
            'ssh://-oProxyCommand=x/a',
            'ssh://-oProxyCommand@h/a',
            '-o@host:a',
            'host:--upload-pack=x',
            'host:-x',
            'git@host:-x',
            'ext::sh_-c_id',
            'ext::sh -c id',
            'host:../a',
            'host:a/../b',
            'https://example.com/../a',
            'https://example.com/a/..',
            'https://example.com/%2e%2e/a',
            'https://example.com/a%00b',
            'https://example.com/a%0ab',
            'https://example.com/a%20b',
            'https://example.com/a%zz',
            'https://example.com/a%',
            'https://example.com',
            'https://example.com/',
            'https://example.com/a?b',
            'https://example.com/a#b',
            'https://example.com/a b',
            "https://example.com/a\nb",
            "https://example.com/a\n",
            "git@host:a\n",
            "ssh://git@host/a\n",
            "https://example.com/a\r",
            "https://example.com/a\x00",
            'https://example.com/a\\b',
            'https://exämple.com/a',
            'https://example.com/ä',
            'https://[::1]/a',
            'ssh://git@[::1]/a',
            'https://example.com:0/a',
            'https://example.com:70000/a',
            'https://example.com:/a',
            'https://example.com:80a/a',
            'ssh://git@example.com:0/a',
            'https:///a',
            'https://-example.com/a',
            'https://example-.com/a',
            'https://exa_mple.com/a',
            'https://example..com/a',
            'https://2130706433/a',
            'https://0x7f.1/a',
            'https://127.1/a',
            'https://010.0.0.1/a',
            'https://999.0.0.1/a',
            'ssh://a@b@example.com/a',
            'ssh://git@example.com',
            'ssh://git@example.com/',
            'git@:a',
            'git@host:',
            'git@host:/',
            ':a',
            'host',
            '',
            str_repeat('a', 2049),
            'https://example.com/'.str_repeat('a', 2040),
            'https://'.str_repeat('a', 64).'.com/a',
            'https://'.str_repeat('a.', 130).'com/a',
            5,
            null,
            ['https://example.com/a'],
        ];
        foreach ($cases as $i => $url) {
            yield \sprintf('#%d %s', $i, \is_string($url) ? addcslashes(substr($url, 0, 50), "\0..\37\177..\377") : get_debug_type($url)) => [$url];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $url): void
    {
        self::assertNull(GitUrl::error($url));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $url): void
    {
        $error = GitUrl::error($url);

        self::assertIsString($error);
        self::assertNotSame('', $error);
        if (\is_string($url) && \strlen($url) >= 6) {
            self::assertStringNotContainsString($url, $error, 'the message must not echo the input');
        }
    }

    public function testTheLongestAllowedUrlIsAccepted(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 2048 - 20);

        self::assertSame(2048, \strlen($url));
        self::assertNull(GitUrl::error($url));
        self::assertNotNull(GitUrl::error($url.'a'));
    }
}
