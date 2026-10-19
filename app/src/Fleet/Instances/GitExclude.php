<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * Keeps the files a deploy writes out of the project's `git status` through `.git/info/exclude` (never `.gitignore`,
 * which the project owns).
 */
final class GitExclude
{
    private function __construct()
    {
    }

    /**
     * Appends the patterns that are not listed yet.
     *
     * @param list<string> $patterns
     */
    public static function add(string $dir, array $patterns): void
    {
        $file = $dir.'/.git/info/exclude';
        if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o750, true) && !is_dir(\dirname($file))) {
            throw new \RuntimeException('Cannot create .git/info.');
        }

        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $existing = array_map('trim', preg_split('/\R/', $text) ?: []);

        $add = '';
        foreach ($patterns as $pattern) {
            if (1 === preg_match('/[\r\n\0]/', $pattern) || '' === $pattern) {
                throw new \InvalidArgumentException('The exclude pattern is not valid.');
            }
            if (!\in_array($pattern, $existing, true)) {
                $existing[] = $pattern;
                $add .= $pattern."\n";
            }
        }
        if ('' === $add) {
            return;
        }
        if ('' !== $text && !str_ends_with($text, "\n")) {
            $add = "\n".$add;
        }
        if (false === @file_put_contents($file, $add, \FILE_APPEND)) {
            throw new \RuntimeException('Cannot write .git/info/exclude.');
        }
    }
}
