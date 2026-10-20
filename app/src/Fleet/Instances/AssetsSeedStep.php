<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Assets\AssetLinkResolver;
use App\Fleet\Assets\AssetPath;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobFailed;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Seeds a new checkout from the project's assets (`/srv/fleet/assets/<project>/`), before ddev starts, like 0.x
 * `fleet.core.assets.inject`: the project's own post_deploy lines (`ddev import-db`, ...) then find the dump and the
 * files in the checkout. fleet never runs the import itself, and a files tarball is not extracted.
 *
 * - The tree is copied below the checkout. A regular text file (at most 1 MiB, no NUL, valid UTF-8) has its `[[token]]`
 *   placeholders substituted from the context tokens; an unresolved one stays as written and is logged by name.
 *   Anything else is copied untouched.
 * - `dumps/` is never substituted: its files are hard-linked (a copy when the link is refused, for example across
 *   filesystems), and a stale file at the destination is replaced.
 * - A symbolic link is honoured when AssetLinkResolver finds a regular file for it inside the same project's assets.
 *   In `dumps/`, a link to another file in `dumps/` becomes a hard link to the same inode (the dump is not copied
 *   twice, and `dumps/default.sql` is a plain file for `ddev import-db`); elsewhere it becomes a relative symbolic link
 *   to the seeded target, so the instance mirrors the assets. A refused link (outside the assets, dangling, to a
 *   folder, a loop) is skipped and logged with the reason. A special file is skipped and logged, a name the browser
 *   could not address is skipped, nothing is written into `.git`, and a destination directory that is a symbolic link fails the deploy.
 * - What was copied is reported through DeployContext::exclude(), so the project's `git status` stays clean.
 */
#[AutoconfigureTag('fleet.deploy_step', ['priority' => self::PRIORITY])]
final class AssetsSeedStep implements DeployStep
{
    /**
     * Tag priority; a higher one runs earlier within a phase. Order of the registered steps: this seed (BeforeStart,
     * 100) runs before every step added later with a lower priority; AfterStart steps (the tmux windows) run once ddev
     * is up.
     */
    public const int PRIORITY = 100;

    private const int MAX_TEXT_BYTES = 1_048_576;
    private const string DUMPS = 'dumps';

    /** The assets directory being seeded (set by run()). */
    private string $root = '';

    public function __construct(private readonly FleetPaths $paths)
    {
    }

    public function phase(): StepPhase
    {
        return StepPhase::BeforeStart;
    }

    public function run(DeployContext $context): void
    {
        $source = $this->paths->assetsDir($context->project->name());
        $stat = @lstat($source);
        if (false === $stat) {
            $context->log->line('assets: none for this project, nothing to seed');

            return;
        }
        if (!is_dir($source) || is_link($source)) {
            throw new JobFailed('assets: the project\'s assets directory is a symbolic link or not a directory; nothing was seeded.');
        }

        $counts = ['copied' => 0, 'substituted' => 0, 'linked' => 0, 'skipped' => 0];
        $this->root = $source;
        try {
            $this->copyTree($source, $context->dir, '', $context, $counts);
        } catch (\RuntimeException $e) {
            throw new JobFailed('assets: '.$e->getMessage());
        }
        $context->log->line(\sprintf(
            'assets: seeded %d file(s), %d with tokens substituted, %d dump(s) linked, %d entry(ies) skipped',
            $counts['copied'] + $counts['substituted'] + $counts['linked'],
            $counts['substituted'],
            $counts['linked'],
            $counts['skipped'],
        ));
    }

