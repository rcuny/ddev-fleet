<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The repositories the fleet may clone: an allow-list of exactly three shapes, never a deny-list.
 *
 *  1. `https://host[:port]/path`, no credentials;
 *  2. `ssh://[user@]host[:port]/path`, no password;
 *  3. scp-like `[user@]host:path`.
 *
 * Everything git would also accept and that is dangerous to hand it is outside these shapes: `ext::`/`file://`/`git://`/
 * `http://`/local paths, credentials in the URL (use deploy keys), a leading dash anywhere an option could be read,
 * whitespace and control characters, query and fragment, `..` segments, non-ASCII, IPv6 literals. A valid URL is
 * necessary, not sufficient: the clone must still run as argv (`git clone -- <url> <dir>`), never through a shell.
 * Hosts that resolve to loopback or link-local addresses pass as ordinary names (the input is admin-only).
 */
final class GitUrl
{
    public const int MAX = 2048;

    private const string HOST = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';
    private const string SSH_USER = '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/D';
    private const string PATH = '/^[A-Za-z0-9._~+@%\/-]+$/D';

    public static function error(mixed $url): ?string
    {
        if (!\is_string($url) || '' === $url) {
            return 'Enter the repository URL.';
        }
        if (\strlen($url) > self::MAX) {
            return \sprintf('The URL must be at most %d characters.', self::MAX);
        }
        if (1 !== preg_match('/^[\x21-\x7E]+$/D', $url)) {
            return 'The URL must be plain ASCII without spaces or control characters.';
        }
        if ('-' === $url[0]) {
            return 'The URL must not start with a dash.';
        }

        if (str_starts_with($url, 'https://')) {
            return self::https(substr($url, 8));
        }
        if (str_starts_with($url, 'ssh://')) {
            return self::ssh(substr($url, 6));
        }
        if (str_contains($url, '://')) {
            return 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';
        }

        return self::scp($url);
    }

    private static function https(string $rest): ?string
    {
        [$authority, $path] = self::split($rest);
        if (str_contains($authority, '@')) {
            return 'Do not put credentials in the URL: use the deploy key.';
        }
        if (null !== $error = self::hostAndPort($authority)) {
            return $error;
        }

        return self::path($path, false);
    }

    private static function ssh(string $rest): ?string
    {
        [$authority, $path] = self::split($rest);
        $parts = explode('@', $authority);
        if (\count($parts) > 2) {
            return 'The URL is not valid.';
        }
        if (2 === \count($parts)) {
            $user = $parts[0];
            $authority = $parts[1];
            if (1 !== preg_match(self::SSH_USER, $user)) {
                return 'The ssh user is not valid (no password in the URL; use the deploy key).';
            }
        }
        if (null !== $error = self::hostAndPort($authority)) {
            return $error;
        }

        return self::path($path, false);
    }

    private static function scp(string $url): ?string
    {
        $colon = strpos($url, ':');
        if (false === $colon) {
            return 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';
        }
        $authority = substr($url, 0, $colon);
        $path = substr($url, $colon + 1);
        // `https:/host/x` (a mistyped scheme) would otherwise read as host "https"
        if (\in_array(strtolower($authority), ['http', 'https', 'ssh', 'git', 'file', 'ftp', 'ext'], true)) {
            return 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';
        }
        $parts = explode('@', $authority);
        if (\count($parts) > 2) {
            return 'The URL is not valid.';
        }
        if (2 === \count($parts)) {
            if (1 !== preg_match(self::SSH_USER, $parts[0])) {
                return 'The ssh user is not valid.';
            }
            $authority = $parts[1];
        }
        if (1 !== preg_match(self::HOST, $authority) || !self::hostIsAcceptable($authority)) {
            return 'The host name is not valid.';
        }

        return self::path($path, true);
    }

    /**
     * @return array{string, string} the authority and the path (with its leading slash), '' when there is none
     */
    private static function split(string $rest): array
    {
        $slash = strpos($rest, '/');

        return false === $slash ? [$rest, ''] : [substr($rest, 0, $slash), substr($rest, $slash)];
    }

    private static function hostAndPort(string $authority): ?string
    {
        $host = $authority;
        if (false !== $colon = strpos($authority, ':')) {
            $host = substr($authority, 0, $colon);
            $port = substr($authority, $colon + 1);
            if (1 !== preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
                return 'The port must be a number between 1 and 65535.';
            }
        }
        if (1 !== preg_match(self::HOST, $host) || !self::hostIsAcceptable($host)) {
            return 'The host name is not valid.';
        }

        return null;
    }

    /**
     * A host whose last label is all digits must be a complete dotted quad (`10.0.0.5`): single numbers and short forms
     * (`2130706433`, `127.1`, `0x7f.1`) are read by resolvers as addresses the admin did not obviously write.
     */
    private static function hostIsAcceptable(string $host): bool
    {
        $labels = explode('.', $host);
        if (1 !== preg_match('/^[0-9]+$/D', $labels[array_key_last($labels)])) {
            return true;
        }
        if (4 !== \count($labels)) {
            return false;
        }
        foreach ($labels as $label) {
            if (1 !== preg_match('/^(0|[1-9][0-9]{0,2})$/D', $label) || (int) $label > 255) {
                return false;
            }
        }

        return true;
    }

    private static function path(string $path, bool $scp): ?string
    {
        if (1 !== preg_match(self::PATH, $path) || '' === trim($path, '/')) {
            return 'The repository path is missing or contains characters that are not allowed.';
        }
        if ($scp && '-' === $path[0]) {
            return 'The repository path must not start with a dash.';
        }
        if (1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) {
            return 'The repository path contains an invalid percent escape.';
        }
        $decoded = rawurldecode($path);
        if (1 === preg_match('/[\x00-\x20\x7f\\\\?#]/', $decoded)) {
            return 'The repository path contains characters that are not allowed.';
        }
        foreach ([$path, $decoded] as $candidate) {
            if (\in_array('..', explode('/', $candidate), true)) {
                return 'The repository path must not contain "..".';
            }
        }

        return null;
    }
}
