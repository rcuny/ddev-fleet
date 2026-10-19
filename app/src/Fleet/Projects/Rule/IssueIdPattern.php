<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * A project's `issue_id_regexp`: the operator's regular expression that pulls an issue key out of an instance label or a
 * branch name. It is attacker-influenced input run by the server, so it is treated as hostile:
 *
 *  - error() restricts the syntax (no backreferences, recursion, callouts, control verbs, comments, extended mode),
 *    rejects the classic nested-quantifier shapes with a static scan and runs a dynamic probe under a small backtrack
 *    budget. These are filters, not proofs: the scan has false positives (`(a|b)+`) and PCRE shortcuts hide some
 *    expensive patterns from the probes.
 *  - The actual safety rests on extract(), the only matching entry point: it runs every match under the same small
 *    `pcre.backtrack_limit` with the JIT off, bounds its subjects (label 63, branch 200 characters) and turns every
 *    preg error into "no issue id". It never throws and never runs long.
 *
 * The pattern is compiled as `'~' . $pattern . '~i'` (no `u` flag): the delimiter is therefore refused inside it.
 */
final class IssueIdPattern
{
    public const int MAX = 200;
    public const int LABEL_MAX = 63;
    public const int BRANCH_MAX = BranchName::MAX;
    public const int BACKTRACK_LIMIT = 100_000;

    /** What an extracted id may contain: it ends up in a terminal pane, so nothing a shell would read. Not a leading dash. */
    private const string ID_CHARSET = '/^[A-Za-z0-9._\/][A-Za-z0-9._\/-]*$/D';

    /** The group openers accepted: plain, non-capturing, lookaround, atomic, named, and the inline flags i m s. */
    private const string GROUP_OPENER = '/\G\((?:\?(?::|=|!|>|<=|<!|<[A-Za-z_][A-Za-z0-9_]{0,31}>|[ims]*(?:-[ims]+)?[):])|(?![?*]))/A';

    public static function error(mixed $pattern): ?string
    {
        if (!\is_string($pattern) || '' === $pattern) {
            return 'Enter a pattern.';
        }
        if (\strlen($pattern) > self::MAX) {
            return \sprintf('The pattern must be at most %d characters.', self::MAX);
        }
        if (1 === preg_match('/[\x00-\x1f\x7f]/', $pattern)) {
            return 'The pattern must not contain control characters.';
        }
        if (str_contains($pattern, '~')) {
            return 'The pattern must not contain "~".';
        }

        $regex = self::regex($pattern);
        if (null !== $compileError = self::compileError($regex)) {
            return 'invalid pattern: '.$compileError;
        }
        if (null !== $error = self::scan($pattern)) {
            return $error;
        }

        return self::guarded(static function () use ($regex): ?string {
            foreach (self::probes() as $probe) {
                if (false === self::match($regex, $probe)) {
                    return 'pattern is too expensive';
                }
            }

            return null;
        });
    }

    /**
     * The issue id for a deploy: the label is tried first, the branch second; case-insensitive; group 1 when the pattern
     * has one and it matched, else the whole match; upper-cased; null unless it fits the id charset. Never throws and
     * never runs long, whatever the pattern and the subjects.
     */
    public static function extract(string $pattern, string $label, string $branch): ?string
    {
        if ('' === $pattern || \strlen($pattern) > self::MAX || 1 === preg_match('/[\x00-\x1f\x7f~]/', $pattern)) {
            return null;
        }
        $regex = self::regex($pattern);

        return self::guarded(static function () use ($regex, $label, $branch): ?string {
            foreach ([[$label, self::LABEL_MAX], [$branch, self::BRANCH_MAX]] as [$subject, $max]) {
                if ('' === $subject || \strlen($subject) > $max) {
                    continue;
                }
                $matches = [];
                $result = self::match($regex, $subject, $matches);
                if (false === $result) {
                    return null;
                }
                if (1 !== $result) {
                    continue;
                }
                $value = \array_key_exists(1, $matches) && null !== $matches[1] ? $matches[1] : $matches[0];
                $value = strtoupper((string) $value);
                if (1 === preg_match(self::ID_CHARSET, $value)) {
                    return $value;
                }
            }

            return null;
        });
    }

    private static function regex(string $pattern): string
    {
        return '~'.$pattern.'~i';
    }

