<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

use App\Fleet\FleetPaths;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Projects\ValidationFailed;

/**
 * Everything the app does to the files in a project's assets directory (`assets/<project>/` under FLEET_HOME): list,
 * make a folder, delete, open for reading, and the two steps that put an uploaded file in place. It reads the
 * filesystem every time (there is no index), so a file copied in over SSH shows up on the next listing.
 *
 * Path safety, the one place it lives: a path is checked by AssetPath (text), then walked here from the project's
 * directory one segment at a time with lstat(). A symbolic link anywhere on the way (the project's directory included)
 * is refused and never followed; nothing is ever written through one (a file is put in place by rename() or link(),
 * which replace a link instead of following it). An open for reading is checked again after fopen() so a swap between
 * the check and the open is caught.
 *
 * The callers have already checked that the project exists.
 */
final readonly class AssetStore
{
    /** Folder items read and shown at most. */
    public const int MAX_ENTRIES = 2000;
    /** Mode of the folders created here (the files are 0640). */
    public const int DIR_MODE = 0o750;
    public const int FILE_MODE = 0o640;
    /** `Refused::$reason` of a name that is taken by a regular file: the only clash an upload may replace. */
    public const string REASON_FILE_EXISTS = 'file-exists';
    /** Seconds after which a hidden upload temporary file counts as the leftover of a killed assembly. */
    public const int STALE_TEMPORARY_AFTER = 3600;

    private const int TYPE_MASK = 0o170000;
    private const int TYPE_DIR = 0o040000;
    private const int TYPE_FILE = 0o100000;
    private const int TYPE_LINK = 0o120000;

    public function __construct(private FleetPaths $paths)
    {
    }

    /**
     * @throws NotFound         the folder does not exist
     * @throws Refused          a symbolic link is on the way
     * @throws ValidationFailed
     */
    public function list(string $project, string $dir = ''): AssetListing
    {
        $segments = AssetPath::segments($dir);
        $absolute = $this->existingDirectory($project, $segments, true);
        if (null === $absolute) {
            // The project has not got an assets directory yet: nothing was ever uploaded or copied in.
            return new AssetListing([], [], false);
        }

        $handle = @opendir($absolute);
        if (false === $handle) {
            throw new Refused('The folder cannot be read.');
        }
        $names = [];
        $truncated = false;
        while (false !== ($name = readdir($handle))) {
            if ('.' === $name || '..' === $name || str_starts_with($name, AssetPath::TEMP_PREFIX)) {
                continue;
            }
            if (\count($names) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $names[] = $name;
        }
        closedir($handle);

        $entries = [];
        foreach ($names as $name) {
            $stat = @lstat($absolute.'/'.$name);
            if (false === $stat) {
                continue;
            }
            $type = $stat['mode'] & self::TYPE_MASK;
            $kind = match ($type) {
                self::TYPE_DIR => AssetEntry::DIRECTORY,
                self::TYPE_FILE => AssetEntry::FILE,
                self::TYPE_LINK => AssetEntry::LINK,
                default => null,
            };
            if (null === $kind) {
                continue;
            }
            $usable = AssetPath::isUsableName($name) && AssetEntry::LINK !== $kind && \count($segments) < AssetPath::MAX_DEPTH;
            $link = AssetEntry::LINK === $kind ? $this->describeLink($project, $segments, $name) : null;
            $entries[] = new AssetEntry(
                self::printable($name),
                $usable ? AssetPath::join([...$segments, $name]) : '',
                $kind,
                AssetEntry::FILE === $kind ? $stat['size'] : null,
                $stat['mtime'],
                $usable,
                $link->raw ?? '',
                $link?->isAllowed() ?? false,
                $link->refusal ?? '',
            );
        }
        usort($entries, static fn (AssetEntry $a, AssetEntry $b): int => [!$a->isDirectory(), strtolower($a->name), $a->name] <=> [!$b->isDirectory(), strtolower($b->name), $b->name]);

        return new AssetListing($segments, $entries, $truncated);
    }

    /**
     * What deploy seeding makes of a link (a name or folder it never seeds is refused by the resolver's walk).
     *
     * @param list<string> $segments the folder holding the link
     */
    private function describeLink(string $project, array $segments, string $name): AssetLink
    {
        $root = $this->paths->assetsDir($project);
        if (is_link($root) || !is_dir($root) || !AssetPath::isUsableName($name)) {
            return AssetLink::refused('not seeded', '');
        }
        foreach ($segments as $segment) {
            if (!AssetPath::isUsableName($segment)) {
                return AssetLink::refused('in a folder that is never seeded', '');
            }
        }

        return AssetLinkResolver::resolve($root, implode('/', [...$segments, $name]));
    }

    /**
     * Makes the folder `$name` inside `$dir` (the folders above it are created too when missing).
     *
     * @return string the new folder's path
     *
     * @throws Refused          it exists already, or a symbolic link is in the way
     * @throws ValidationFailed
     */
    public function createFolder(string $project, string $dir, string $name): string
    {
        $segments = AssetPath::segments($dir);
        $name = AssetPath::name($name);
        if (\count($segments) >= AssetPath::MAX_DEPTH) {
            throw new ValidationFailed(['path' => \sprintf('The path is more than %d folders deep.', AssetPath::MAX_DEPTH)]);
        }
        $parent = $this->ensureDirectory($project, $segments);

        $target = $parent.'/'.$name;
        if (false !== @lstat($target)) {
            throw new Refused('A file or folder with that name exists already.', 409);
        }
        if (!@mkdir($target, self::DIR_MODE) && !is_dir($target)) {
            throw new Refused('The folder could not be created.');
        }
        @chmod($target, self::DIR_MODE);

        return AssetPath::join([...$segments, $name]);
    }

    /**
     * Deletes a file (or a symbolic link, itself) or an empty folder.
     *
     * @throws NotFound
     * @throws Refused          a folder that is not empty
     * @throws ValidationFailed
     */
    public function delete(string $project, string $path): void
    {
        $segments = AssetPath::segments($path);
        if ([] === $segments) {
            throw new ValidationFailed(['path' => 'Choose a file or a folder.']);
        }
        $absolute = $this->existing($project, $segments, true);

        $stat = @lstat($absolute);
        if (false === $stat) {
            throw new NotFound('That file or folder does not exist.');
        }
        if (self::TYPE_DIR === ($stat['mode'] & self::TYPE_MASK)) {
            $names = @scandir($absolute);
            if (false === $names) {
                throw new Refused('The folder could not be read.');
            }
            $stale = [];
            foreach ($names as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                if (!$this->isStaleTemporary($absolute.'/'.$entry, $entry)) {
                    throw new Refused('The folder is not empty. Delete what is in it first.', 409);
                }
                $stale[] = $absolute.'/'.$entry;
            }
            // Only hidden leftovers of an assembly that was killed: they are invisible in the listing, so they go with the folder.
            foreach ($stale as $leftover) {
                @unlink($leftover);
            }
            if (!@rmdir($absolute)) {
                throw new Refused('The folder could not be deleted.');
            }

            return;
        }
        if (!@unlink($absolute)) {
            throw new Refused('The file could not be deleted.');
        }
    }

    /**
     * @throws NotFound
     * @throws Refused          not a regular file, or a symbolic link is on the way
     * @throws ValidationFailed
     */
    public function open(string $project, string $path): AssetFile
    {
        $segments = AssetPath::segments($path);
        if ([] === $segments) {
            throw new ValidationFailed(['path' => 'Choose a file.']);
        }
        $absolute = $this->existing($project, $segments, false);

        $before = @lstat($absolute);
        if (false === $before) {
            throw new NotFound('That file does not exist.');
        }
        if (self::TYPE_FILE !== ($before['mode'] & self::TYPE_MASK)) {
            throw new Refused('That is not a file.');
        }
        $handle = @fopen($absolute, 'r');
        if (false === $handle) {
            throw new Refused('The file cannot be read.');
        }
        // fopen() follows a link: make sure the thing opened is the regular file that was checked, not one swapped in since.
        $opened = fstat($handle);
        $now = self::statOf($absolute);
        if (false === $opened || null === $now || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
            || $now['ino'] !== $before['ino'] || $now['dev'] !== $before['dev'] || self::TYPE_FILE !== ($now['mode'] & self::TYPE_MASK)) {
            fclose($handle);
            throw new Refused('The file changed while it was being opened.');
        }

        return new AssetFile($handle, $segments[\count($segments) - 1], $opened['size']);
    }

    /**
     * The first step of putting an uploaded file in place: makes the folder when missing and returns where the
     * temporary file is assembled and where the file will end up. Refuses a symbolic link on the way, a folder or a
     * link at the target, and (unless $overwrite) a file that exists already.
     *
     * @return array{string, string} [directory, target file]
     *
     * @throws Refused
     * @throws ValidationFailed
     */
    public function prepareTarget(string $project, string $dir, string $name, bool $overwrite): array
    {
        $segments = AssetPath::segments($dir);
        $name = AssetPath::name($name);
        $directory = $this->ensureDirectory($project, $segments);
        $target = $directory.'/'.$name;
        $this->checkTarget($target, $overwrite);

        return [$directory, $target];
    }

    /**
     * The last step: moves the assembled file onto its target. Atomic. Without $overwrite it never replaces anything
     * (link() fails when the target exists); with it, rename() replaces a file, and a link at the target is replaced,
     * not followed.
     *
     * @throws Refused
     */
    public function publish(string $temporary, string $target, bool $overwrite): void
    {
        $this->checkTarget($target, $overwrite);
        @chmod($temporary, self::FILE_MODE);
        if ($overwrite) {
            if (!@rename($temporary, $target)) {
                throw new Refused('The file could not be put in place.');
            }

            return;
        }
        if (!@link($temporary, $target)) {
            throw new Refused('A file with that name exists already.', 409);
        }
        @unlink($temporary);
    }

    /**
     * A hidden temporary file of an upload assembly that has not been touched for STALE_TEMPORARY_AFTER seconds: the
     * assembly was killed (one that runs writes to it continuously, and php-fpm cuts a request after 240 s).
     */
    private function isStaleTemporary(string $path, string $name): bool
    {
        if (!str_starts_with($name, AssetPath::TEMP_PREFIX) || !str_ends_with($name, '.tmp')) {
            return false;
        }
        $stat = @lstat($path);

        return false !== $stat && self::TYPE_FILE === ($stat['mode'] & self::TYPE_MASK) && time() - $stat['mtime'] > self::STALE_TEMPORARY_AFTER;
    }

    /**
     * @throws Refused
     */
    private function checkTarget(string $target, bool $overwrite): void
    {
        $stat = @lstat($target);
        if (false === $stat) {
            return;
        }
        $type = $stat['mode'] & self::TYPE_MASK;
        if (self::TYPE_FILE !== $type) {
            throw new Refused(self::TYPE_DIR === $type ? 'A folder with that name exists.' : 'A symbolic link or special file with that name exists.', 409);
        }
        if (!$overwrite) {
            throw new Refused('A file with that name exists already.', 409, self::REASON_FILE_EXISTS);
        }
    }

    /**
     * The absolute path of an existing part of the tree, every step checked with lstat().
     *
     * @param list<string> $segments
     *
     * @throws NotFound
     * @throws Refused  a symbolic link on the way (the last part may be one when $lastMayBeLink)
     */
    private function existing(string $project, array $segments, bool $lastMayBeLink): string
    {
        $current = $this->projectDirectory($project) ?? throw new NotFound('That file or folder does not exist.');
        $last = \count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $current .= '/'.$segment;
            $stat = @lstat($current);
            if (false === $stat) {
                throw new NotFound('That file or folder does not exist.');
            }
            $type = $stat['mode'] & self::TYPE_MASK;
            if (self::TYPE_LINK === $type) {
                if ($lastMayBeLink && $i === $last) {
                    return $current;
                }
                throw new Refused('A symbolic link is in the way. The browser never follows symbolic links.');
            }
            if ($i < $last && self::TYPE_DIR !== $type) {
                throw new NotFound('That file or folder does not exist.');
            }
        }

        return $current;
    }

    /**
     * @param list<string> $segments
     *
     * @return string|null the directory; null when the project's directory is missing and $emptyOk
     *
     * @throws NotFound
     * @throws Refused
     */
    private function existingDirectory(string $project, array $segments, bool $emptyOk): ?string
    {
        if (null === $this->projectDirectory($project)) {
            if ($emptyOk && [] === $segments) {
                return null;
            }
            throw new NotFound('That folder does not exist.');
        }
        $absolute = [] === $segments ? $this->paths->assetsDir($project) : $this->existing($project, $segments, false);
        $stat = @lstat($absolute);
        if (false === $stat || self::TYPE_DIR !== ($stat['mode'] & self::TYPE_MASK)) {
            throw new NotFound('That folder does not exist.');
        }

        return $absolute;
    }

    /**
     * Like existingDirectory(), but creates what is missing (the project's directory included).
     *
     * @param list<string> $segments
     *
     * @throws Refused
     */
    private function ensureDirectory(string $project, array $segments): string
    {
        $current = $this->paths->assetsDir($project);
        $this->makeDirectory($current);
        foreach ($segments as $segment) {
            $current .= '/'.$segment;
            $this->makeDirectory($current);
        }

        return $current;
    }

    private function makeDirectory(string $directory): void
    {
        $stat = self::statOf($directory);
        if (null === $stat) {
            if (!@mkdir($directory, self::DIR_MODE, true) && !is_dir($directory)) {
                throw new Refused('The folder could not be created.');
            }
            @chmod($directory, self::DIR_MODE);
            // Another request may have raced us with a link in its place: look again.
            $stat = self::statOf($directory);
        }
        $type = null === $stat ? 0 : $stat['mode'] & self::TYPE_MASK;
        if (self::TYPE_LINK === $type) {
            throw new Refused('A symbolic link is in the way. The browser never follows symbolic links.');
        }
        if (self::TYPE_DIR !== $type) {
            throw new Refused('A file is in the way of that folder.', 409);
        }
    }

    /**
     * The project's directory when it exists as a real directory; null when it does not exist.
     *
     * @throws Refused it is a symbolic link or not a directory
     */
    private function projectDirectory(string $project): ?string
    {
        $directory = $this->paths->assetsDir($project);
        $stat = @lstat($directory);
        if (false === $stat) {
            return null;
        }
        $type = $stat['mode'] & self::TYPE_MASK;
        if (self::TYPE_LINK === $type) {
            throw new Refused('The project\'s assets directory is a symbolic link. The browser never follows symbolic links.');
        }
        if (self::TYPE_DIR !== $type) {
            throw new Refused('The project\'s assets directory is not a directory.');
        }

        return $directory;
    }

    /**
     * lstat() without following a link; null when there is nothing at the path.
     *
     * @return array<int|string, int>|null
     */
    private static function statOf(string $path): ?array
    {
        $stat = @lstat($path);

        return false === $stat ? null : $stat;
    }

    private static function printable(string $name): string
    {
        $clean = mb_scrub($name, 'UTF-8');

        return preg_replace('/[\x00-\x1F\x7F]/', '?', $clean) ?? '?';
    }
}
