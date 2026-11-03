<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Host\TlsMode;

/**
 * What a successful SetupFinisher::finish() did. Nothing here is a secret: the Finish page shows it.
 */
final readonly class FinishResult
{
    /**
     * @param list<string> $steps the side effects that ran, in order (users, authelia, gate, caddy, complete)
     */
    public function __construct(
        public \DateTimeImmutable $completedAt,
        public string $domain,
        public TlsMode $tlsMode,
        public array $steps,
    ) {
    }

    public function url(): string
    {
        return 'https://'.$this->domain.'/';
    }
}
