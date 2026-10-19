<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use PHPUnit\Framework\TestCase;

final class FleetPathsTest extends TestCase
{
    public function testDerivesAllPathsFromHome(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');

        self::assertSame('/srv/fleet', $paths->home());
        self::assertSame('/srv/fleet/var', $paths->varDir());
        self::assertSame('/srv/fleet/var/fleet.sqlite', $paths->fleetDb());
        self::assertSame('/srv/fleet/var/queue.sqlite', $paths->queueDb());
        self::assertSame('/srv/fleet/keys', $paths->keysDir());
        self::assertSame('/srv/fleet/keys/secret.key', $paths->secretKey());
        self::assertSame('/srv/fleet/ssh', $paths->sshDir());
        self::assertSame('/srv/fleet/assets/demo', $paths->assetsDir('demo'));
        self::assertSame('/srv/fleet/instances', $paths->instancesDir());
        self::assertSame('/srv/fleet/instances/demo--main', $paths->instanceDir('demo--main'));
        self::assertSame('/srv/fleet/logs', $paths->logsDir());
        self::assertSame('/srv/fleet/logs/jobs/42.log', $paths->jobLog('42'));
        self::assertSame('/srv/fleet/logs/instances/demo--main/42.log', $paths->jobLog('42', 'demo--main'));
        self::assertSame('/srv/fleet/locks', $paths->locksDir());
    }

    public function testNormalisesTrailingSlash(): void
    {
        $paths = new FleetPaths('/srv/fleet/', '/etc/caddy/fleet');

        self::assertSame('/srv/fleet', $paths->home());
        self::assertSame('/srv/fleet/var/fleet.sqlite', $paths->fleetDb());
    }

