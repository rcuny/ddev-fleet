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
    private const string SMTP_ADDRESS = '/^(?:smtp|submission|submissions):\/\/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::[0-9]{1,5})?$/D';
    private const string SMTP_USERNAME = '/^[A-Za-z0-9._@+-]{1,128}$/D';
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

    /**
     * The address of Authelia's SMTP notifier: `smtp://`, `submission://` or `submissions://`, a lowercase host name
     * and an optional port.
     */
    public static function smtpAddress(string $address): string
    {
        if (1 !== preg_match(self::SMTP_ADDRESS, $address)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid SMTP address (smtp://host, submission://host:587 or submissions://host:465).', self::printable($address)));
        }

        return $address;
    }

    /**
     * The sender of Authelia's mail: a plain address.
     */
    public static function smtpSender(string $sender): string
    {
        if (1 !== preg_match(self::EMAIL, $sender) || false === filter_var($sender, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('The SMTP sender must be an email address.');
        }

        return $sender;
    }

    public static function smtpUsername(string $username): string
    {
        if (1 !== preg_match(self::SMTP_USERNAME, $username)) {
            throw new \InvalidArgumentException('The SMTP username must be 1 to 128 characters of letters, digits and "._@+-".');
        }

        return $username;
    }

    /**
     * A username as a rule subject (`user:<name>`): the same shape User accepts.
     */
    public static function username(string $username): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $username)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid username.', self::printable($username)));
        }

        return $username;
    }

    /**
     * An Authelia group as a rule subject (`group:<name>`): the shape AdminGroup accepts.
     */
    public static function group(string $group): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $group)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid group name.', self::printable($group)));
        }

        return $group;
    }

    /**
     * A project name as it appears in an instance host name (`<project>--<label>.<domain>`).
     */
    public static function projectName(string $name): string
    {
        if (1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D', $name) || str_contains($name, '--')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid project name.', self::printable($name)));
        }

        return $name;
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
