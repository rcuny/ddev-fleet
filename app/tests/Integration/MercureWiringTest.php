<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;

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

    /** FLE-330: the subscriber key cannot sign a publisher token, and the publisher key cannot sign a subscriber one. */
    public function testSubscriberAndPublisherTokensAreSignedWithDifferentKeys(): void
    {
        self::bootKernel();
        $hub = self::getContainer()->get('mercure.hub.default');
        self::assertInstanceOf(HubInterface::class, $hub);

        $subscriber = $hub->getFactory()?->create([new Grant([Grant::ACTION_SUBSCRIBE], ['/jobs/1'])]);
        self::assertIsString($subscriber);
        $publisher = $hub->getProvider()->getJwt();

        $sign = static function (string $jwt, string $secret): bool {
            [$h, $p, $s] = explode('.', $jwt);

            return hash_equals(rtrim(strtr(base64_encode(hash_hmac('sha256', $h.'.'.$p, $secret, true)), '+/', '-_'), '='), $s);
        };
        self::assertNotSame($_SERVER['MERCURE_JWT_SECRET'], $_SERVER['MERCURE_SUBSCRIBER_JWT_SECRET']);
        self::assertTrue($sign($subscriber, (string) $_SERVER['MERCURE_SUBSCRIBER_JWT_SECRET']));
        self::assertFalse($sign($subscriber, (string) $_SERVER['MERCURE_JWT_SECRET']));
        self::assertTrue($sign($publisher, (string) $_SERVER['MERCURE_JWT_SECRET']));
        self::assertFalse($sign($publisher, (string) $_SERVER['MERCURE_SUBSCRIBER_JWT_SECRET']));
    }

    public function testNoJwtSecretIsCommittedToTheDefaultEnvFile(): void
    {
        $env = (string) file_get_contents(\dirname(__DIR__, 2).'/.env');

        self::assertMatchesRegularExpression('/^MERCURE_JWT_SECRET=$/m', $env);
        self::assertStringNotContainsString('ChangeThisMercure', $env);
    }
}
