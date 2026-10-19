<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;

/**
 * Applier fake: remembers every target and can be told to fail.
 */
final class RecordingApplier implements Applier
{
    /** @var list<ApplyTarget> */
    public array $applied = [];

    public function __construct(public ?\Throwable $fails = null)
    {
    }

    public function apply(ApplyTarget $target): void
    {
        $this->applied[] = $target;
        if (null !== $this->fails) {
            throw $this->fails;
        }
    }
}
