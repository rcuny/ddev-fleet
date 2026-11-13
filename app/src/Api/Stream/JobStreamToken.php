<?php

declare(strict_types=1);

namespace App\Api\Stream;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;

/**
 * Mints the Mercure subscriber token behind `POST /api/v1/jobs/{id}/stream-token` (FLE-287): one grant, `subscribe` on
 * the single private topic `/jobs/<id>`, a short expiry and no publish grant. Mercure is a wake-up (id, status, size);
 * the text still comes from the log endpoint. The expiry is capped at the calling API token's own expiry. A hub that is not configured answers null and the client keeps polling.
 */
final readonly class JobStreamToken
{
    /** Seconds a token lives: long enough to open the stream, short enough that a leak is not worth keeping. */
    public const int LIFETIME = 600;

    public function __construct(private HubInterface $hub)
    {
    }

    public static function topic(int $jobId): string
    {
        return '/jobs/'.$jobId;
    }

    /**
     * @return array{token: string, topic: string, hub_url: string, expires_at: string}|null
     */
    public function mint(int $jobId, Request $request, ?\DateTimeImmutable $notAfter = null): ?array
    {
        $factory = $this->hub->getFactory();
        if (null === $factory) {
            return null;
        }
        $expires = new \DateTimeImmutable('+'.self::LIFETIME.' seconds');
        // Never outlive the API token that asked for it (FLE-329).
        if (null !== $notAfter && $notAfter < $expires) {
            $expires = $notAfter;
        }
        try {
            $token = $factory->create(
                [new Grant([Grant::ACTION_SUBSCRIBE], [self::topic($jobId)])],
                ['exp' => $expires],
            );
        } catch (\Throwable) {
            return null;
        }
        $url = $this->hub->getPublicUrl();
        if (str_starts_with($url, '/')) {
            $url = $request->getSchemeAndHttpHost().$url;
        }

        return [
            'token' => $token,
            'topic' => self::topic($jobId),
            'hub_url' => $url,
            'expires_at' => $expires->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
