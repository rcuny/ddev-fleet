<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * A git branch the fleet is allowed to check out: a deliberately narrow subset of git's own ref rules (no `~^:?*[\`,
 * spaces, control characters, `@{`, a leading dash that could pass as an option).
 */
final class BranchName
{
    public const int MAX = 200;

    private const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D';

    public static function error(mixed $branch): ?string
    {
        if (!\is_string($branch) || '' === $branch) {
            return 'Enter a branch name.';
        }
        if (\strlen($branch) > self::MAX) {
            return \sprintf('The branch name must be at most %d characters.', self::MAX);
        }
        if (1 !== preg_match(self::PATTERN, $branch)) {
            return 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.';
        }
        if (str_contains($branch, '..') || str_contains($branch, '//') || str_contains($branch, '/.')
            || str_ends_with($branch, '/') || str_ends_with($branch, '.') || str_ends_with($branch, '.lock')
            || str_contains($branch, '.lock/')) {
            return 'This is not a valid git branch name (no "..", "//", "/.", trailing "/" or ".", or ".lock" ending).';
        }

        if ('HEAD' === $branch || str_starts_with($branch, 'refs/')) {
            return 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.';
        }

        return null;
    }
}
