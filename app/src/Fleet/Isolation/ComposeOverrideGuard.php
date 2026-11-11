<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The deploy guard (FLE-298, audit finding F3): reads the DDEV files of an instance checkout, the project's own and the
 * ones fleet wrote, and reports what would hand a web container the host. A project repo that ships a compose override
 * with the Docker socket is otherwise root on the host.
 *
 * Scanned: `.ddev/docker-compose*.y(a)ml` and `.ddev/config*.y(a)ml`. Refused:
 * - any string that names `docker.sock` (a mount, a `chmod` in a hook, an environment value);
 * - a service with `privileged: true`, `network_mode`, `pid`, `ipc`, `userns_mode` or `uts` set to `host`, `cap_add` of
 *   SYS_ADMIN, NET_ADMIN or ALL, any `devices`, or `security_opt` that switches seccomp or AppArmor off;
 * - a bind mount (short or long syntax, or a named volume with `driver_opts` of type bind) whose host side is outside the
 *   instance directory and the allowed paths. Relative sources resolve against the file's directory. A source that starts
 *   with a variable other than `${DDEV_*}` cannot be verified and is refused;
 * - a file that is a symlink or cannot be parsed (it cannot be verified).
 *
 * Pure: reads files, writes nothing.
 */
final class ComposeOverrideGuard
{
    private const array HOST_NAMESPACE_KEYS = ['network_mode', 'pid', 'ipc', 'userns_mode', 'uts'];
    private const array DANGEROUS_CAPS = ['SYS_ADMIN', 'NET_ADMIN', 'ALL'];

    /**
     * @param list<string> $allowedHostPaths absolute host paths a bind mount may use besides the instance directory
     *                                       (the instance's own ssh-agent socket directory)
     *
     * @return list<OverrideViolation>
     */
    public function scan(string $instanceDir, array $allowedHostPaths = []): array
    {
        $violations = [];
        $root = rtrim($instanceDir, '/');
        foreach ($this->files($root) as $relative) {
            $path = $root.'/'.$relative;
            if (is_link($path)) {
                $violations[] = new OverrideViolation($relative, '(file)', 'is a symbolic link, which cannot be verified');
                continue;
            }
            $text = @file_get_contents($path);
            if (false === $text) {
                $violations[] = new OverrideViolation($relative, '(file)', 'cannot be read');
                continue;
            }
            try {
                $data = Yaml::parse($text);
            } catch (ParseException $e) {
                $violations[] = new OverrideViolation($relative, '(file)', 'cannot be parsed ('.$e->getMessage().'), so it cannot be verified');
                continue;
            }
            if (!\is_array($data)) {
                continue;
            }
            $this->walkStrings($data, '', $relative, $violations);
            if (str_starts_with(basename($relative), 'docker-compose')) {
                $this->checkCompose($data, $relative, $root, $allowedHostPaths, $violations);
            }
        }

        // One entry per file and key: a socket mount is found by the key walk and by the volume check.
        $unique = [];
        foreach ($violations as $v) {
            $unique[$v->file."\0".$v->key] ??= $v;
        }

        return array_values($unique);
    }

