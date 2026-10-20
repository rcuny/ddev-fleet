<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the live entrypoints from Traefik's API on the loopback (`GET http://127.0.0.1:10999/api/entrypoints`).
 */
final readonly class TraefikApiEntrypoints implements LiveEntrypoints
{
    public const string URL = 'http://127.0.0.1:10999/api/entrypoints';
    private const float TIMEOUT = 5.0;

    public function __construct(private HttpClientInterface $client)
    {
    }

    public function names(): ?array
    {
        try {
            $data = $this->client->request('GET', self::URL, ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 2])->toArray();
        } catch (ExceptionInterface) {
            return null;
        }
        if ([] === $data || !array_is_list($data)) {
            return null;
        }
        $names = [];
        foreach ($data as $entry) {
            if (!\is_array($entry) || !\is_string($entry['name'] ?? null)) {
                return null;
            }
            $names[] = $entry['name'];
        }

        return $names;
    }
}
