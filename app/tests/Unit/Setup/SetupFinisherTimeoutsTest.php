<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Fleet\System\CaddyReloader;
use App\Fleet\System\PrivilegedApply;
use App\Setup\SetupFinisher;
use PHPUnit\Framework\TestCase;

/**
 * php-fpm kills a request that runs past request_terminate_timeout. If that happened between the gate flip and the
 * restore of the setup gate, the wizard would be unreachable while setup is still open. These pin the arithmetic so a
 * longer step timeout or a new step cannot silently outgrow the timeout the installer configures.
 */
final class SetupFinisherTimeoutsTest extends TestCase
{
    private const int RESPONSE_MARGIN = 30;

    public function testTheRequiredTimeoutCoversTheSumOfEveryStepIncludingTheRestore(): void
    {
        // authelia apply + gate (validate+reload) + caddy apply + restore (validate+reload)
        $sum = 2 * PrivilegedApply::TIMEOUT + 2 * CaddyReloader::MAX_SECONDS;

        self::assertGreaterThanOrEqual($sum, SetupFinisher::REQUIRED_REQUEST_TIMEOUT);
    }

    public function testTheRequiredTimeoutCoversTheBudgetPlusTheRestorePlusTheResponse(): void
    {
        self::assertSame(CaddyReloader::MAX_SECONDS, SetupFinisher::RESTORE_SECONDS);
        self::assertGreaterThanOrEqual(
            SetupFinisher::BUDGET_SECONDS + SetupFinisher::RESTORE_SECONDS + self::RESPONSE_MARGIN,
            SetupFinisher::REQUIRED_REQUEST_TIMEOUT,
        );
    }

    public function testEveryForwardStepFitsTheBudgetWhenItStartsAtItsLatestAllowedMoment(): void
    {
        foreach ([PrivilegedApply::TIMEOUT, CaddyReloader::MAX_SECONDS] as $worstCase) {
            self::assertLessThanOrEqual(SetupFinisher::BUDGET_SECONDS, $worstCase, 'a single step must fit the budget at all');
        }
    }
}
