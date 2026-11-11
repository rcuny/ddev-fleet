<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\ApiTokenReminder;
use App\Command\Token\TokenRemindCommand;
use App\Domain\ApiToken\ApiScope;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ApiTokenReminderTest extends KernelTestCase
{
    use FleetDatabase;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        $issuer->issue('soon', '', [ApiScope::Read], ['blog'], [], ['192.0.2.1'], new \DateTimeImmutable('+5 days'), 'alice');
        $issuer->issue('later', '', [ApiScope::Read], ['blog'], [], ['192.0.2.1'], new \DateTimeImmutable('+90 days'), 'alice');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function reminder(): ApiTokenReminder
    {
        $reminder = self::getContainer()->get(ApiTokenReminder::class);

        return $reminder;
    }

    /**
     * @return list<string>
     */
    private function reminders(): array
    {
        return array_map(static fn ($e): string => $e->subject(), self::getContainer()->get(AuditLog::class)->latest(50, AuditLog::API_TOKEN_EXPIRING));
    }

    public function testOnlyAnExpiringTokenIsRemindedAndOnlyOncePerDay(): void
    {
        $reminder = $this->reminder();

        self::assertCount(1, $reminder->remind());
        self::assertCount(1, $reminder->remind(), 'still reported');

        $subjects = $this->reminders();
        self::assertCount(1, $subjects, 'but audited once');
        self::assertStringContainsString('soon expires', $subjects[0]);
    }

    public function testTheCommandWarnsAndNeverFails(): void
    {
        $command = new CommandTester(new TokenRemindCommand($this->reminder()));

        $command->execute([]);

        self::assertSame(0, $command->getStatusCode());
        self::assertStringContainsString('WARNING token', $command->getDisplay());
        self::assertStringContainsString('"soon"', $command->getDisplay());
        self::assertStringNotContainsString('later', $command->getDisplay());
    }
}
