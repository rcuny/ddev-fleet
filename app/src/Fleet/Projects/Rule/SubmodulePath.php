<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * `display_submodule_branch`: a path relative to the instance checkout, handed to `git -C`. Every segment starts with a
 * letter or digit, which closes `.`, `..`, `.git` and anything that could pass as an option. Consumers must still check
 * the real path stays inside the instance directory (a submodule can be a symlink).
 */
final class SubmodulePath
{
    public const int MAX = 200;

    private const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*(\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/D';

    public static function error(mixed $path): ?string
    {
        if (!\is_string($path) || '' === $path) {
            return 'Enter a path relative to the checkout.';
        }
        if (\strlen($path) > self::MAX) {
            return \sprintf('The path must be at most %d characters.', self::MAX);
        }
        if (1 !== preg_match(self::PATTERN, $path)) {
            return 'Use a relative path such as web/themes/custom: segments of letters, digits, dot, underscore and dash, each starting with a letter or digit.';
        }

        return null;
    }
}
