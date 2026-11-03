<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\RunJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Yaml\Yaml;

final class MessengerRoutingTest extends KernelTestCase
{
    private const string CONFIG = __DIR__.'/../../config/packages/messenger.yaml';

    public function testRunJobRoutesToJobsAndProdUsesDoctrine(): void
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parseFile(self::CONFIG);

        // Routing is the same in every environment.
        self::assertSame([RunJob::class => 'jobs'], $config['framework']['messenger']['routing']);

        // The base (= prod) transport is the Doctrine queue; only dev and test swap in sync://.
        self::assertStringStartsWith('doctrine://queue?', $config['framework']['messenger']['transports']['jobs']['dsn']);
        self::assertArrayNotHasKey('when@prod', $config);
        self::assertSame('sync://', $config['when@dev']['framework']['messenger']['transports']['jobs']);
        self::assertSame('sync://', $config['when@test']['framework']['messenger']['transports']['jobs']);
    }

    public function testTestEnvironmentRunsJobsInTheRequest(): void
    {
        self::bootKernel();

        self::assertInstanceOf(SyncTransport::class, self::getContainer()->get('messenger.transport.jobs'));
    }
}