    /**
     * @param array<array-key, string|null> $matches
     *
     * @return int|false false on any error: a bad pattern, the backtrack limit, the JIT stack
     */
    private static function match(string $regex, string $subject, array &$matches = []): int|false
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, $subject, $matches, \PREG_UNMATCHED_AS_NULL);
        } finally {
            restore_error_handler();
        }
    }

    private static function compileError(string $regex): ?string
    {
        $message = null;
        set_error_handler(static function (int $no, string $text) use (&$message): bool {
            $message = $text;

            return true;
        });
        try {
            $result = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
        if (false !== $result) {
            return null;
        }
        $message = (string) $message;
        if (false !== $at = strpos($message, 'Compilation failed: ')) {
            $message = substr($message, $at + \strlen('Compilation failed: '));
        }

        return substr(trim(strtok($message, "\n") ?: 'does not compile'), 0, 120);
    }

    /**
     * Walks the pattern outside escapes and character classes. Refuses backreferences and the group syntax that
     * recurses, calls out or changes how the rest is parsed, and a repeated group whose body already repeats or
     * alternates (`(a+)+`, `(a|aa)*`, `(\d+){2,}`).
     */
    private static function scan(string $pattern): ?string
    {
        $length = \strlen($pattern);
        /** @var list<bool> $groups whether the body of each open group contains a quantifier or an alternation */
        $groups = [];
        $i = 0;
        while ($i < $length) {
            $c = $pattern[$i];
            if ('\\' === $c) {
                $next = $pattern[$i + 1] ?? '';
                if ('Q' === $next || 'E' === $next) {
                    return 'literal quoting (\\Q...\\E) is not allowed in the pattern';
                }
                if ('g' === $next || 'k' === $next || ($next >= '1' && $next <= '9')) {
                    return 'backreferences are not allowed in the pattern';
                }
                $i += 2;
                if (('p' === $next || 'P' === $next || 'x' === $next) && '{' === ($pattern[$i] ?? '')) {
                    $close = strpos($pattern, '}', $i);
                    $i = false === $close ? $length : $close + 1;
                }
                continue;
            }
            if ('[' === $c) {
                $i = self::skipClass($pattern, $i);
                continue;
            }
            if ('(' === $c) {
                if (1 !== preg_match(self::GROUP_OPENER, $pattern, $opener, 0, $i)) {
                    return 'the pattern uses syntax that is not allowed (recursion, callouts, conditionals, comments, control verbs)';
                }
                $i += \strlen($opener[0]);
                if (!str_ends_with($opener[0], ')')) {
                    $groups[] = false;
                }
                continue;
            }
            if (')' === $c) {
                $repeats = array_pop($groups) ?? false;
                $next = $pattern[$i + 1] ?? '';
                if ($repeats && ('+' === $next || '*' === $next || '{' === $next)) {
                    return 'nested quantifier: a repeated group must not contain a quantifier or an alternation';
                }
                if ($repeats && [] !== $groups) {
                    $groups[array_key_last($groups)] = true;
                }
                ++$i;
                continue;
            }
            if (('+' === $c || '*' === $c || '{' === $c || '|' === $c) && [] !== $groups) {
                $groups[array_key_last($groups)] = true;
            }
            ++$i;
        }

        return null;
    }

    /** @return int the offset after the closing bracket of the class that starts at $i */
    private static function skipClass(string $pattern, int $i): int
    {
        $length = \strlen($pattern);
        $j = $i + 1;
        if ('^' === ($pattern[$j] ?? '')) {
            ++$j;
        }
        if (']' === ($pattern[$j] ?? '')) {
            ++$j;
        }
        while ($j < $length && ']' !== $pattern[$j]) {
            if ('\\' === $pattern[$j]) {
                $j += 2;
            } elseif ('[' === $pattern[$j] && ':' === ($pattern[$j + 1] ?? '')) {
                $end = strpos($pattern, ':]', $j + 2);
                $j = false === $end ? $length : $end + 2;
            } else {
                ++$j;
            }
        }

        return $j + 1;
    }

    /** @return list<string> */
    private static function probes(): array
    {
        return [
            str_repeat('a', 4096).'!',
            str_repeat('1', 4096).'-',
            str_repeat('a1-', 2000),
            str_repeat('A-', 2000).'x',
            str_repeat('/', 3000).'x',
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private static function guarded(callable $callback): mixed
    {
        $jit = ini_set('pcre.jit', '0');
        $limit = ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            return $callback();
        } finally {
            if (false !== $jit) {
                ini_set('pcre.jit', $jit);
            }
            if (false !== $limit) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }
    }
}
