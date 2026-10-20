<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Fleet\Secrets\SecretStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Renders the staged `env` file of `apply caddy`: the proxy secret the Caddyfile passes to the app, and in DNS mode the
 * OVH credentials read from the secret store. The helper splits it: `/etc/caddy/fleet.env` (Caddy's environment) keeps
 * the proxy secret only, each OVH credential goes to its own file under `/etc/caddy/ovh/` that the Caddyfile reads through
 * `{file.*}` placeholders. Plain KEY=VALUE lines, unquoted: every value is validated against a charset that is safe for systemd's EnvironmentFile and for the root
 * helper's allowlist parser.
 */
final readonly class CaddyEnvRenderer
{
    /** Secret store names of the OVH credentials, in the order they are written. The endpoint is no secret: the Caddyfile carries it. */
    private const array OVH = [
        'OVH_APPLICATION_KEY' => 'ovh_application_key',
        'OVH_APPLICATION_SECRET' => 'ovh_application_secret',
        'OVH_CONSUMER_KEY' => 'ovh_consumer_key',
    ];

    public function __construct(
        private SecretStore $secrets,
        #[Autowire('%fleet.proxy_secret%')]
        #[\SensitiveParameter]
        private string $proxySecret,
    ) {
    }

    /**
     * @throws \RuntimeException         when an OVH value is missing in DNS mode
     * @throws \InvalidArgumentException when a value has an unsafe shape
     */
    public function render(HostSettings $host): string
    {
        $lines = ['FLEET_PROXY_SECRET='.ConfigValue::proxySecret($this->proxySecret)];

        if (TlsMode::OvhDns === $host->tlsMode()) {
            foreach (self::OVH as $variable => $name) {
                $value = $this->secrets->getFleetWide($name);
                if (null === $value) {
                    throw new \RuntimeException(\sprintf('The secret "%s" is not set; DNS mode needs all three OVH credentials.', $name));
                }
                $lines[] = $variable.'='.ConfigValue::ovhCredential($name, $value);
            }
        }

        return implode("\n", $lines)."\n";
    }
}
