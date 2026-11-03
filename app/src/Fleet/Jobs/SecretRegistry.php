<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

/**
 * Where code that puts a secret value into a deploy (a rendered token, a written config) says so, so the job log hides
 * that value from then on. Registering is cheap and idempotent; JobExecutor clears the registry when a job ends.
 */
interface SecretRegistry
{
    public function register(#[\SensitiveParameter] string $value): void;

    public function clear(): void;
}
