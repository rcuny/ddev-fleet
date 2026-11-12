<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/** Test hub (APP_ENV=test only): remembers every Update published to it, so a functional test can read what the app announced. */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private array $updates = [];

    private ?TokenFactoryInterface $factory = null;

    public function getUrl(): string
    {
        return 'http://hub.test/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return '/.well-known/mercure';
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::Legacy;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new \LogicException('Not used in tests.');
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->factory;
    }

    /** A test that needs subscriber tokens (the stream-token endpoint) switches a real factory on; reset() switches it off. */
    public function useFactory(?TokenFactoryInterface $factory): void
    {
        $this->factory = $factory;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'id-'.\count($this->updates);
    }

    /** @return list<Update> */
    public function updates(): array
    {
        return $this->updates;
    }

    public function reset(): void
    {
        $this->updates = [];
        $this->factory = null;
    }
}
