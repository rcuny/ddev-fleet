<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Check\CheckResult;
use App\Fleet\Upgrade\Check\CheckRunner;
use App\Fleet\Upgrade\Check\Severity;
use App\Fleet\Upgrade\Check\UpgradeCheck;
use App\Fleet\Upgrade\Plan;

final class CheckRunnerTest extends CheckTestCase
{
    /**
     * @param list<CheckResult>|\Throwable $out
     */
    private static function dummy(array|\Throwable $out): UpgradeCheck
    {
        return new readonly class($out) implements UpgradeCheck {
            /** @param list<CheckResult>|\Throwable $out */
            public function __construct(private array|\Throwable $out)
            {
            }

            public function check(Plan $plan): array
            {
                if ($this->out instanceof \Throwable) {
                    throw $this->out;
                }

                return $this->out;
            }
        };
    }

    public function testADummyCheckIsRunInRegistryOrder(): void
    {
        $runner = new CheckRunner([
            self::dummy([CheckResult::info('a.one', 'first'), CheckResult::warn('a.two', 'second')]),
            self::dummy([]),
            self::dummy([CheckResult::block('b.one', 'third', 'fix it')]),
        ]);

        $report = $runner->run(self::plan());

        self::assertSame(['a.one', 'a.two', 'b.one'], self::ids($report->results));
        self::assertSame(['b.one'], self::ids($report->blocking()));
        self::assertTrue($report->isBlocked());
        self::assertCount(1, $report->withSeverity(Severity::Warn));
    }

    public function testNoCheckMeansNothingBlocks(): void
    {
        self::assertFalse(new CheckRunner([])->run(self::plan())->isBlocked());
    }

    public function testACheckThatThrowsBlocksAndCannotBeForced(): void
    {
        $runner = new CheckRunner([self::dummy(new \RuntimeException('boom'))]);

        $report = $runner->run(self::plan(), [CheckRunner::CRASHED]);

        self::assertSame([CheckRunner::CRASHED], self::ids($report->blocking()));
        self::assertSame([CheckRunner::CRASHED], $report->refused);
        self::assertStringContainsString('boom', $report->results[0]->message);
    }

    public function testForceWaivesOnlyAnOverridableBlock(): void
    {
        $runner = new CheckRunner([self::dummy([
            CheckResult::block('soft', 'x', 'r', true),
            CheckResult::block('hard', 'y', 'r', false),
        ])]);

        $report = $runner->run(self::plan(), ['soft', 'hard', 'nothing', 'soft']);

        self::assertSame(['soft'], $report->forced);
        self::assertSame(['hard'], $report->refused);
        self::assertSame(['nothing'], $report->unmatched);
        self::assertSame(['hard'], self::ids($report->blocking()));
    }

    public function testEveryFindingHasAnIdAndRemedyWhenItBlocks(): void
    {
        $result = CheckResult::block('x.y', 'msg', 'do this');

        self::assertSame(['id' => 'x.y', 'severity' => 'block', 'message' => 'msg', 'remedy' => 'do this', 'overridable' => false], $result->toArray());
    }
}
