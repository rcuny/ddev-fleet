<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

use App\Fleet\Projects\ValidationFailed;

/**
 * The rules for a path inside a project's assets directory, as text. A path is relative, uses `/` between segments and
 * names only things below the project's directory: the empty path is the directory itself. Everything else is refused
 * (allow-list, not a clean-up): an absolute path, an empty, `.` or `..` segment, a NUL, a backslash, a control or
 * format character (so no right-to-left override), a name that is too long or too deep, and the names the upload
 * service keeps for itself. Messages never contain the submitted value.
 *
 * This class only reads strings. Whether a path leaves the directory through a symlink is decided by AssetStore, which
 * looks at the filesystem.
 */
final class AssetPath
{
    public const int MAX_NAME_BYTES = 255;
    public const int MAX_DEPTH = 8;
    public const int MAX_PATH_BYTES = 1024;

    /** The temporary files the upload service assembles next to the target: hidden, never listed, never a valid name. */
    public const string TEMP_PREFIX = '.fleet-upload-';

    // Letters, digits and combining marks of any script, then a few punctuation characters. A name may start with a dot
    // (`.env`), but not with a space, and it may not be `.` or `..` (checked apart).
    private const string NAME_PATTERN = '/^[\p{L}\p{N}_.][\p{L}\p{M}\p{N}_. ()+@,=~\-]*$/uD';

    private function __construct()
    {
    }

    /**
     * @return list<string> the segments; empty for the project's directory itself
     *
     * @throws ValidationFailed field `path`
     */
    public static function segments(string $path): array
    {
        if ('' === $path) {
            return [];
        }
        if (str_contains($path, "\0")) {
            throw self::refuse('path', 'The path contains a NUL character.');
        }
        if (\strlen($path) > self::MAX_PATH_BYTES) {
            throw self::refuse('path', \sprintf('The path is longer than %d bytes.', self::MAX_PATH_BYTES));
        }
        if (str_starts_with($path, '/')) {
            throw self::refuse('path', 'The path must be relative to the project\'s assets directory.');
        }

        $segments = explode('/', $path);
        if (\count($segments) > self::MAX_DEPTH) {
            throw self::refuse('path', \sprintf('The path is more than %d folders deep.', self::MAX_DEPTH));
        }
        foreach ($segments as $segment) {
            self::checkName($segment, 'path');
        }

        return $segments;
    }

    /**
     * A single file or folder name.
     *
     * @throws ValidationFailed field `name`
     */
    public static function name(string $name): string
    {
        self::checkName($name, 'name');

        return $name;
    }

    /**
     * @param list<string> $segments
     */
    public static function join(array $segments): string
    {
        return implode('/', $segments);
    }

    /**
     * Whether a name found on disk is one the browser may address (a file copied in over SSH can have any name).
     */
    public static function isUsableName(string $name): bool
    {
        try {
            self::checkName($name, 'name');
        } catch (ValidationFailed) {
            return false;
        }

        return true;
    }

    private static function checkName(string $name, string $field): void
    {
        if ('' === $name) {
            throw self::refuse($field, 'The '.$field.' is empty or has an empty part.');
        }
        if (str_contains($name, "\0")) {
            throw self::refuse($field, 'The '.$field.' contains a NUL character.');
        }
        if ('.' === $name || '..' === $name) {
            throw self::refuse($field, 'The '.$field.' must not contain "." or ".." parts.');
        }
        if (\strlen($name) > self::MAX_NAME_BYTES) {
            throw self::refuse($field, \sprintf('A name is longer than %d bytes.', self::MAX_NAME_BYTES));
        }
        if (str_starts_with($name, self::TEMP_PREFIX)) {
            throw self::refuse($field, 'That name is reserved.');
        }
        if (1 !== preg_match(self::NAME_PATTERN, $name) || str_ends_with($name, ' ')) {
            throw self::refuse($field, 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.');
        }
    }

    private static function refuse(string $field, string $message): ValidationFailed
    {
        return new ValidationFailed([$field => $message]);
    }
}
