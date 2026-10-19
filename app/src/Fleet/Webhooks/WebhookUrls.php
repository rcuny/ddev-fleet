<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Webhook\WebhookSource;

/**
 * The address a sender is configured with: `https://<fleet domain>/hooks/<source>/<project>`. The domain is the host
 * setting; before it is set (a dev checkout) the page falls back to the address the admin is browsing.
 */
final readonly class WebhookUrls
{
    public function __construct(private HostSettingsRepository $settings)
    {
    }

    public function base(?string $fallback = null): ?string
    {
        $domain = $this->settings->get()?->domain();

        return null !== $domain && '' !== $domain ? 'https://'.$domain : $fallback;
    }

    public function url(WebhookSource $source, string $project, ?string $fallbackBase = null): string
    {
        return ($this->base($fallbackBase) ?? 'https://<fleet-domain>').'/hooks/'.$source->value.'/'.$project;
    }
}
