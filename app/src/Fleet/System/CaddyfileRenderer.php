<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Security\TrustedProxyChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Renders the main Caddyfile (installed as root through `apply caddy`). Every value comes from validated host
 * settings or from FleetPaths; credentials are never rendered, only `{env.X}` / `{file.X}` placeholders that Caddy resolves from
 * its environment file and from the OVH credential files at run time.
 */
final readonly class CaddyfileRenderer
{
    public const string PHP_SOCKET = '/run/php/fleet.sock';
    /** Where `apply caddy` writes the OVH credentials, one file per value (root:caddy 0640); install/libexec/apply names the same directory. */
    public const string OVH_DIR = '/etc/caddy/ovh';
    public const string HUB = '127.0.0.1:3000';
    public const string AUTHELIA = '127.0.0.1:9091';
    /** ddev's shared router: plain HTTP on loopback, routing by Host (the instance sites proxy to it). */
    public const string DDEV_ROUTER_HOST = '127.0.0.1';
    public const string DDEV_ROUTER = self::DDEV_ROUTER_HOST.':8080';

    /** The identity headers the app trusts from Caddy (see RemoteUserAuthenticator). */
    public const array IDENTITY_FIELDS = ['User', 'Groups', 'Name', 'Email'];

    public function __construct(
        private Environment $twig,
        private FleetPaths $paths,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Every spelling under which a client could smuggle an identity header past the gate: FastCGI turns both `-` and
     * `_` into `_` (HTTP_REMOTE_USER), so `Remote_User` is as dangerous as `Remote-User`.
     *
     * @return list<string>
     */
    public static function strippedHeaders(): array
    {
        $names = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $names[] = 'Remote-'.$field;
            $names[] = 'Remote_'.$field;
        }

        return $names;
    }

    public function render(HostSettings $host): string
    {
        $domain = ConfigValue::domain($host->domain());

        return $this->twig->render('system/Caddyfile.twig', [
            'domain' => $domain,
            'email' => ConfigValue::acmeEmail($host->acmeEmail()),
            'ovh' => TlsMode::OvhDns === $host->tlsMode(),
            'ovh_dir' => self::OVH_DIR,
            'stripped_headers' => self::strippedHeaders(),
            'snippet_dir' => ConfigValue::path('The Caddy snippet directory', $this->paths->caddySnippetDir()),
            'gate_import' => ConfigValue::path('The Caddy snippet directory', $this->paths->caddySnippetDir()).'/gate.conf',
            'root' => ConfigValue::path('The project directory', $this->projectDir).'/public',
            'php_socket' => self::PHP_SOCKET,
            'hub' => self::HUB,
            'authelia' => self::AUTHELIA,
            // The name is rendered from the constant the app reads, so the two cannot drift apart.
            'proxy_param' => TrustedProxyChecker::PROXY_PARAM,
        ]);
    }
}
