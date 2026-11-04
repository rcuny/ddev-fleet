<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * What Caddy's journal said about certificates in a time window (the output of AcmeLogParser).
 */
final readonly class AcmeEvents
{
    /**
     * @param list<string> $rateLimitedNames hostnames Let's Encrypt refused (at most AcmeLogParser::MAX_NAMES)
     * @param list<string> $failedSolveNames hostnames whose challenge failed
     * @param list<string> $errors           the last distinct, shortened error lines, newest last
     */
    public function __construct(
        public int $rateLimited = 0,
        public ?\DateTimeImmutable $rateLimitedSince = null,
        public ?\DateTimeImmutable $rateLimitedLast = null,
        public ?\DateTimeImmutable $retryAfter = null,
        public array $rateLimitedNames = [],
        public int $failedSolves = 0,
        public ?\DateTimeImmutable $failedSolvesSince = null,
        public array $failedSolveNames = [],
        public int $obtained = 0,
        public array $errors = [],
    ) {
    }
}
