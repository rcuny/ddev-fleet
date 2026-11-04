<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Check\CheckResult;
use App\Fleet\Upgrade\Direction;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\Release;
use App\Fleet\Upgrade\Version;
use PHPUnit\Framework\TestCase;

abstract class CheckTestCase extends TestCase
{
    protected const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected static function plan(string $from = 'v1.2.0', ?string $to = 'v1.3.0', Direction $direction = Direction::Up): Plan
    {
        $target = null === $to ? null : new Release(Version::parse($to), self::SHA);

        return new Plan($from, Version::tryParse($from), $target, $direction, null === $target ? [] : [$target]);
    }

    /**
     * @param list<CheckResult> $results
     *
     * @return list<string>
     */
    protected static function ids(array $results): array
    {
        return array_map(static fn (CheckResult $r): string => $r->id, $results);
    }
}
