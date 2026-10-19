<?php

declare(strict_types=1);

namespace App\Fleet\Host;

/**
 * Validation of the notification e-mail settings (SMTP relay, sender and recipient). Nothing here sends mail.
 */
final class NotificationMailRule
{
    public const int MAX_PASSWORD_LENGTH = 256;

    private const string HOST = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    private function __construct()
    {
    }

    /**
     * @return array<'smtpHost'|'smtpPort'|'smtpUser'|'mailFrom'|'mailTo', string> field => message; empty when valid.
     *                                                                             Blank values are not checked (they mean "not set").
     */
    public static function violations(?string $smtpHost, ?int $smtpPort, ?string $smtpUser, ?string $mailFrom, ?string $mailTo): array
    {
        $violations = [];

        $smtpHost = trim((string) $smtpHost);
        if ('' !== $smtpHost && 1 !== preg_match(self::HOST, $smtpHost)) {
            $violations['smtpHost'] = 'Enter a host name such as smtp.example.com (no scheme, port or path).';
        }
        if (null !== $smtpPort && ($smtpPort < 1 || $smtpPort > 65535)) {
            $violations['smtpPort'] = 'The port must be between 1 and 65535.';
        }
        $smtpUser = trim((string) $smtpUser);
        if ('' !== $smtpUser && (!mb_check_encoding($smtpUser, 'UTF-8') || mb_strlen($smtpUser) > 254 || 1 === preg_match('/[\x00-\x1F\x7F]/', $smtpUser))) {
            $violations['smtpUser'] = 'The user name must be at most 254 characters without control characters.';
        }
        foreach (['mailFrom' => $mailFrom, 'mailTo' => $mailTo] as $field => $address) {
            $address = trim((string) $address);
            if ('' !== $address && !self::address($address)) {
                $violations[$field] = 'That is not a valid e-mail address.';
            }
        }

        return $violations;
    }

    /**
     * Null when acceptable, else the message. The password may contain spaces but no control characters.
     */
    public static function passwordViolation(#[\SensitiveParameter] string $password): ?string
    {
        if ('' === $password || \strlen($password) > self::MAX_PASSWORD_LENGTH || 1 === preg_match('/[\x00-\x1F\x7F]/', $password)) {
            return \sprintf('The password must be 1 to %d characters without control characters.', self::MAX_PASSWORD_LENGTH);
        }

        return null;
    }

    private static function address(string $address): bool
    {
        return \strlen($address) <= 254
            && 1 !== preg_match('/[\s\x00-\x1F\x7F]/', $address)
            && false !== filter_var($address, \FILTER_VALIDATE_EMAIL);
    }
}
