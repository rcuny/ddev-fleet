<?php

declare(strict_types=1);

namespace App\Fleet\System;

/**
 * Validation of every value that is rendered into a root-installed config (Caddyfile, Authelia configuration, Caddy
 * environment file). The system templates run with autoescape off, so this is the only defence against a newline,
 * brace, quote or comment character changing the structure of those files: each method accepts a narrow charset and
 * throws otherwise. Messages never contain a credential value.
 */
final class ConfigValue
{
    /** The OVH API endpoints that serve DNS zones (the caddy-dns/ovh module accepts more; these are the ones needed here). */
    public const array OVH_ENDPOINTS = ['ovh-eu', 'ovh-ca', 'ovh-us'];

    private const string DOMAIN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D';
    private const string EMAIL = '/^(?=.{3,254}$)[A-Za-z0-9._%+-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D';
    private const string CREDENTIAL = '/^[A-Za-z0-9._-]{8,128}$/D';
    private const string PROXY_SECRET = '/^[A-Za-z0-9._~+\/=-]{32,256}$/D';
    private const string PATH = '/^\/[A-Za-z0-9._+\/-]*$/D';

    private function __construct()
    {
    }

    /**
     * A lowercase, dotted host name (at least two labels, the last one starting with a letter, so an IP address is
     * refused). No wildcard, port, scheme or path.
     */
    public static function domain(string $domain): string
    {
        if (1 !== preg_match(self::DOMAIN, $domain) || str_contains($domain, '..')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid lowercase fully qualified domain name.', self::printable($domain)));
        }

        return $domain;
    }

    /**
     * @return string|null null for null or ''
     */
    public static function acmeEmail(?string $email): ?string
    {
        if (null === $email || '' === $email) {
            return null;
        }
        if (1 !== preg_match(self::EMAIL, $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid ACME contact email address.', self::printable($email)));
        }

        return $email;
    }

    public static function ovhEndpoint(string $endpoint): string
    {
        if (!\in_array($endpoint, self::OVH_ENDPOINTS, true)) {
            throw new \InvalidArgumentException(\sprintf('The OVH endpoint must be one of: %s.', implode(', ', self::OVH_ENDPOINTS)));
        }

        return $endpoint;
    }

    /**
     * An OVH application key, application secret or consumer key (8 to 128 of letters, digits, dot, underscore, dash).
     *
     * @param string $field the secret's name, for the message
     */
    public static function ovhCredential(string $field, #[\SensitiveParameter] string $value): string
    {
        if (1 !== preg_match(self::CREDENTIAL, $value)) {
            throw new \InvalidArgumentException(\sprintf('The OVH value "%s" must be 8 to 128 characters of letters, digits, ".", "_" or "-".', $field));
        }

        return $value;
    }

    /**
     * The secret Caddy hands to the app as a FastCGI parameter (at least 32 characters, no whitespace or quoting
     * characters, so it is safe unquoted in a systemd environment file).
     */
    public static function proxySecret(#[\SensitiveParameter] string $secret): string
    {
        if (1 !== preg_match(self::PROXY_SECRET, $secret)) {
            throw new \InvalidArgumentException('FLEET_PROXY_SECRET must be 32 to 256 characters of letters, digits and ".", "_", "~", "+", "/", "=", "-".');
        }

        return $secret;
    }

    /**
     * An absolute path that is safe unquoted in a Caddyfile or a YAML scalar (no whitespace, quotes, braces, "#" or
     * control characters, no "." or ".." segment).
     */
    public static function path(string $what, string $path): string
    {
        if (1 !== preg_match(self::PATH, $path)) {
            throw new \InvalidArgumentException(\sprintf('%s must be an absolute path of letters, digits and "._+-/".', $what));
        }
        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException(\sprintf('%s must be a normalised path.', $what));
            }
        }

        return $path;
    }

    private static function printable(string $value): string
    {
        return addcslashes(substr($value, 0, 80), "\0..\37\177..\377");
    }
}
