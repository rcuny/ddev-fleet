<?php

declare(strict_types=1);

namespace App\Api\Terminal;

use App\Fleet\Jobs\LogMasker;
use App\Fleet\Jobs\RegisteredSecretsMasker;

/**
 * Masks the text stored as evidence of a terminal input (FLE-286): the registered secrets, and anything shaped like a token
 * (known vendor prefixes, `Bearer ...`, JWTs, long unbroken key-like strings), because the evidence table outlives the call
 * and is shown to administrators. The sha256 of the original stays in the row.
 */
final readonly class EvidenceMasker
{
    private const string PATTERN = '/(?:flt_svc_|ghp_|gho_|ghu_|ghs_|ghr_|github_pat_|glpat-|xox[abprs]-|sk-|AKIA|ASIA)[A-Za-z0-9_\-]{8,}'
        .'|Bearer\s+[A-Za-z0-9._~+\/=\-]{8,}'
        .'|eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]*'
        .'|(?<![A-Za-z0-9+\/=_\-])[A-Za-z0-9+\/=_\-]{40,}(?![A-Za-z0-9+\/=_\-])/';

    public function __construct(private LogMasker $masker)
    {
    }

    public function mask(string $text): string
    {
        return (string) preg_replace(self::PATTERN, RegisteredSecretsMasker::MASK, $this->masker->mask($text));
    }
}
