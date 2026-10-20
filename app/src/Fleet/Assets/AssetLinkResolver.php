<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

/**
 * Decides whether a symbolic link inside a project's assets directory is honoured by deploy seeding.
 *
 * A link is honoured when it ends, through at most MAX_HOPS links, at a regular file that lives inside the same
 * project's assets directory. The chain is walked one link at a time without ever following it: every hop is resolved
 * lexically against the directory of the link, must stay below the assets directory (an absolute target counts only
 * when it is below it too), and every folder on the way to the next hop must be a real folder, never a link. Refused,
 * with a reason: a target outside the assets, a dangling link, a link to a folder or a special file, a loop or a chain
 * longer than the cap, a link behind a folder link, and a target no deploy would copy (an unusable name, `.git`).
 *
 * The browser (AssetStore) still never follows a link; this class only reads.
 */
final class AssetLinkResolver
{
    public const int MAX_HOPS = 4;

    private const int TYPE_MASK = 0o170000;
    private const int TYPE_DIR = 0o040000;
    private const int TYPE_FILE = 0o100000;
    private const int TYPE_LINK = 0o120000;

    /**
     * @param string $root a project's assets directory (not a link)
     * @param string $path the link, relative to $root, with `/` separators
     */
    public static function resolve(string $root, string $path): AssetLink
    {
        $raw = @readlink($root.'/'.$path);
        if (false === $raw) {
            return AssetLink::refused('the link cannot be read', '');
        }
        $shown = self::printable($raw);

        $current = $path;
        for ($hop = 0; $hop < self::MAX_HOPS; ++$hop) {
            $target = @readlink($root.'/'.$current);
            if (false === $target) {
                return AssetLink::refused('the link cannot be read', $shown);
            }
            if (str_starts_with($target, '/')) {
                $prefix = rtrim($root, '/').'/';
                if (!str_starts_with($target, $prefix)) {
                    return AssetLink::refused('points outside the project\'s assets', $shown);
                }
                $target = substr($target, \strlen($prefix));
            } else {
                $dir = false === ($slash = strrpos($current, '/')) ? '' : substr($current, 0, $slash);
                $target = '' === $dir ? $target : $dir.'/'.$target;
            }

            $segments = self::normalise($target);
            if (null === $segments) {
                return AssetLink::refused('points outside the project\'s assets', $shown);
            }
            if ([] === $segments) {
                return AssetLink::refused('points at a folder', $shown);
            }
            foreach ($segments as $segment) {
                if ('.git' === $segment || str_starts_with($segment, AssetPath::TEMP_PREFIX) || !AssetPath::isUsableName($segment)) {
                    return AssetLink::refused('points at a name that is never seeded', $shown);
                }
            }

            $absolute = rtrim($root, '/');
            $last = \count($segments) - 1;
            foreach ($segments as $i => $segment) {
                $absolute .= '/'.$segment;
                $stat = @lstat($absolute);
                if (false === $stat) {
                    return AssetLink::refused('dangling (the target does not exist)', $shown);
                }
                $type = $stat['mode'] & self::TYPE_MASK;
                if ($i < $last) {
                    if (self::TYPE_DIR !== $type) {
                        return AssetLink::refused(self::TYPE_LINK === $type ? 'goes through a folder that is a symbolic link' : 'goes through something that is not a folder', $shown);
                    }

                    continue;
                }
                if (self::TYPE_FILE === $type) {
                    return AssetLink::allowed(implode('/', $segments), $shown);
                }
                if (self::TYPE_DIR === $type) {
                    return AssetLink::refused('points at a folder', $shown);
                }
                if (self::TYPE_LINK !== $type) {
                    return AssetLink::refused('points at a special file', $shown);
                }
            }
            $current = implode('/', $segments);
        }

        return AssetLink::refused(\sprintf('a loop or a chain of more than %d links', self::MAX_HOPS), $shown);
    }

    /**
     * @return list<string>|null the path without `.` and `..`; null when a `..` climbs above the root
     */
    private static function normalise(string $path): ?array
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                if ([] === $out) {
                    return null;
                }
                array_pop($out);

                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    private static function printable(string $text): string
    {
        return preg_replace('/[^\P{C}]/u', '?', $text) ?? '?';
    }
}
