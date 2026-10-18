<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;

final class MercureWiringTest extends KernelTestCase
{
    public function testDefaultHubUsesConfiguredUrls(): void
    {
        self::bootKernel();

        $hub = self::getContainer()->get('mercure.hub.default');
        self::assertInstanceOf(HubInterface::class, $hub);

        self::assertSame('/.well-known/mercure', $hub->getPublicUrl());
        self::assertSame($_SERVER['MERCURE_URL'], $hub->getUrl());
    }

    public function testNoJwtSecretIsCommittedToTheDefaultEnvFile(): void
    {
        $env = (string) file_get_contents(\dirname(__DIR__, 2).'/.env');

        self::assertMatchesRegularExpression('/^MERCURE_JWT_SECRET=$/m', $env);
        self::assertStringNotContainsString('ChangeThisMercure', $env);
    }
}
