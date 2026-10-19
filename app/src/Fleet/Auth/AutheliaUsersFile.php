<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\PasswordHash;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\System\AutheliaNotifier;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps FleetPaths::usersFile() (Authelia's user database) equal to the users in the database. The database is the
 * source of truth, with one exception: Authelia's self-service password reset rewrites a hash in the file. Before every
 * render sync() reads those hashes back (D5, "adopt"), so a later render does not undo a reset.
 *
 * Read-back rule, per user, all under the render lock: the hash in the file differs from the copy Fleet last wrote
 * (FleetPaths::usersRenderedFile()) AND the database still holds the hash Fleet last wrote (so nobody changed it in
 * Fleet meanwhile) AND the new hash is argon2id. When Fleet changed the password itself the same moment, Fleet wins.
 * A user missing from the file, from the database or from the last copy is never adopted; with no last copy there is
 * no baseline and nothing is adopted (the first render after an upgrade only creates it).
 *
 * Mode 0640 and the group come from the installer's directory (fleet:authelia 2750, setgid), so a file created or
 * renamed into place is readable by Authelia without this class knowing the group's name. When the self-service reset
 * is enabled (an SMTP notifier is configured) Authelia must also write the file, so it is 0660.
 */
final readonly class AutheliaUsersFile
{
    public const int MODE = 0o640;
    public const int MODE_RESET_ENABLED = 0o660;

    /** The copy of what Fleet wrote is private: it is only read by this class. */
    private const int RENDERED_COPY_MODE = 0o600;

    public function __construct(
        private FleetPaths $paths,
        private AutheliaUsersRenderer $renderer,
        private ?AutheliaNotifier $notifier = null,
    ) {
    }

    /**
     * Brings the file in line with the database, after adopting the hashes Authelia changed. The lock is taken first,
     * then the users are read, rendered and written, so two concurrent callers cannot write a stale snapshot over a
     * newer one. Adopted hashes are flushed to the database before the render; a caller that wraps this in a
     * transaction (UserRepository::transactional) gets them committed or rolled back with its own change.
     *
     * @return bool true when the file changed; false (file untouched) when it already had this content
     */
    public function sync(UserRepository $users): bool
    {
        return $this->syncWithReport($users)->changed;
    }

    /**
     * Renders the users as stored now without writing anything: throws what sync() would throw for a row that cannot be
     * rendered. Lets a caller refuse a change before it commits and write the file after (see IdentitySync::commit()).
     * Pending changes must be flushed first.
     *
     * @throws \InvalidArgumentException a user that cannot be rendered
     */
    public function check(UserRepository $users): void
    {
        $this->renderer->render($users->allFresh());
    }

    public function syncWithReport(UserRepository $users): UsersSyncResult
    {
        return $this->locked(function () use ($users): UsersSyncResult {
            $all = $users->allFresh();
            $adopted = $this->adopt($all);
            if ([] !== $adopted) {
                $users->flush();
            }
            $rendered = $this->renderer->render($all);

            return new UsersSyncResult($this->install($rendered), array_map(static fn (User $user): string => $user->username(), $adopted));
        });
    }

    /**
     * Writes exactly these users (lock held around render and write), without the read-back: the caller gave the
     * users, so there is no database to adopt into. The last-written copy is replaced, so what Authelia changed
     * before this call is not adopted afterwards. Callers that read the users themselves cannot rule out a concurrent
     * writer; prefer sync().
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
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $path = $this->paths->usersFile();
        $this->ensureDir(\dirname($path), 0o750);
        $this->ensureDir($this->paths->varDir(), 0o750);
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

    /**
     * Takes over the hashes Authelia changed (see the class comment). Changes the entities only; the caller flushes.
     *
     * @param list<User> $users
     *
     * @return list<User> the users whose hash was adopted
     */
    private function adopt(array $users): array
    {
        $inFile = $this->hashesIn($this->paths->usersFile());
        $lastWritten = $this->hashesIn($this->paths->usersRenderedFile());
        if ([] === $inFile || [] === $lastWritten) {
            return [];
        }

        $adopted = [];
        foreach ($users as $user) {
            $name = $user->username();
            if (!isset($inFile[$name], $lastWritten[$name])) {
                continue;
            }
            $theirs = $inFile[$name];
            if (hash_equals($lastWritten[$name], $theirs)) {
                continue; // Authelia did not touch it.
            }
            if (!hash_equals($lastWritten[$name], $user->passwordHash())) {
                continue; // Fleet changed the password since the last render: Fleet wins.
            }
            if (!PasswordHash::isArgon2id($theirs)) {
                continue; // Never let a hand edit or another algorithm into the database.
            }
            $user->changePasswordHash($theirs);
            $adopted[] = $user;
        }

        return $adopted;
    }

    /**
     * username => password hash of a users.yml. A missing, unreadable or malformed file has none.
     *
     * @return array<string, string>
     */
    private function hashesIn(string $path): array
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if (false === $contents) {
            return [];
        }
        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException) {
            return [];
        }

        $hashes = [];
        $entries = \is_array($parsed) && \is_array($parsed['users'] ?? null) ? $parsed['users'] : [];
        foreach ($entries as $username => $entry) {
            if (\is_array($entry) && \is_string($entry['password'] ?? null)) {
                $hashes[(string) $username] = $entry['password'];
            }
        }

        return $hashes;
    }

    private function mode(): int
    {
        return $this->notifier?->isConfigured() ? self::MODE_RESET_ENABLED : self::MODE;
    }

    private function install(string $contents): bool
    {
        $path = $this->paths->usersFile();
        $mode = $this->mode();
        $current = is_file($path) ? @file_get_contents($path) : false;
        if ($current === $contents) {
            // Same content: no new inode, no new mtime. Only a changed mode is corrected.
            if ((@fileperms($path) & 0o777) !== $mode && !@chmod($path, $mode)) {
                throw new \RuntimeException('Cannot set the mode of the Authelia users file.');
            }
            $changed = false;
        } else {
            AtomicFile::write($path, $contents, $mode);
            $changed = true;
        }
        $this->rememberWritten($contents);

        return $changed;
    }

    /**
     * Keeps a copy of what was written, for the next read-back. Replaced only when it differs.
     */
    private function rememberWritten(string $contents): void
    {
        $copy = $this->paths->usersRenderedFile();
        if (is_file($copy) && @file_get_contents($copy) === $contents) {
            return;
        }
        AtomicFile::write($copy, $contents, self::RENDERED_COPY_MODE);
    }

    private function ensureDir(string $dir, int $mode): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
        }
    }
}
