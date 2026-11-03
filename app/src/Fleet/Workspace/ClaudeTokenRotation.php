<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Host\ClaudeTokenRule;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretService;
use App\Fleet\Secrets\SecretStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces the fleet-wide Claude token. The storage is the host settings page's (SecretStore, fleet scope); this adds the
 * shape check, the audit entry and the list of instances that will pick the token up. Handing it to running instances is
 * ClaudeTokenApplier's job, on request; a new deploy reads it by itself. No message, log line or return value carries the token.
 */
final readonly class ClaudeTokenRotation
{
    public function __construct(
        private SecretStore $secrets,
        private AuditLog $audit,
        private InstanceRepository $instances,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the token does not have the right shape (the message never contains it)
     * @throws SecretConflict            when a concurrent writer created the secret first
     */
    public function rotate(#[\SensitiveParameter] string $token, string $by): TokenRotation
    {
        $token = trim($token);
        if ('' === $token) {
            throw new \InvalidArgumentException('Paste the token to store it.');
        }
        if (!ClaudeTokenRule::isValid($token)) {
            throw new \InvalidArgumentException(ClaudeTokenRule::MESSAGE);
        }

        $this->secrets->putFleetWide(HostSecrets::CLAUDE_TOKEN, $token, $by);
        $this->audit->record($by, AuditLog::CLAUDE_TOKEN_ROTATE, SecretService::subject(null, HostSecrets::CLAUDE_TOKEN));

        $running = [];
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running === $instance->state()) {
                $running[] = $instance->id();
            }
        }
        $this->logger->info('The Claude token was rotated.', ['by' => $by, 'running_instances' => \count($running)]);

        return new TokenRotation($this->clock->now(), $running);
    }

    public function lastRotated(): ?\DateTimeImmutable
    {
        return $this->secrets->fleetWideUpdatedAt(HostSecrets::CLAUDE_TOKEN);
    }
}
