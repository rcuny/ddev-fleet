<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TempFleetHome;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class MessengerWiringTest extends KernelTestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreFleetHome();
    }

    public function testQueueTransportsRoundTripOnTheQueueDatabaseOnly(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $container = self::getContainer();

        // `jobs` runs as sync:// in the test environment (MessengerRoutingTest pins prod to Doctrine), so the
        // round trip uses `failed`, which is Doctrine everywhere.
        $failed = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SetupableTransportInterface::class, $failed);
        $failed->setup();
        self::assertInstanceOf(TransportInterface::class, $failed);

        $failed->send(new Envelope(new \stdClass()));
        $received = iterator_to_array($failed->get());
        self::assertCount(1, $received);
        self::assertInstanceOf(\stdClass::class, $received[0]->getMessage());

        $queue = $container->get('doctrine.dbal.queue_connection');
        $fleet = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $queue);
        self::assertInstanceOf(Connection::class, $fleet);

        self::assertTrue($queue->createSchemaManager()->tablesExist(['messenger_messages']));
        self::assertFalse($fleet->createSchemaManager()->tablesExist(['messenger_messages']));
    }

    public function testFailedMessagesAreRoutedToTheFailedTransport(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $container = self::getContainer();

        $failed = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SetupableTransportInterface::class, $failed);
        $failed->setup();

        $locator = $container->get('messenger.failure_transports.default');
        self::assertSame($failed, $locator);
    }
}
