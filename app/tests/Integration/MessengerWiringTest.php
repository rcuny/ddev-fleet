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

    public function testJobsTransportRoundTripsOnTheQueueDatabaseOnly(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $container = self::getContainer();

        foreach (['jobs', 'failed'] as $name) {
            $transport = $container->get('messenger.transport.'.$name);
            self::assertInstanceOf(SetupableTransportInterface::class, $transport);
            $transport->setup();
        }

        $jobs = $container->get('messenger.transport.jobs');
        self::assertInstanceOf(TransportInterface::class, $jobs);

        $jobs->send(new Envelope(new \stdClass()));
        $received = iterator_to_array($jobs->get());
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
