<?php

declare(strict_types=1);

namespace App\Fleet\System;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How Authelia can send mail, which decides whether its self-service password reset is switched on (D5). Nothing is
 * assumed: with no SMTP server configured the reset stays disabled and the notifier is the filesystem one (Authelia
 * writes what it would send to a file), so an unconfigured host exposes no reset flow that cannot deliver its link.
 *
 * An operator opts in with `FLEET_AUTHELIA_SMTP_ADDRESS` (`smtp://`, `submission://` or `submissions://` host and
 * optional port) and `FLEET_AUTHELIA_SMTP_SENDER` in fleet.env, and `FLEET_AUTHELIA_SMTP_USERNAME` when the server wants
 * a login. The SMTP password is never rendered: Authelia reads it from the file named by
 * `AUTHELIA_NOTIFIER_SMTP_PASSWORD_FILE` in its service environment (see docs/installation.md).
 */
final readonly class AutheliaNotifier
{
    private ?string $address;
    private ?string $sender;
    private ?string $username;

    public function __construct(
        #[Autowire('%env(default::FLEET_AUTHELIA_SMTP_ADDRESS)%')]
        ?string $smtpAddress = null,
        #[Autowire('%env(default::FLEET_AUTHELIA_SMTP_SENDER)%')]
        ?string $smtpSender = null,
        #[Autowire('%env(default::FLEET_AUTHELIA_SMTP_USERNAME)%')]
        ?string $smtpUsername = null,
    ) {
        $this->address = self::blankToNull($smtpAddress);
        $this->sender = self::blankToNull($smtpSender);
        $this->username = self::blankToNull($smtpUsername);
    }

    /**
     * Whether an SMTP server is configured, i.e. whether the self-service password reset is enabled.
     */
    public function isConfigured(): bool
    {
        return null !== $this->address;
    }

    /**
     * The validated SMTP settings for the template, or null when none are configured.
     *
     * @return array{address: string, sender: string, username: string|null}|null
     *
     * @throws \InvalidArgumentException when a value is not acceptable or the sender is missing
     */
    public function smtp(): ?array
    {
        if (null === $this->address) {
            return null;
        }
        if (null === $this->sender) {
            throw new \InvalidArgumentException('FLEET_AUTHELIA_SMTP_SENDER is required when FLEET_AUTHELIA_SMTP_ADDRESS is set.');
        }

        return [
            'address' => ConfigValue::smtpAddress($this->address),
            'sender' => ConfigValue::smtpSender($this->sender),
            'username' => null === $this->username ? null : ConfigValue::smtpUsername($this->username),
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
