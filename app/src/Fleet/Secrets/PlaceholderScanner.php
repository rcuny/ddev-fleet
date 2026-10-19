<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use App\Fleet\FleetPaths;

/**
 * Reads the text files of a project's assets directory for `[[token]]` placeholders, so a secret that no file uses can
 * be flagged (the 0.x trap: a secret without a placeholder is silently inert). Files over 1 MiB, binary files (a NUL
 * in the first 8 KiB), files that are not UTF-8 and symlinks are skipped, as the deploy's substitution skips them.
 */
final readonly class PlaceholderScanner
{
    public const int MAX_FILE_BYTES = 1048576;
    public const int MAX_FILES = 5000;
    private const int SNIFF_BYTES = 8192;

    public function __construct(private FleetPaths $paths)
    {
    }

    public function scan(string $project): PlaceholderScan
    {
        $dir = $this->paths->assetsDir($project);
        if (!is_dir($dir)) {
            return new PlaceholderScan(false, 0, []);
        }

        $tokens = [];
        $scanned = 0;
        $seen = 0;
        try {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                    continue;
                }
                if (++$seen > self::MAX_FILES) {
                    return new PlaceholderScan(true, $scanned, $tokens, false);
                }
                $text = self::text($file);
                if (null === $text) {
                    continue;
                }
                ++$scanned;
                preg_match_all(TokenRenderer::PATTERN, $text, $found);
                foreach ($found[1] as $token) {
                    $tokens[$token] = true;
                }
            }
        } catch (\UnexpectedValueException) {
            // An unreadable directory: report what was read so far.
            return new PlaceholderScan(true, $scanned, $tokens, false);
        }

        return new PlaceholderScan(true, $scanned, $tokens);
    }

    private static function text(\SplFileInfo $file): ?string
    {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            return null;
        }
        $content = @file_get_contents($file->getPathname());
        if (false === $content || str_contains(substr($content, 0, self::SNIFF_BYTES), "\0") || !mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return $content;
    }
}