    public function testRejectsRelativeOrEmptyHome(): void
    {
        foreach (['', 'var/x', '.', '/'] as $home) {
            try {
                new FleetPaths($home, '/etc/caddy/fleet');
                self::fail(\sprintf('Home "%s" should have been rejected.', $home));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsTraversalInIds(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');
        $bad = [
            static fn () => $paths->instanceDir('../etc'),
            static fn () => $paths->instanceDir(''),
            static fn () => $paths->instanceDir('.hidden'),
            static fn () => $paths->instanceDir('a/b'),
            static fn () => $paths->assetsDir('a/b'),
            static fn () => $paths->assetsDir('..'),
            static fn () => $paths->jobLog('1', '..'),
            static fn () => $paths->jobLog('../1'),
            static fn () => $paths->instanceDir(str_repeat('a', 129)),
            static fn () => $paths->instanceDir("a\0b"),
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                self::fail(\sprintf('Case %d should have been rejected.', $i));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEnsureLayoutCreatesDirectoriesWithModes(): void
    {
        $home = sys_get_temp_dir().'/fleet-paths-'.bin2hex(random_bytes(4));
        $previous = umask(0o077);

        try {
            $paths = new FleetPaths($home, $home.'/caddy');
            $paths->ensureLayout();
            $paths->ensureLayout();

            clearstatcache();
            self::assertSame(0o750, fileperms($home) & 0o777, 'home');
            $modes = [
                'var' => 0o750, 'keys' => 0o700, 'ssh' => 0o700, 'assets' => 0o750, 'instances' => 0o750,
                'logs' => 0o750, 'logs/jobs' => 0o750, 'logs/instances' => 0o750, 'locks' => 0o755,
                'staging' => 0o700, 'authelia' => 0o750,
            ];
            foreach ($modes as $dir => $mode) {
                self::assertDirectoryExists($home.'/'.$dir);
                self::assertSame($mode, fileperms($home.'/'.$dir) & 0o777, $dir);
            }
        } finally {
            umask($previous);
            self::removeLayout($home);
        }
    }

    public function testEnsureLayoutModesAreExactUnderPermissiveUmask(): void
    {
        $home = sys_get_temp_dir().'/fleet-paths-'.bin2hex(random_bytes(4));
        $previous = umask(0o000);

        try {
            new FleetPaths($home, $home.'/caddy')->ensureLayout();

            clearstatcache();
            self::assertSame(0o750, fileperms($home.'/var') & 0o777);
            self::assertSame(0o700, fileperms($home.'/keys') & 0o777);
            self::assertSame(0o750, fileperms($home) & 0o777);
            self::assertSame(0o750, fileperms($home.'/logs') & 0o777);
        } finally {
            umask($previous);
            self::removeLayout($home);
        }
    }

    public function testEnsureLayoutRepairsLooseModesOnExistingDirectories(): void
    {
        $home = sys_get_temp_dir().'/fleet-paths-'.bin2hex(random_bytes(4));
        $previous = umask(0o000);

        try {
            foreach (['keys', 'ssh', 'var', 'assets', 'instances', 'logs'] as $dir) {
                mkdir($home.'/'.$dir, 0o755, true);
            }

            new FleetPaths($home, $home.'/caddy')->ensureLayout();

            clearstatcache();
            self::assertSame(0o700, fileperms($home.'/keys') & 0o777);
            self::assertSame(0o700, fileperms($home.'/ssh') & 0o777);
            self::assertSame(0o750, fileperms($home.'/var') & 0o777);
            foreach (['assets', 'instances', 'logs'] as $dir) {
                self::assertSame(0o750, fileperms($home.'/'.$dir) & 0o777, $dir);
            }
        } finally {
            umask($previous);
            self::removeLayout($home);
        }
    }

    public function testNewPathsLiveWhereExpected(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');

        self::assertSame('/srv/fleet/staging', $paths->stagingDir());
        self::assertSame('/srv/fleet/staging/caddy/Caddyfile', $paths->stagingFile('caddy', 'Caddyfile'));
        self::assertSame('/srv/fleet/authelia', $paths->autheliaDir());
        self::assertSame('/srv/fleet/authelia/users.yml', $paths->usersFile());
        self::assertSame('/etc/caddy/fleet', $paths->caddySnippetDir());
        self::assertSame('/etc/caddy/fleet/gate.conf', $paths->gateFile());
        self::assertSame('/srv/fleet/ssh/known_hosts', $paths->knownHostsFile());
    }

    public function testStagingDirIsOwnerOnlyAfterEnsureLayout(): void
    {
        $home = sys_get_temp_dir().'/fleet-paths-'.bin2hex(random_bytes(4));
        $previous = umask(0o000);

        try {
            mkdir($home.'/staging', 0o755, true);
            new FleetPaths($home, $home.'/caddy')->ensureLayout();

            clearstatcache();
            self::assertSame(0o700, fileperms($home.'/staging') & 0o777, 'repaired');
            self::assertSame(0o750, fileperms($home.'/authelia') & 0o777, 'created');
        } finally {
            umask($previous);
            self::removeLayout($home);
        }
    }

    public function testAutheliaDirModeIsNotRepairedWhenItExists(): void
    {
        // Production creates it setgid (fleet:authelia 2750) before the app runs; ensureLayout must leave that alone.
        $home = sys_get_temp_dir().'/fleet-paths-'.bin2hex(random_bytes(4));
        $previous = umask(0o000);

        try {
            mkdir($home.'/authelia', 0o2750, true);
            chmod($home.'/authelia', 0o2750);
            new FleetPaths($home, $home.'/caddy')->ensureLayout();

            clearstatcache();
            self::assertSame(0o2750, fileperms($home.'/authelia') & 0o7777);
        } finally {
            umask($previous);
            self::removeLayout($home);
        }
    }

    public function testStagingFileRejectsTraversal(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');
        $bad = [
            static fn () => $paths->stagingFile('..', 'x'),
            static fn () => $paths->stagingFile('caddy', '..'),
            static fn () => $paths->stagingFile('a/b', 'x'),
            static fn () => $paths->stagingFile('caddy', '../etc/passwd'),
            static fn () => $paths->stagingFile('caddy', '/etc/passwd'),
            static fn () => $paths->stagingFile('', 'x'),
            static fn () => $paths->stagingFile('caddy', ''),
            static fn () => $paths->stagingFile('caddy', '.hidden'),
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                self::fail(\sprintf('Case %d should have been rejected.', $i));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSshKeyPathsPerRole(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');

        self::assertSame('/srv/fleet/ssh/deploy_ed25519', $paths->sshPrivateKey(SshKeyRole::Deploy));
        self::assertSame('/srv/fleet/ssh/deploy_ed25519.pub', $paths->sshPublicKey(SshKeyRole::Deploy));
        self::assertSame('/srv/fleet/ssh/push_ed25519', $paths->sshPrivateKey(SshKeyRole::Push));
        self::assertSame('/srv/fleet/ssh/push_ed25519.pub', $paths->sshPublicKey(SshKeyRole::Push));
    }

    public function testCaddyDirMustBeAbsolute(): void
    {
        foreach (['', 'caddy', 'etc/caddy/fleet', '/', '/etc/../caddy', "/etc/caddy\0x"] as $dir) {
            try {
                new FleetPaths('/srv/fleet', $dir);
                self::fail(\sprintf('Caddy dir "%s" should have been rejected.', addcslashes($dir, "\0")));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCaddyMainFileIsSiblingOfSnippetDir(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet/');

        self::assertSame('/etc/caddy/fleet', $paths->caddySnippetDir());
        self::assertSame('/etc/caddy/Caddyfile', $paths->caddyMainFile());
    }

    private static function removeLayout(string $home): void
    {
        foreach (['var', 'keys', 'ssh', 'locks', 'logs/jobs', 'logs/instances', 'logs', 'assets', 'instances', 'staging', 'authelia'] as $dir) {
            @rmdir($home.'/'.$dir);
        }
        @rmdir($home);
    }

    public function testInstanceLogsCannotCollideWithTheJobLogDirectory(): void
    {
        $paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');

        // An instance literally named "jobs" or "instances" stays under logs/instances/.
        self::assertSame('/srv/fleet/logs/instances/jobs/1.log', $paths->jobLog('1', 'jobs'));
        self::assertSame('/srv/fleet/logs/instances/instances/1.log', $paths->jobLog('1', 'instances'));
        self::assertSame('/srv/fleet/logs/jobs/1.log', $paths->jobLog('1'));
    }
}
