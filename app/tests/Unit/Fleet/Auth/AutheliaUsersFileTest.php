<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Auth;

use App\Domain\User\User;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\FleetPaths;
use PHPUnit\Framework\TestCase;

final class AutheliaUsersFileTest extends TestCase
{
    private string $home;
    private FleetPaths $paths;
    private AutheliaUsersFile $file;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-users-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0o750);
        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->file = new AutheliaUsersFile($this->paths, new AutheliaUsersRenderer());
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

    private static function user(string $username = 'alice'): User
    {
        return new User($username, ucfirst($username), $username.'@example.com', AutheliaUsersRendererTest::HASH_A, ['fleet-admins']);
    }

    public function testWritesMode0640AtomicallyWithoutTempLeftovers(): void
    {
        $users = [self::user('alice'), self::user('bob')];

        self::assertTrue($this->file->write($users));

        $path = $this->paths->usersFile();
        self::assertSame($this->home.'/authelia/users.yml', $path);
        self::assertSame(0o640, fileperms($path) & 0o777);
        self::assertSame(new AutheliaUsersRenderer()->render($users), file_get_contents($path));
        self::assertSame(['users.yml'], array_values(array_diff(scandir(\dirname($path)) ?: [], ['.', '..'])), 'no temp file is left next to it');

        // A change replaces the file whole, again without leftovers.
        self::assertTrue($this->file->write([self::user('carol')]));
        self::assertStringContainsString('"carol"', (string) file_get_contents($path));
        self::assertStringNotContainsString('"alice"', (string) file_get_contents($path));
        self::assertSame(['users.yml'], array_values(array_diff(scandir(\dirname($path)) ?: [], ['.', '..'])));
        self::assertSame(0o640, fileperms($path) & 0o777);
    }

    public function testModeIsNotAffectedByAHostileUmask(): void
    {
        $previous = umask(0o000);
        try {
            $this->file->write([self::user()]);
        } finally {
            umask($previous);
        }

        self::assertSame(0o640, fileperms($this->paths->usersFile()) & 0o777);
    }

    public function testUnchangedContentIsNotRewritten(): void
    {
        $path = $this->paths->usersFile();
        self::assertTrue($this->file->write([self::user()]));
        $old = (int) strtotime('2001-01-01 00:00:00 UTC');
        touch($path, $old);
        clearstatcache();
        $inode = fileinode($path);

        self::assertFalse($this->file->write([self::user()]));

        clearstatcache();
        self::assertSame($inode, fileinode($path));
        self::assertSame($old, filemtime($path));
    }

    public function testUnchangedContentStillTightensALooseMode(): void
    {
        $path = $this->paths->usersFile();
        $this->file->write([self::user()]);
        chmod($path, 0o666);
        clearstatcache();
        $inode = fileinode($path);

        self::assertFalse($this->file->write([self::user()]));

        clearstatcache();
        self::assertSame(0o640, fileperms($path) & 0o777);
        self::assertSame($inode, fileinode($path));
    }

    public function testRefusedUsersLeaveTheExistingFileAlone(): void
    {
        $this->file->write([self::user()]);
        $before = (string) file_get_contents($this->paths->usersFile());

        try {
            $this->file->write([self::user(), self::user()]);
            self::fail('duplicate usernames must be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($before, file_get_contents($this->paths->usersFile()));
    }
}
