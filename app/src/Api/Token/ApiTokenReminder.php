<?php

declare(strict_types=1);

namespace App\Api\Token;

use App\Domain\ApiToken\ApiToken;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Fleet\Audit\AuditLog;
use Psr\Clock\ClockInterface;

/**
 * Expiry reminders (FLE-289): a token that expires within ApiTokenWatch::EXPIRING_DAYS gets one `api_token.expiring` audit
 * entry a day, so the owner sees it in the audit view and the CLI before an integration stops working. Never sends anything
 * and never changes a token; a second run inside the same day adds nothing.
 */
final readonly class ApiTokenReminder
{
    public const int REPEAT_HOURS = 20;

    public function __construct(private ApiTokenRepository $tokens, private AuditLog $audit, private ClockInterface $clock)
    {
    }

    /**
     * @return list<ApiToken> the expiring tokens (reminded now or earlier today)
     */
    public function remind(): array
    {
        $now = $this->clock->now();
        $expiring = ApiTokenWatch::expiring($this->tokens->all(), $now);
        $since = $now->modify('-'.self::REPEAT_HOURS.' hours');
        foreach ($expiring as $token) {
            $prefix = '#'.$token->id().' ';
            if ($this->audit->recordedSince(AuditLog::API_TOKEN_EXPIRING, $prefix, $since)) {
                continue;
            }
            $this->audit->record(
                AuditLog::CLI_ACTOR,
                AuditLog::API_TOKEN_EXPIRING,
                \sprintf('%s%s expires %s UTC', $prefix, $token->name(), $token->expiresAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')),
            );
        }

        return $expiring;
    }
}