    /**
     * @param array{copied: int, substituted: int, linked: int, skipped: int} $counts
     */
    private function copyTree(string $from, string $to, string $relative, DeployContext $context, array &$counts): void
    {
        $names = @scandir($from);
        if (false === $names) {
            throw new \RuntimeException(\sprintf('cannot read the directory "%s"', '' === $relative ? '.' : $relative));
        }
        sort($names);
        $inDumps = self::DUMPS === explode('/', $relative)[0];

        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = '' === $relative ? $name : $relative.'/'.$name;
            if ('.git' === $name || !AssetPath::isUsableName($name)) {
                $context->log->line(\sprintf('assets: skipped "%s" (a name that cannot be copied)', $this->printable($path)));
                ++$counts['skipped'];

                continue;
            }
            if (str_starts_with($name, AssetPath::TEMP_PREFIX)) {
                continue;
            }

            $src = $from.'/'.$name;
            $dst = $to.'/'.$name;
            $info = @lstat($src);
            if (false === $info) {
                throw new \RuntimeException(\sprintf('cannot read "%s"', $this->printable($path)));
            }
            $type = $info['mode'] & 0o170000;
            if (0o120000 === $type) {
                $this->copyLink($path, $dst, $inDumps, $context, $counts);
            } elseif (0o040000 === $type) {
                $this->ensureDirectory($dst, $path);
                $this->copyTree($src, $dst, $path, $context, $counts);
                if ('' === $relative && self::DUMPS === $name) {
                    $context->exclude('/'.self::DUMPS.'/');
                }
            } elseif (0o100000 === $type) {
                $this->copyFile($src, $dst, $path, $info['mode'] & 0o755, $inDumps, $context, $counts);
                if (!$inDumps) {
                    $context->exclude('/'.self::escape($path));
                }
            } else {
                $context->log->line(\sprintf('assets: skipped "%s" (not a regular file)', $this->printable($path)));
                ++$counts['skipped'];
            }
        }
    }

    /**
     * @param array{copied: int, substituted: int, linked: int, skipped: int} $counts
     */
    private function copyLink(string $path, string $dst, bool $inDumps, DeployContext $context, array &$counts): void
    {
        $link = AssetLinkResolver::resolve($this->root, $path);
        if (null === $link->target) {
            $context->log->line(\sprintf('assets: skipped "%s" (symbolic link to "%s": %s)', $this->printable($path), $link->raw, $link->refusal));
            ++$counts['skipped'];

            return;
        }

        if ($inDumps && self::DUMPS === explode('/', $link->target)[0]) {
            $this->linkOrCopy($this->root.'/'.$link->target, $dst, $path, $counts);

            return;
        }

        // Relative to the folder of the link, so the instance stays movable: `a/b/link` -> `../c/target`.
        $from = explode('/', $path);
        array_pop($from);
        $to = explode('/', $link->target);
        while ([] !== $from && \count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $relative = str_repeat('../', \count($from)).implode('/', $to);
        $this->replace($dst, $path, null, static fn (string $tmp): bool => @symlink($relative, $tmp));
        ++$counts['linked'];
        if (!$inDumps) {
            $context->exclude('/'.self::escape($path));
        }
    }

    private function ensureDirectory(string $dst, string $path): void
    {
        if (is_link($dst)) {
            throw new \RuntimeException(\sprintf('"%s" is a symbolic link in the checkout; refusing to write through it', $this->printable($path)));
        }
        if (file_exists($dst) && !is_dir($dst)) {
            throw new \RuntimeException(\sprintf('"%s" is a file in the checkout where the assets have a folder', $this->printable($path)));
        }
        if (!is_dir($dst) && !@mkdir($dst, 0o750, true) && !is_dir($dst)) {
            throw new \RuntimeException(\sprintf('cannot create the folder "%s"', $this->printable($path)));
        }
    }

    /**
     * @param array{copied: int, substituted: int, linked: int, skipped: int} $counts
     */
    private function copyFile(string $src, string $dst, string $path, int $mode, bool $dump, DeployContext $context, array &$counts): void
    {
        if ($dump) {
            $this->linkOrCopy($src, $dst, $path, $counts);

            return;
        }

        $size = (int) @filesize($src);
        $content = $size <= self::MAX_TEXT_BYTES ? @file_get_contents($src) : false;
        if ($size <= self::MAX_TEXT_BYTES && false === $content) {
            throw new \RuntimeException(\sprintf('cannot read "%s"', $this->printable($path)));
        }
        if (\is_string($content) && !str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) {
            $rendering = $context->render($content);
            $rendered = $rendering->text;
            $missing = $rendering->unresolved;
            if ([] !== $missing) {
                $context->log->line(\sprintf('assets: "%s" has unresolved token(s) left as written: %s', $this->printable($path), implode(', ', array_map(static fn (string $n): string => '[['.$n.']]', $missing))));
            }
            $this->replace($dst, $path, $mode, static fn (string $tmp): bool => false !== @file_put_contents($tmp, $rendered));
            ++$counts['substituted'];

            return;
        }

        $this->replace($dst, $path, $mode, static fn (string $tmp): bool => @copy($src, $tmp));
        ++$counts['copied'];
    }

    /**
     * @param array{copied: int, substituted: int, linked: int, skipped: int} $counts
     */
    private function linkOrCopy(string $src, string $dst, string $path, array &$counts): void
    {
        $a = @lstat($src);
        $b = @lstat($dst);
        $same = false !== $a && false !== $b && 0o100000 === ($b['mode'] & 0o170000) && $a['ino'] === $b['ino'] && $a['dev'] === $b['dev'];
        if (!$same) {
            if (is_link($dst) || file_exists($dst)) {
                if (!@unlink($dst)) {
                    throw new \RuntimeException(\sprintf('cannot replace "%s"', $this->printable($path)));
                }
            }
            if (!@link($src, $dst)) {
                $this->replace($dst, $path, null, static fn (string $tmp): bool => @copy($src, $tmp));
            }
        }
        ++$counts['linked'];
    }

    /**
     * Writes through a temporary file next to the target and renames it over it, so a stale file or a symbolic link at
     * the destination is replaced, never written through.
     *
     * The temporary file is created private (0600) and given its final mode before the rename, so a file with secrets
     * is never readable by others, not even briefly; a failed chmod fails the step.
     *
     * @param \Closure(string): bool $write
     */
    private function replace(string $dst, string $path, ?int $mode, \Closure $write): void
    {
        if (is_dir($dst) && !is_link($dst)) {
            throw new \RuntimeException(\sprintf('"%s" is a folder in the checkout where the assets have a file', $this->printable($path)));
        }
        $tmp = $dst.'.fleet-seed-'.bin2hex(random_bytes(4));
        $old = umask(0o077);
        try {
            $written = $write($tmp);
        } finally {
            umask($old);
        }
        if ($written && null !== $mode && !@chmod($tmp, $mode)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('cannot set the mode of "%s"', $this->printable($path)));
        }
        if (!$written || !@rename($tmp, $dst)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('cannot write "%s"', $this->printable($path)));
        }
    }

    private static function escape(string $path): string
    {
        return addcslashes($path, '*?[]\\');
    }

    private function printable(string $path): string
    {
        return preg_replace('/[^\P{C}]/u', '?', $path) ?? '?';
    }
}
