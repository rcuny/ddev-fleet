<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * A masker that can say how long its longest secret is. JobLog then holds back that many bytes (less one) of the
 * output until the next chunk arrives, so a secret split across two chunks is still masked. A masker that does not
 * implement this is applied to each chunk as it comes.
 */
interface TailAwareLogMasker extends LogMasker
{
    /**
     * @return int the length in bytes of the longest value this masker hides (0 when it hides nothing)
     */
    public function longestSecret(): int;
}
