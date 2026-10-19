<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Projects\Rule;

use App\Fleet\Projects\Rule\IssueIdPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IssueIdPatternTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach ([
            '[A-Z][A-Z0-9]+-\d+',
            'OAKS-[0-9]+',
            'feature/([A-Z]+-\d+)-',
            '(?:ABC|XYZ)-\d{1,6}',
            '[A-Z]+-\d+',
            '(?i)abc-\d+',
            '(?<key>[A-Z]+)-(?<num>\d+)',
            '^[a-z]+/([A-Z]{2,5}-\d+)',
            '\bABC-\d+\b',
            'ABC-\d+(?!\d)',
            str_repeat('a', 200),
        ] as $pattern) {
            yield substr($pattern, 0, 40) => [$pattern];
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        $cases = [
            '(a+)+$',
            '\\Q(a+)+\\E$',
            '\\Qabc',
            'a\\E',
            '(.*)*x',
            '(a|aa)+$',
            '(\d+)*-',
            '([a-z]+){2,}',
            '(?:a+)+b',
            '((a+)b)+',
            '(a*)*',
            '(a{2,})+',
            '(',
            ')',
            '[',
            'a)',
            '*a',
            'a~b',
            "a\x00b",
            "a\nb",
            "ABC-\\d+\n",
            "a\x7f",
            '(a)\1',
            '(a)\g{1}',
            '(?<n>a)\k<n>',
            '(?<n>a)\g<n>',
            '(a)(?1)',
            '(?R)',
            '(?C)',
            '(?{x})',
            '(?&n)',
            '(?P>n)',
            '(?P=n)',
            '(?(1)a|b)',
            '(?#comment)',
            '(?|(a)|(b))',
            '(?-1)',
            '(?+1)',
            '(*SKIP)a',
            '(*COMMIT)a',
            '(*F)',
            '',
            str_repeat('a', 201),
            str_repeat('a', 10_000),
            5,
            null,
            ['a'],
        ];
        foreach ($cases as $i => $pattern) {
            yield '#'.$i.' '.(\is_string($pattern) ? addcslashes(substr($pattern, 0, 30), "\0..\37") : get_debug_type($pattern)) => [$pattern];
        }
    }

    #[DataProvider('accepted')]
    public function testAccepts(string $pattern): void
    {
        self::assertNull(IssueIdPattern::error($pattern));
    }

    #[DataProvider('rejected')]
    public function testRejects(mixed $pattern): void
    {
        $error = IssueIdPattern::error($pattern);

        self::assertIsString($error);
        if (\is_string($pattern) && \strlen($pattern) >= 8 && \strlen($pattern) <= 200) {
            self::assertStringNotContainsString($pattern, $error);
        }
    }

    public function testACompileErrorIsReportedWithAPrefix(): void
    {
        self::assertStringStartsWith('invalid pattern: ', (string) IssueIdPattern::error('(abc'));
    }

    public function testTheStaticScanNamesTheNestedQuantifier(): void
    {
        self::assertStringContainsString('nested quantifier', (string) IssueIdPattern::error('(a+)+$'));
    }

    public function testThePatternsOfTheProbeFamilyAreCaughtByTheDynamicProbe(): void
    {
        // no nested quantifier (the static scan lets it through), but polynomial of a high degree on the probe strings
        self::assertSame('pattern is too expensive', IssueIdPattern::error('a*a*a*a*a*$'));
        self::assertSame('pattern is too expensive', IssueIdPattern::error('.*.*.*.*.*\d!'));
    }

    public function testTheIniSettingsAreRestored(): void
    {
        $jit = \ini_get('pcre.jit');
        $limit = \ini_get('pcre.backtrack_limit');

        IssueIdPattern::error('[A-Z]+-\d+');
        IssueIdPattern::error('(a+)+$');
        IssueIdPattern::extract('[A-Z]+-\d+', 'abc-1', 'main');

        self::assertSame($jit, \ini_get('pcre.jit'));
        self::assertSame($limit, \ini_get('pcre.backtrack_limit'));
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function extractions(): iterable
    {
        yield 'label, whole match' => ['OAKS-[0-9]+', 'oaks-1781', 'main', 'OAKS-1781'];
        yield 'branch, group 1' => ['feature/(OAKS-[0-9]+)-', 'x', 'feature/oaks-12-fix', 'OAKS-12'];
        yield 'label wins over branch' => ['[A-Z]+-\d+', 'abc-1', 'feature/xyz-2', 'ABC-1'];
        yield 'branch when the label does not match' => ['[A-Z]+-\d+', 'demo', 'feature/xyz-2-blog', 'XYZ-2'];
        yield 'match outside the charset' => ['.*', 'a b', 'x y', null];
        yield 'match outside the charset, falls back to the branch' => ['.*', 'a b', 'main', 'MAIN'];
        yield 'no match' => ['[A-Z]+-\d+', 'demo', 'main', null];
        yield 'group 1 unmatched, whole match' => ['(?:(ABC)|XYZ)-\d+', 'xyz-5', 'main', 'XYZ-5'];
        yield 'empty group 1 is no id' => ['()', 'abc', 'main', null];
        yield 'leading dash is refused' => ['-[A-Z]+', '-abc', 'main', null];
        yield 'trailing newline in the subject' => ['ABC-\d+$', "abc-1\n", 'main', 'ABC-1'];
        yield 'a matched trailing newline leaves the charset' => ['ABC-\\d+\\n', "abc-1\n", "abc-1\n", null];
        yield 'dots, slashes and underscores are allowed' => ['[a-z./_-]+', 'a.b_c-d', 'main', 'A.B_C-D'];
        yield 'shell metacharacters are not' => ['[a-z$]+', 'a$b', 'a$c', null];
        yield 'a pattern that does not compile' => ['(', 'abc', 'abc', null];
        yield 'a pattern with the delimiter' => ['a~b', 'a~b', 'a~b', null];
        yield 'an empty pattern' => ['', 'abc', 'abc', null];
        yield 'a label over the DNS limit is ignored' => ['[A-Z]+', str_repeat('a', 64), 'abc', 'ABC'];
        yield 'a branch over the limit is ignored' => ['[A-Z]+', '1', str_repeat('a', 201), null];
        yield 'a branch at the limit is used' => ['[A-Z]+', '1', str_repeat('a', 200), str_repeat('A', 200)];
    }

    #[DataProvider('extractions')]
    public function testExtract(string $pattern, string $label, string $branch, ?string $expected): void
    {
        self::assertSame($expected, IssueIdPattern::extract($pattern, $label, $branch));
    }

    public function testExtractNeverHangsOnAPathologicalBranch(): void
    {
        $start = microtime(true);
        $result = IssueIdPattern::extract('[A-Z]+-\d+', 'x y', str_repeat('A', 100_000));

        self::assertNull($result);
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function expensive(): iterable
    {
        // valid patterns that the static scan accepts and that take minutes to fail on these subjects without the limit
        yield 'five star runs on the branch' => ['a*a*a*a*a*$', 'x y', str_repeat('a', 190).'!'];
        yield 'five star runs on the label' => ['a*a*a*a*a*$', str_repeat('a', 60).'!', 'x y'];
        yield 'dot star chain' => ['.*.*.*.*.*\d!', 'x y', str_repeat('a', 190).'!'];
        yield 'no match and no error on a short subject' => ['.*.*.*.*.*\d!', 'x y', 'main'];
    }

    #[DataProvider('expensive')]
    public function testExtractRestsOnTheBacktrackLimit(string $pattern, string $label, string $branch): void
    {
        $start = microtime(true);

        self::assertNull(IssueIdPattern::extract($pattern, $label, $branch));
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testTheLimitReallyTripsOnTheseSubjects(): void
    {
        // guards the test above: without the budget the pattern fails by error, not by a quick no-match
        $limit = ini_set('pcre.backtrack_limit', '100000');
        $jit = ini_set('pcre.jit', '0');
        try {
            self::assertFalse(@preg_match('~a*a*a*a*a*$~i', str_repeat('a', 190).'!'));
            self::assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function testExtractGivesTheSameAnswerWithTheJitOn(): void
    {
        $jit = ini_set('pcre.jit', '1');
        try {
            // warm the pattern cache with JIT, as a request that matched earlier would
            @preg_match('~a*a*a*a*a*$~i', 'a');
            self::assertNull(IssueIdPattern::extract('a*a*a*a*a*$', 'x y', str_repeat('a', 190).'!'));
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
        }
    }
}
