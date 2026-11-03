<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * The operation is valid but not allowed in the current state; the message states the reason, for example
 * "2 instance(s) exist: destroy them first.".
 */
final class Refused extends \DomainException
{
    /**
     * @param string|null $reason a machine-readable kind for callers that must tell refusals apart (the JSON endpoints
     *                            send it as `reason`); null for the common case
     */
    public function __construct(string $message = '', int $code = 0, public readonly ?string $reason = null)
    {
        parent::__construct($message, $code);
    }
}
