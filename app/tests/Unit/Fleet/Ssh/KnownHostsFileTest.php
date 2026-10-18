<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Ssh\Ed25519KeyGenerator;
use App\Fleet\Ssh\KnownHostConflict;
use App\Fleet\Ssh\KnownHostEntry;
use App\Fleet\Ssh\KnownHostsFile;
use App\Fleet\Ssh\SshPublicKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KnownHostsFileTest extends TestCase
{
    private const string GITHUB_ED25519 = 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU';
    private const string BITBUCKET_ED25519 = 'SHA256:ybgmFkzwOSotHTHLJgHO0QN8L0xErw6vd0VhFA9m3SM';
    private const string GITLAB_ED25519 = 'SHA256:eUXGGm1YGsMAS7vkcx6JOJdOGHPem5gQp4taiCfCLB8';

    private string $home = '';
    private FleetPaths $paths;
    private KnownHostsFile $file;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-known-hosts-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0o750);
        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->file = new KnownHostsFile($this->paths, self::realSeed());
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->home);
    }

    public function testSeedContainsGithubBitbucketGitlabEd25519WithPublishedFingerprints(): void
    {
        $this->file->seed();

        $fingerprints = [];
        foreach ($this->file->entries() as $entry) {
            if ('ssh-ed25519' === $entry->type) {
                $fingerprints[$entry->host] = $entry->fingerprint();
            }
        }
        self::assertSame(
            ['github.com' => self::GITHUB_ED25519, 'bitbucket.org' => self::BITBUCKET_ED25519, 'gitlab.com' => self::GITLAB_ED25519],
            $fingerprints,
        );
        self::assertTrue($this->file->has('github.com'));
        self::assertTrue($this->file->has('GitLab.com'));
        self::assertFalse($this->file->has('git.example.com'));
    }

    public function testSeedIsIdempotentAndKeepsLocalAdditions(): void
    {
        $key = self::key();
        $this->file->seed();
        $this->file->add(new KnownHostEntry('git.example.com', 'ssh-ed25519', $key));
        $before = (string) file_get_contents($this->paths->knownHostsFile());

        $this->file->seed();

        self::assertSame($before, (string) file_get_contents($this->paths->knownHostsFile()));
        self::assertTrue($this->file->has('git.example.com'));
        self::assertSame(0o644, fileperms($this->paths->knownHostsFile()) & 0o777);
    }

    public function testSeedKeepsCommentsAndBlankLinesOfAnExistingFile(): void
    {
        mkdir($this->paths->sshDir(), 0o700);
        file_put_contents($this->paths->knownHostsFile(), "# mine\n\n\n# again\n");

        $this->file->seed();

        $contents = (string) file_get_contents($this->paths->knownHostsFile());
        self::assertStringStartsWith("# mine\n\n\n# again\ngithub.com ", $contents);
    }

    public function testSeedMarksARevokedKeyAndDropsItsTrustedLine(): void
    {
        $old = self::key();
        $new = self::key();
        $seedFile = $this->home.'/seed';
        file_put_contents($seedFile, "@revoked git.example.com ssh-ed25519 $old\ngit.example.com ssh-ed25519 $new\n");
        mkdir($this->paths->sshDir(), 0o700);
        file_put_contents($this->paths->knownHostsFile(), "git.example.com ssh-ed25519 $old\nlocal.example.com ssh-ed25519 $old\n");

        new KnownHostsFile($this->paths, $seedFile)->seed();

        self::assertSame(
            "local.example.com ssh-ed25519 $old\n@revoked git.example.com ssh-ed25519 $old\ngit.example.com ssh-ed25519 $new\n",
            (string) file_get_contents($this->paths->knownHostsFile()),
        );
    }

    public function testASeedWithABrokenLineFailsLoudly(): void
    {
        $seedFile = $this->home.'/seed';
        file_put_contents($seedFile, "git.example.com ssh-ed25519 !!!\n");

        $this->expectException(\RuntimeException::class);

        new KnownHostsFile($this->paths, $seedFile)->seed();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHosts(): iterable
    {
        yield 'option like' => ['-oProxyCommand=touch /tmp/pwn'];
        yield 'dash' => ['-github.com'];
        yield 'space' => ['evil.example.com ssh-rsa AAAA'];
        yield 'newline' => ["evil.example.com\ngithub.com"];
        yield 'comma list' => ['a.example.com,github.com'];
        yield 'wildcard' => ['*'];
        yield 'negation' => ['!github.com'];
        yield 'hashed' => ['|1|c2FsdA==|aGFzaA=='];
        yield 'port zero length' => ['[a.example.com]:'];
        yield 'port too big' => ['[a.example.com]:70000'];
        yield 'empty' => [''];
    }

    #[DataProvider('badHosts')]
    public function testAddRejectsOptionLikeHostAndWhitespace(string $host): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KnownHostEntry($host, 'ssh-ed25519', self::key());
    }

    public function testEntryRejectsInjectionThroughTypeAndKey(): void
    {
        $key = self::key();
        foreach ([
            ['ssh-ed25519 extra', $key],
            ['ssh-ed25519', $key." extra\nevil.example.com ssh-ed25519 ".$key],
            ['ssh-dss', $key],
            ['ssh-rsa', $key],
        ] as [$type, $material]) {
            try {
                new KnownHostEntry('git.example.com', $type, $material);
                self::fail('expected InvalidArgumentException for type "'.$type.'"');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAddIsIdempotent(): void
    {
        $entry = new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key());

        $this->file->add($entry);
        $this->file->add($entry);
        $this->file->add(new KnownHostEntry('GIT.example.com', $entry->type, $entry->key));

        self::assertSame($entry->line()."\n", (string) file_get_contents($this->paths->knownHostsFile()));
        self::assertSame([$entry->line()], array_map(static fn (KnownHostEntry $e): string => $e->line(), $this->file->entries()));
    }

    public function testBracketedPortHostAccepted(): void
    {
        $entry = new KnownHostEntry('[Git.Example.com]:2222', 'ssh-ed25519', self::key());
        $this->file->add($entry);

        self::assertSame('[git.example.com]:2222', $entry->host);
        self::assertTrue($this->file->has('[git.example.com]:2222'));
        self::assertFalse($this->file->has('git.example.com'), 'a host and the same host on another port are different identities');
    }

    public function testAddRefusesASecondKeyForASeededHost(): void
    {
        $this->file->seed();
        $before = (string) file_get_contents($this->paths->knownHostsFile());
        foreach (['github.com', 'GitHub.COM', 'bitbucket.org', 'gitlab.com'] as $host) {
            // Same type with another key, and another type altogether: both would be a second identity for the host.
            foreach ([['ssh-ed25519', self::key()], ['ssh-rsa', self::rsaLikeKey()]] as [$type, $key]) {
                try {
                    $this->file->add(new KnownHostEntry($host, $type, $key));
                    self::fail('a forged key must not be attached to '.$host);
                } catch (KnownHostConflict) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame($before, (string) file_get_contents($this->paths->knownHostsFile()));
    }

    public function testAddRefusesADifferentKeyForAnyExistingHost(): void
    {
        $this->file->add(new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key()));

        $this->expectException(KnownHostConflict::class);

        $this->file->add(new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key()));
    }

    public function testReplaceIsRefusedForSeededHostsAndWorksForOthers(): void
    {
        $this->file->seed();
        try {
            $this->file->replace([new KnownHostEntry('github.com', 'ssh-ed25519', self::key())]);
            self::fail('replace must be refused for a seeded host');
        } catch (KnownHostConflict) {
            $this->addToAssertionCount(1);
        }

        $old = new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key());
        $new = new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key());
        $this->file->add($old);
        $this->file->replace([$new]);

        $lines = array_map(static fn (KnownHostEntry $e): string => $e->line(), $this->file->entries());
        self::assertContains($new->line(), $lines);
        self::assertNotContains($old->line(), $lines);
        self::assertTrue($this->file->has('github.com'));
    }

    public function testAddAllTakesSeveralKeysForANewHostOnly(): void
    {
        $a = new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key());
        $b = new KnownHostEntry('git.example.com', 'ssh-rsa', self::rsaLikeKey());

        $this->file->addAll([$a, $b]);
        $this->file->addAll([$a, $b]);

        self::assertCount(2, $this->file->entries());
        $this->expectException(\InvalidArgumentException::class);
        $this->file->addAll([$a, new KnownHostEntry('other.example.com', 'ssh-ed25519', self::key())]);
    }

    public function testHashedAndListedHostsCountAsExisting(): void
    {
        $key = self::key();
        $salt = random_bytes(20);
        $hash = hash_hmac('sha1', 'hashed.example.com', $salt, true);
        mkdir($this->paths->sshDir(), 0o700);
        file_put_contents($this->paths->knownHostsFile(), \sprintf(
            "|1|%s|%s ssh-ed25519 %s\nlisted.example.com,10.0.0.9 ssh-ed25519 %s\n@cert-authority *.ca.example.com ssh-ed25519 %s\n",
            base64_encode($salt),
            base64_encode($hash),
            $key,
            $key,
            $key,
        ));

        self::assertTrue($this->file->has('hashed.example.com'));
        self::assertTrue($this->file->has('listed.example.com'));
        self::assertTrue($this->file->has('10.0.0.9'));
        foreach (['hashed.example.com', 'listed.example.com'] as $host) {
            try {
                $this->file->add(new KnownHostEntry($host, 'ssh-ed25519', self::key()));
                self::fail($host.' already has a key');
            } catch (KnownHostConflict) {
                $this->addToAssertionCount(1);
            }
        }

        // Replacing one host of a list keeps the other host's trust.
        $new = new KnownHostEntry('listed.example.com', 'ssh-ed25519', self::key());
        $this->file->replace([$new]);
        self::assertTrue($this->file->has('10.0.0.9'));
        self::assertStringContainsString('10.0.0.9 ssh-ed25519', (string) file_get_contents($this->paths->knownHostsFile()));
        self::assertStringNotContainsString('listed.example.com,10.0.0.9', (string) file_get_contents($this->paths->knownHostsFile()));
        self::assertStringContainsString($new->line(), (string) file_get_contents($this->paths->knownHostsFile()));
    }

    public function testAnExistingRevokedLineDoesNotCountAsTrust(): void
    {
        $key = self::key();
        mkdir($this->paths->sshDir(), 0o700);
        file_put_contents($this->paths->knownHostsFile(), "@revoked git.example.com ssh-ed25519 $key\n");

        self::assertFalse($this->file->has('git.example.com'));
        $this->file->add(new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key()));
        self::assertTrue($this->file->has('git.example.com'));
    }

    public function testWritesAreAtomicAndLeaveNoTempFiles(): void
    {
        $this->file->seed();
        $this->file->add(new KnownHostEntry('git.example.com', 'ssh-ed25519', self::key()));

        $names = array_values(array_diff((array) scandir($this->paths->sshDir()), ['.', '..']));
        sort($names);
        self::assertSame(['.known_hosts.lock', 'known_hosts'], $names);
        self::assertSame(0o700, fileperms($this->paths->sshDir()) & 0o777);
    }

    public function testASymlinkedFileIsRefused(): void
    {
        mkdir($this->paths->sshDir(), 0o700);
        file_put_contents($this->home.'/elsewhere', '');
        symlink($this->home.'/elsewhere', $this->paths->knownHostsFile());

        $this->expectException(\RuntimeException::class);

        $this->file->seed();
    }

    public function testEntryFingerprintAndLine(): void
    {
        $key = self::key();
        $entry = new KnownHostEntry('Git.Example.com', 'ssh-ed25519', $key);

        self::assertSame('git.example.com ssh-ed25519 '.$key, $entry->line());
        self::assertSame(SshPublicKeys::fingerprint('ssh-ed25519', $key), $entry->fingerprint());
        self::assertSame('@revoked git.example.com ssh-ed25519 '.$key, new KnownHostEntry('git.example.com', 'ssh-ed25519', $key, true)->line());
    }

    private static function realSeed(): string
    {
        return \dirname(__DIR__, 4).'/resources/ssh/known_hosts.seed';
    }

    private static function key(): string
    {
        return SshPublicKeys::parseLine(new Ed25519KeyGenerator()->generate('c')->publicLine)['key'];
    }

    /** A blob that is structurally an ssh-rsa key (type string, exponent, modulus); only the framing is validated. */
    private static function rsaLikeKey(): string
    {
        $field = static fn (string $v): string => pack('N', \strlen($v)).$v;

        return base64_encode($field('ssh-rsa').$field("\x01\x00\x01").$field("\x00".random_bytes(256)));
    }
}
