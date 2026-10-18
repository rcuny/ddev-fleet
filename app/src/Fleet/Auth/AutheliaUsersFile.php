<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;

/**
 * Keeps FleetPaths::usersFile() (Authelia's user database) equal to the users in the database. The file is
 * Fleet-owned: Authelia only reads it (self-service password reset stays disabled until the hash read-back of M0.10).
 *
 * Mode 0640 and the group come from the installer's directory (fleet:authelia 2750, setgid), so a file created or
 * renamed into place is readable by Authelia without this class knowing the group's name.
 */
final readonly class AutheliaUsersFile
{
    public const int MODE = 0o640;

    public function __construct(
        private FleetPaths $paths,
        private AutheliaUsersRenderer $renderer,
    ) {
    }

    /**
     * Brings the file in line with the database. The lock is taken first, then the users are read, rendered and
     * written, so two concurrent callers cannot write a stale snapshot over a newer one.
     *
     * @return bool true when the file changed; false (file untouched) when it already had this content
     */
    public function sync(UserRepository $users): bool
    {
        return $this->locked(fn (): bool => $this->install($this->renderer->render($users->allFresh())));
    }

    /**
     * Writes exactly these users (lock held around render and write). Callers that read the users themselves cannot
     * rule out a concurrent writer; prefer sync().
     *
     * @param list<User> $users
     *
     * @return bool true when the file changed; false (file untouched) when it already had this content
     */
    public function write(array $users): bool
    {
        return $this->locked(fn (): bool => $this->install($this->renderer->render($users)));
    }

    /**
     * @param callable(): bool $work
     */
    private function locked(callable $work): bool
    {
        $path = $this->paths->usersFile();
        $this->ensureDir(\dirname($path), 0o750);
        $this->ensureDir($this->paths->locksDir(), 0o755);
        $lock = @fopen($this->paths->locksDir().'/authelia-users.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the Authelia users file.');
        }

        try {
            return $work();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function install(string $contents): bool
    {
        $path = $this->paths->usersFile();
        $current = is_file($path) ? @file_get_contents($path) : false;
        if ($current === $contents) {
            // Same content: no new inode, no new mtime. Only a loosened mode is corrected.
            if ((@fileperms($path) & 0o777) !== self::MODE && !@chmod($path, self::MODE)) {
                throw new \RuntimeException('Cannot set the mode of the Authelia users file.');
            }

            return false;
        }
        AtomicFile::write($path, $contents, self::MODE);

        return true;
    }

    private function ensureDir(string $dir, int $mode): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }
    }
}