    /**
     * @return list<string> paths relative to the checkout, sorted
     */
    private function files(string $root): array
    {
        $found = [];
        foreach (['docker-compose*.yaml', 'docker-compose*.yml', 'config*.yaml', 'config*.yml'] as $pattern) {
            foreach (glob($root.'/.ddev/'.$pattern) ?: [] as $file) {
                $found[] = '.ddev/'.basename($file);
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * @param array<mixed>            $data
     * @param list<OverrideViolation> $violations
     */
    private function walkStrings(array $data, string $prefix, string $file, array &$violations): void
    {
        foreach ($data as $key => $value) {
            $here = '' === $prefix ? (string) $key : (\is_int($key) ? $prefix.'['.$key.']' : $prefix.'.'.$key);
            if (\is_array($value)) {
                $this->walkStrings($value, $here, $file, $violations);
            } elseif (\is_string($value) && str_contains($value, 'docker.sock')) {
                $violations[] = new OverrideViolation($file, $here, 'mentions the Docker socket, which gives the container root on the host');
            }
        }
    }

    /**
     * @param array<mixed>            $data
     * @param list<string>            $allowed
     * @param list<OverrideViolation> $violations
     */
    private function checkCompose(array $data, string $file, string $root, array $allowed, array &$violations): void
    {
        $services = $data['services'] ?? [];
        foreach (\is_array($services) ? $services : [] as $name => $service) {
            if (!\is_array($service)) {
                continue;
            }
            $at = 'services.'.$name;
            if (true === ($service['privileged'] ?? false) || 'true' === strtolower((string) ($service['privileged'] ?? ''))) {
                $violations[] = new OverrideViolation($file, $at.'.privileged', 'privileged: true gives the container the host');
            }
            foreach (self::HOST_NAMESPACE_KEYS as $key) {
                if (isset($service[$key]) && \is_string($service[$key]) && 'host' === strtolower(trim($service[$key]))) {
                    $violations[] = new OverrideViolation($file, $at.'.'.$key, $key.': host shares a host namespace with the container');
                }
            }
            foreach (\is_array($service['cap_add'] ?? null) ? $service['cap_add'] : [] as $i => $cap) {
                $normalised = strtoupper(preg_replace('/^CAP_/i', '', trim((string) $cap)) ?? '');
                if (\in_array($normalised, self::DANGEROUS_CAPS, true)) {
                    $violations[] = new OverrideViolation($file, $at.'.cap_add['.$i.']', $normalised.' is a capability that escapes the container');
                }
            }
            if (!empty($service['devices'])) {
                $violations[] = new OverrideViolation($file, $at.'.devices', 'host devices must not be passed to a container');
            }
            foreach (\is_array($service['security_opt'] ?? null) ? $service['security_opt'] : [] as $i => $opt) {
                if (\is_string($opt) && 1 === preg_match('/(seccomp|apparmor)[=:]\s*unconfined/i', $opt)) {
                    $violations[] = new OverrideViolation($file, $at.'.security_opt['.$i.']', 'turns the container sandbox off');
                }
            }
            foreach (\is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $i => $volume) {
                $source = $this->bindSource($volume);
                if (null !== $source) {
                    $reason = $this->hostPathRefusal($source, $root, $allowed);
                    if (null !== $reason) {
                        $violations[] = new OverrideViolation($file, $at.'.volumes['.$i.']'.(\is_array($volume) ? '.source' : ''), $reason);
                    }
                }
            }
        }
        $top = $data['volumes'] ?? [];
        foreach (\is_array($top) ? $top : [] as $name => $volume) {
            $opts = \is_array($volume) && \is_array($volume['driver_opts'] ?? null) ? $volume['driver_opts'] : [];
            $device = $opts['device'] ?? null;
            if (\is_string($device) && str_contains((string) ($opts['o'] ?? ''), 'bind')) {
                $reason = $this->hostPathRefusal($device, $root, $allowed);
                if (null !== $reason) {
                    $violations[] = new OverrideViolation($file, 'volumes.'.$name.'.driver_opts.device', $reason);
                }
            }
        }
    }

    /**
     * The host side of a service volume when it is a bind mount, null for a named volume, tmpfs and the like.
     */
    private function bindSource(mixed $volume): ?string
    {
        if (\is_array($volume)) {
            $type = $volume['type'] ?? 'bind';
            $source = $volume['source'] ?? null;

            return 'bind' === $type && \is_string($source) ? $source : null;
        }
        if (!\is_string($volume)) {
            return null;
        }
        $source = explode(':', $volume, 2)[0];
        if (str_contains($volume, ':') && 1 === preg_match('#^(/|\.|~|\$)#', $source)) {
            return $source;
        }

        return null; // "/container/path" alone is an anonymous volume; "name:/path" a named volume
    }

    /**
     * @param list<string> $allowed
     */
    private function hostPathRefusal(string $source, string $root, array $allowed): ?string
    {
        if (str_starts_with($source, '${DDEV_') || str_starts_with($source, '$DDEV_')) {
            return null; // DDEV's own variables (docroot, approot, ...): DDEV points them at the project
        }
        if (str_starts_with($source, '$')) {
            return 'host path starts with a variable that cannot be verified';
        }
        if (str_starts_with($source, '~')) {
            return 'bind mount of a path in a home directory';
        }
        // Relative sources are relative to the compose file, which sits in .ddev/.
        $absolute = str_starts_with($source, '/') ? $source : $root.'/.ddev/'.$source;
        $resolved = self::normalise($absolute);
        foreach ([$root, ...$allowed] as $base) {
            $base = self::normalise($base);
            if ($resolved === $base || str_starts_with($resolved, $base.'/')) {
                return null;
            }
        }

        return 'bind mount of a host path outside the instance directory ('.$resolved.')';
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }
}
