<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Terminal\TerminalWriteNotifier;
use App\Domain\ApiCall\ApiCall;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class TerminalWriteNotifierTest extends TestCase
{
    private static function call(): ApiCall
    {
        return new ApiCall(7, 'token:7:orch', 'POST terminal', 'blog--main', 'tty2', 'claude', '192.0.2.1', 11, hash('sha256', 'secret text'), 'secret text', true, new \DateTimeImmutable('2026-11-11 10:00:00 UTC'));
    }

    public function testPublishesAPrivateAlertWithIdsAndSizesButNeverTheText(): void
    {
        $sent = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $u) use (&$sent): string {
            $sent[] = $u;

            return 'id';
        });

        new TerminalWriteNotifier($hub, new NullLogger())->sent(self::call());

        self::assertCount(1, $sent);
        self::assertSame([TerminalWriteNotifier::ALERT_TOPIC], $sent[0]->getTopics());
        self::assertTrue($sent[0]->isPrivate());
        $data = json_decode($sent[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('blog--main', $data['instance']);
        self::assertSame(11, $data['length']);
        self::assertStringNotContainsString('secret', $sent[0]->getData());
    }

    public function testAHubThatIsDownNeverFailsTheCall(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub down'));

        new TerminalWriteNotifier($hub, new NullLogger())->sent(self::call());

        $this->expectNotToPerformAssertions();
    }
}
